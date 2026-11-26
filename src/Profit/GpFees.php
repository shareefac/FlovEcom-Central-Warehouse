<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Expenses\ExpenseViews;
use CW\Reports\ReportRefresh;

/**
 * Global Payments' card fees in CW's profit (CW-1174, 0084; docs/decisions.md GPF1-GPF14; the owner, 26 Nov 2026: "Yes, do that, estimate
 * is fine").
 *
 *  - Which orders count (GPF1): a paid order with gateway `globalpay` (the shops' Card / Apple Pay / Google Pay) of a shop an account takes
 *    (profit.gp_shops_<account>), paid on a UK day from the account's first day to its last (profit.gp_from_ / gp_until_<account>), with a
 *    base above 0. The base is what the card was charged: sale_order.grand_inc. PayPal (pp, pphf) and office orders never count here.
 *  - The estimate (GPF3): base x the account's rate (profit.gp_rate_<account>), rounded half up per order; a day is the sum of its orders.
 *    A refund changes nothing (Global Payments charges a refund too and never gives the sale's fee back: that cost is inside the rate), so a
 *    fee is never lowered or made negative by one.
 *  - The actual (GPF5): an invoice entered for an account and a CW calendar month (GpInvoices) replaces the month's estimates: its net charge
 *    shared over the month's card orders by base (ProfitMath::shareOut, ties by day, shop, order). A month with no card order in CW puts it on
 *    gp_other of the account's main shop on the month's last day (GPF7).
 *  - Every penny once (GPF4): a gp_fee_order row and the profit_day figures it counts in (gp_fee, gp_fee_est on the order's paid day;
 *    gp_other) are only ever written together under the day's guard (ProfitDays::guard): by the snapshot (onSnapshot(), inside
 *    OrderSnapshots::apply, which holds the order's day guards), by syncDay() (the job, the backfill, a setting changed) and by
 *    settleMonth() (an invoice's spread, or its month back to estimates). ProfitDays::compute reads dayFigures(), so a rebuild of a day
 *    gives the same figures.
 *  - The job (bin/gp_fees.php, GPF8): the months queued (gp_due: an order of an applied month changed), a setting changed (its days gone over
 *    again), estimates at an old rate worked out again, invoices due spread; the nightly also goes over the last RECHECK_DAYS days.
 */
final class GpFees
{
    /** The Global Payments accounts CW counts (GPF2): Vape and Go Online (also takes Vape Big's card payments) and Electrofag Online. */
    public const ACCOUNTS = ['vpg_online', 'ef_online'];
    public const GATEWAY = 'globalpay';
    /** The nightly goes over this many days back (an order reached CW late, a setting changed). */
    public const RECHECK_DAYS = 62;
    /** How long the job waits for another spread of an invoice before leaving it for its next run. */
    public const SETTLE_WAIT_SEC = 30;
    /** How many times a spread is planned again when an order of the month changed while it worked. */
    public const SETTLE_TRIES = 3;
    /** The accounts as staff know them (the alert's words). */
    public const ACCOUNT_NAMES = ['vpg_online' => 'Vape and Go Online', 'ef_online' => 'Electrofag Online'];
    /** The health alert says the job has not run well for this many minutes (it runs every 10). */
    public const JOB_LATE_MIN = 60;
    /** An account switched on less than this many minutes ago has its first run to come: not said yet. */
    public const SWITCH_GRACE_MIN = 60;
    /** A spread's day found its orders changed since the plan (rolled back; planned again). */
    private const CHANGED = 'gp_spread_changed';
    /** A guard of a day was held elsewhere (rolled back at once; left for the next run: the Codex review of 6043729, H3). */
    private const BUSY = 'gp_guard_busy';
    /**
     * How long a day pass, a spread's day or a charge-on-no-order move waits for each day guard, and how long it may have held them before it
     * writes (seconds): a snapshot of any shop waits at most about this for one of them, well inside its own 3 s (OrderSnapshots::
     * QUEUE_LOCK_WAIT_SEC). Busy: rolled back, the work left for the next run (H3).
     */
    public const GUARD_WAIT_SEC = 1;
    public const HOLD_SEC = 2.0;
    /** The job's work deadline (seconds; cron's hard stop is 300 and the installer waits 360 for running jobs: M14); what is left waits for the next run. */
    public const WORK_SEC = 230.0;
    /** Tests only: called by a spread right after it planned (H1: a late order and the job's queue meanwhile). */
    public static ?\Closure $afterPlan = null;
    private const ROW_FIELDS = ['channel_id', 'order_ref', 'account', 'day', 'base_pence', 'state', 'fee_pence', 'rate_e4', 'invoice_id', 'spread_version', 'updated_at'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cfg = null;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param float $workSec the job's work deadline (run()), from its start
     */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, private readonly float $workSec = self::WORK_SEC)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ---- the settings ------------------------------------------------------------------------------------------------------------------

    /**
     * Each account's settings as the code uses them (GPF2): first and last day, its shops (channel ids, the main shop first), its rate in
     * millionths, its merchant number, whether it is switched on (a first day of the 1st of a month, a rate, a shop) and the signature of the
     * settings that decide which orders count (a job seeing another goes over the account's days again).
     *
     * @return array<string, array{account: string, from: ?string, until: ?string, shops: list<int>, codes: list<string>, main: ?int, rate: ?int, merchant: string, on: bool, shop_from: array<int, ?string>, sig: string}>
     */
    public static function readConfig(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE setting_key LIKE 'profit.gp\\_%'") as $r) {
            $v[(string) $r['setting_key']] = json_decode((string) $r['v'], true);
        }
        $ids = [];
        foreach ($db->all('SELECT id, code FROM channel') as $c) {
            $ids[(string) $c['code']] = (int) $c['id'];
        }
        $out = [];
        foreach (self::ACCOUNTS as $a) {
            $from = self::dayOrNull($v["profit.gp_from_{$a}"] ?? null);
            $until = self::dayOrNull($v["profit.gp_until_{$a}"] ?? null);
            $codes = array_values(array_filter(explode(',', is_string($v["profit.gp_shops_{$a}"] ?? null) ? $v["profit.gp_shops_{$a}"] : ''),
                static fn (string $c): bool => $c !== ''));
            $shops = [];
            foreach ($codes as $c) {
                if (isset($ids[$c]) && !in_array($ids[$c], $shops, true)) {
                    $shops[] = $ids[$c];
                }
            }
            // each shop's own first day (H2: Vape Big's card fees count from 1 Dec, its real profit start): before it a card order of the shop has
            // no estimate, and an invoice's share of it goes on the account's main shop (on no order); not set: the account's first day
            $shopFrom = [];
            foreach ($codes as $code) {
                if (isset($ids[$code])) {
                    $shopFrom[$ids[$code]] = self::dayOrNull($v["profit.gp_shop_from_{$code}"] ?? null);
                }
            }
            $rawRate = $v["profit.gp_rate_{$a}"] ?? null;
            $rate = is_string($rawRate) ? ProfitMath::rateE4($rawRate) : (is_int($rawRate) ? ProfitMath::rateE4((string) $rawRate) : null);
            $on = $from !== null && str_ends_with($from, '-01') && $rate !== null && $shops !== [];
            $out[$a] = ['account' => $a, 'from' => $from, 'until' => $until, 'shops' => $shops, 'codes' => $codes, 'main' => $shops[0] ?? null, 'rate' => $rate,
                'merchant' => is_string($v["profit.gp_merchant_{$a}"] ?? null) ? $v["profit.gp_merchant_{$a}"] : '', 'on' => $on, 'shop_from' => $shopFrom,
                'sig' => substr(hash('sha256', (string) json_encode([$on ? $from : null, $until, $shops, $shopFrom])), 0, 16)];
        }
        return $out;
    }

    /** The settings read once per instance (a request, a job's account pass: fresh() reads them again). */
    public function config(): array
    {
        return $this->cfg ??= self::readConfig($this->db);
    }

    public function fresh(): void
    {
        $this->cfg = null;
    }

    /**
     * The account whose card payments an order went through and that counts its fee (GPF1), or null: it counts no card fee. A re-send (a
     * replacement: parent_ref) never counts one (M13); a shop's card order before the shop's own first day neither (H2: preStartAccount()).
     */
    public static function accountOf(array $cfg, int $channelId, ?string $gateway, ?string $day, ?string $parent = null): ?string
    {
        $a = self::takenBy($cfg, $channelId, $gateway, $day, $parent);
        return $a !== null && (($cfg[$a]['shop_from'][$channelId] ?? null) === null || $day >= $cfg[$a]['shop_from'][$channelId]) ? $a : null;
    }

    /**
     * The account a card order of a shop BEFORE the shop's own first day went through (H2), or null: no estimate, but an invoice of its month
     * is shared over it too and its share goes on the account's main shop (on no order).
     */
    public static function preStartAccount(array $cfg, int $channelId, ?string $gateway, ?string $day, ?string $parent = null): ?string
    {
        $a = self::takenBy($cfg, $channelId, $gateway, $day, $parent);
        return $a !== null && ($cfg[$a]['shop_from'][$channelId] ?? null) !== null && $day < $cfg[$a]['shop_from'][$channelId] ? $a : null;
    }

    /** The account switched on that took a card order (globalpay, paid in its days, of one of its shops, no re-send), or null. */
    private static function takenBy(array $cfg, int $channelId, ?string $gateway, ?string $day, ?string $parent): ?string
    {
        if ($gateway !== self::GATEWAY || $day === null || $parent !== null) {
            return null;
        }
        foreach ($cfg as $a => $c) {
            if ($c['on'] && in_array($channelId, $c['shops'], true) && $day >= $c['from'] && ($c['until'] === null || $day <= $c['until'])) {
                return (string) $a;
            }
        }
        return null;
    }

    /** The estimated fee of a base at a rate in millionths, rounded half up (GPF3): £29.99 at 1.01% is 30p; £40,000.00 is £404.00. */
    public static function estimate(int $base, int $rateE4): int
    {
        return $base <= 0 ? 0 : ProfitMath::mulDivRound($base, $rateE4, 1_000_000);
    }

    // ---- one order ---------------------------------------------------------------------------------------------------------------------

    /**
     * The row an order has now (GPF1, GPF3), from its sale_order row (null: none) and its stored row (null: none), and the months whose
     * invoice must be spread again because of it. Pure. $live says whether an account's month has a live invoice. $keepActual false: an
     * actual row goes back to an estimate (its invoice deleted, its account switched off).
     *
     * @param array<string, mixed>|null $order
     * @param array<string, mixed>|null $old
     * @param \Closure(string, string): bool $live
     * @return array{0: ?array<string, mixed>, 1: list<array{0: string, 1: string}>} the row now (the stored one when nothing changes; null: none), the months
     */
    public static function plan(array $cfg, int $channelId, string $ref, ?array $order, ?array $old, string $now, \Closure $live, bool $keepActual = true): array
    {
        $acct = $order === null ? null : self::accountOf($cfg, $channelId, (string) ($order['gateway'] ?? ''), $order['day'] === null ? null : (string) $order['day'],
            ($order['parent_ref'] ?? null) === null ? null : (string) $order['parent_ref']);
        $base = $order === null ? 0 : (int) $order['grand_inc'];
        if ($acct !== null && $base <= 0) {
            $acct = null;
        }
        $oldOn = $old !== null && $old['state'] !== 'off';
        $oldMonth = $old === null ? null : substr((string) $old['day'], 0, 7);
        if ($acct === null) {
            if (!$oldOn) {
                return [$old, []];
            }
            $new = ['state' => 'off', 'fee_pence' => 0, 'rate_e4' => null, 'invoice_id' => null, 'spread_version' => null, 'updated_at' => $now] + $old;
            return [$new, $live((string) $old['account'], (string) $oldMonth) ? [[(string) $old['account'], (string) $oldMonth]] : []];
        }
        $day = (string) $order['day'];
        $month = substr($day, 0, 7);
        if ($keepActual && $oldOn && $old['state'] === 'actual' && (string) $old['account'] === $acct && $oldMonth === $month && (int) $old['base_pence'] === $base) {
            // its share of the month's invoice stays (the month's total is the same); a day moved inside the month moves it along and the month is
            // spread again, so its rounding penny follows the day order (L15)
            if ((string) $old['day'] === $day) {
                return [$old, []];
            }
            return [['day' => $day, 'updated_at' => $now] + $old, $live($acct, $month) ? [[$acct, $month]] : []];
        }
        $rate = (int) $cfg[$acct]['rate'];
        $new = ['channel_id' => $channelId, 'order_ref' => $old === null ? $ref : (string) $old['order_ref'], 'account' => $acct, 'day' => $day, 'base_pence' => $base,
            'state' => 'estimate', 'fee_pence' => self::estimate($base, $rate), 'rate_e4' => $rate, 'invoice_id' => null, 'spread_version' => null, 'updated_at' => $now];
        if ($old !== null && self::same($old, $new)) {
            return [$old, []];
        }
        $queue = [];
        if ($live($acct, $month)) {
            $queue[] = [$acct, $month];
        }
        if ($oldOn && ((string) $old['account'] !== $acct || $oldMonth !== $month) && $live((string) $old['account'], (string) $oldMonth)) {
            $queue[] = [(string) $old['account'], (string) $oldMonth];
        }
        return [$new, $queue];
    }

    /** What a row counts (GPF4): its fee, and the part of it still estimated. @return array{fee: int, est: int} */
    public static function share(?array $row): array
    {
        if ($row === null || $row['state'] === 'off') {
            return ['fee' => 0, 'est' => 0];
        }
        $fee = (int) $row['fee_pence'];
        return ['fee' => $fee, 'est' => $row['state'] === 'estimate' ? $fee : 0];
    }

    /** An order's stored row (inside the caller's transaction, under the order's day guard), or null. */
    public static function stored(Db $db, int $channelId, string $ref): ?array
    {
        return $db->one('SELECT * FROM gp_fee_order WHERE channel_id = ? AND order_ref = ?', [$channelId, $ref]);
    }

    /** What an order counts now (ProfitDays::orderShare passes it when the order moves with no change of its own). @return array{fee: int, est: int} */
    public static function orderFee(Db $db, int $channelId, string $ref): array
    {
        return self::share(self::stored($db, $channelId, $ref));
    }

    /**
     * The snapshot's part (OrderSnapshots::apply, inside its transaction, holding the order's day guards, after its sale_order row is written):
     * the order's row decided again and written; what it counted before and counts now (ProfitDays::orderShare counts them on the order's
     * day before and now). An order of a month whose invoice is applied queues the month (gp_due), in the same transaction (GPF8). An order
     * that was no card order and is none now reads nothing of the card fees (M8); the settings are read again in each snapshot (M7: a batch
     * that started before an account was switched off never counts a fee after it). A shop's card order before the shop's own first day
     * queues an applied month when it changed (its share of the invoice is the main shop's: H2).
     *
     * @param array<string, mixed>|null $before the sale_order row as it was before this snapshot (null: none)
     * @param array<string, mixed>|null $order the sale_order row as written now (null: none)
     * @return array{0: array{fee: int, est: int}, 1: array{fee: int, est: int}}
     */
    public function onSnapshot(Db $db, int $channelId, string $ref, ?array $before, ?array $order, string $now): array
    {
        $card = static fn (?array $o): bool => $o !== null && ($o['gateway'] ?? '') === self::GATEWAY;
        if (!$card($before) && !$card($order)) {
            return [['fee' => 0, 'est' => 0], ['fee' => 0, 'est' => 0]]; // no card order before or now: its row, if any, is off (GP1)
        }
        $cfg = self::readConfig($db);
        $old = self::stored($db, $channelId, $ref);
        $live = self::liveReader($db);
        [$new, $queue] = self::plan($cfg, $channelId, $ref, $order, $old, $now, $live);
        $key = static fn (?array $o): string => $o === null ? '-' : implode('|', [(string) ($o['day'] ?? ''), (string) ($o['grand_inc'] ?? ''), (string) ($o['gateway'] ?? ''),
            (string) ($o['parent_ref'] ?? '')]);
        if ($key($before) !== $key($order)) {
            foreach ([$before, $order] as $o) {
                $a = $o === null || $o['day'] === null ? null : self::preStartAccount($cfg, $channelId, (string) ($o['gateway'] ?? ''), (string) $o['day'],
                    ($o['parent_ref'] ?? null) === null ? null : (string) $o['parent_ref']);
                if ($a !== null && $live($a, substr((string) $o['day'], 0, 7))) {
                    $queue[] = [$a, substr((string) $o['day'], 0, 7)];
                }
            }
        }
        if ($new !== null && $new !== $old) {
            self::writeRows($db, [$new]);
        }
        self::queue($db, $queue, $now);
        return [self::share($old), self::share($new)];
    }

    // ---- the profit days ----------------------------------------------------------------------------------------------------------------

    /**
     * A store's day of card fee figures from scratch (ProfitDays::compute): each paid order's fee and its estimated part (by lower-case ref),
     * the invoices' charges on no order dated that day, and whether any counts on the day itself.
     *
     * @return array{orders: array<string, array{fee: int, est: int}>, other: int, rows: int}
     */
    public static function dayFigures(Db $db, int $channelId, string $day): array
    {
        $orders = [];
        foreach ($db->all("SELECT o.order_ref, g.fee_pence, g.state FROM sale_order o JOIN gp_fee_order g ON g.channel_id = o.channel_id AND g.order_ref = o.order_ref "
            . "AND g.state <> 'off' WHERE o.day = ? AND o.channel_id = ?", [$day, $channelId]) as $r) {
            $orders[strtolower((string) $r['order_ref'])] = ['fee' => (int) $r['fee_pence'], 'est' => $r['state'] === 'estimate' ? (int) $r['fee_pence'] : 0];
        }
        $r = $db->one('SELECT COALESCE(SUM(other_pence), 0) AS p, COUNT(*) AS n FROM gp_invoice WHERE other_channel = ? AND other_day = ? AND other_pence <> 0',
            [$channelId, $day]) ?? [];
        return ['orders' => $orders, 'other' => (int) ($r['p'] ?? 0), 'rows' => (int) ($r['n'] ?? 0)];
    }

    /** Whether an invoice's charge on no order counts on this store's day (a day row with it is kept: DeliveryBills::tidy). */
    public static function anyOnDay(Db $db, int $channelId, string $day): bool
    {
        return $db->value('SELECT 1 FROM gp_invoice WHERE other_channel = ? AND other_day = ? AND other_pence <> 0 LIMIT 1', [$channelId, $day]) !== null;
    }

    // ---- a day of an account (the job, the backfill, a setting changed) --------------------------------------------------------------------

    /**
     * Every order of the account's shops paid on $day decided again (plan()) and written with the day's figures, in ONE transaction under the
     * guards of the day of every shop involved (GPF4): the backfill, the job after a setting changed or a rate changed, the nightly. A row of
     * the account on the day whose order is not paid any more is switched off. $keepActual false: the actual rows go back to estimates (an
     * invoice deleted or its account switched off: settleMonth()). A guard held elsewhere: rolled back at once, `busy` (H3: the next run).
     *
     * @return array{orders: int, written: int, before: int, after: int, busy: bool}
     */
    public function syncDay(string $acct, string $day, bool $keepActual = true): array
    {
        $now = Clock::db(($this->clock)());
        $cfg = $this->config();
        try {
            return $this->bounded(static function (Db $db, float $t0) use ($cfg, $acct, $day, $keepActual, $now): array {
                $chs = $cfg[$acct]['shops'];
                foreach ($db->column('SELECT DISTINCT channel_id FROM gp_fee_order WHERE account = ? AND day = ?', [$acct, $day]) as $c) {
                    $chs[] = (int) $c;
                }
                $chs = array_values(array_unique($chs));
                sort($chs);
                if ($chs === []) {
                    return ['orders' => 0, 'written' => 0, 'before' => 0, 'after' => 0, 'busy' => false];
                }
                foreach ($chs as $c) {
                    ProfitDays::guard($db, $c, [$day]);
                }
                $in = implode(', ', array_fill(0, count($chs), '?'));
                $orders = $db->all("SELECT channel_id, order_ref, day, gateway, grand_inc, parent_ref FROM sale_order WHERE day = ? AND channel_id IN ({$in}) "
                    . 'ORDER BY channel_id, order_ref', [$day, ...$chs]);
                $rows = [];
                foreach ($db->all("SELECT g.* FROM gp_fee_order g JOIN sale_order o ON o.channel_id = g.channel_id AND o.order_ref = g.order_ref WHERE o.day = ? "
                    . "AND o.channel_id IN ({$in})", [$day, ...$chs]) as $g) {
                    $rows[$g['channel_id'] . '|' . strtolower((string) $g['order_ref'])] = $g;
                }
                $live = self::liveReader($db);
                $writes = [];
                $queue = [];
                $delta = [];
                $before = 0;
                $after = 0;
                foreach ($orders as $o) {
                    $ch = (int) $o['channel_id'];
                    $old = $rows[$ch . '|' . strtolower((string) $o['order_ref'])] ?? null;
                    [$new, $q] = self::plan($cfg, $ch, (string) $o['order_ref'], $o, $old, $now, $live, $keepActual);
                    $s0 = self::share($old);
                    $s1 = self::share($new);
                    $before += $s0['fee'];
                    $after += $s1['fee'];
                    if ($new !== null && $new !== $old) {
                        $writes[] = $new;
                        $delta[$ch]['gp_fee'] = ($delta[$ch]['gp_fee'] ?? 0) + $s1['fee'] - $s0['fee'];
                        $delta[$ch]['gp_fee_est'] = ($delta[$ch]['gp_fee_est'] ?? 0) + $s1['est'] - $s0['est'];
                    }
                    array_push($queue, ...$q);
                }
                // a row of the account on this day whose order is not paid now counts nowhere (the snapshot that took its day off switched it off):
                // switched off here too, never left counting
                foreach ($db->all("SELECT g.* FROM gp_fee_order g JOIN sale_order o ON o.channel_id = g.channel_id AND o.order_ref = g.order_ref WHERE g.account = ? "
                    . "AND g.day = ? AND g.state <> 'off' AND o.day IS NULL", [$acct, $day]) as $g) {
                    $writes[] = ['state' => 'off', 'fee_pence' => 0, 'rate_e4' => null, 'invoice_id' => null, 'spread_version' => null, 'updated_at' => $now] + $g;
                }
                self::holdOk($t0);
                self::writeRows($db, $writes);
                foreach ($delta as $ch => $d) {
                    ProfitDays::bump($db, (int) $ch, $day, $d);
                }
                // a guard made a row of a day with nothing on it: taken away again, as a rebuild has none
                foreach ($chs as $c) {
                    DeliveryBills::tidy($db, $c, $day);
                }
                self::queue($db, $queue, $now);
                return ['orders' => count($orders), 'written' => count($writes), 'before' => $before, 'after' => $after, 'busy' => false];
            });
        } catch (\UnexpectedValueException $e) {
            if ($e->getMessage() !== self::BUSY) {
                throw $e;
            }
            return ['orders' => 0, 'written' => 0, 'before' => 0, 'after' => 0, 'busy' => true];
        }
    }

    /**
     * $fn($db, $t0) in a transaction of its own whose row waits are GUARD_WAIT_SEC at most (H3): a guard held elsewhere (a lock wait, a
     * deadlock) rolls it back at once and is thrown as BUSY; never retried here (the next run does it). The session's wait is put back.
     *
     * @template T
     * @param \Closure(Db, float): T $fn
     * @return T
     */
    private function bounded(\Closure $fn): mixed
    {
        $wait = (int) $this->db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
        $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . self::GUARD_WAIT_SEC);
        try {
            $t0 = microtime(true);
            return $this->db->transaction(static fn (Db $db): mixed => $fn($db, $t0), 0);
        } catch (\PDOException $e) {
            if (in_array(Db::driverCode($e), [1205, 1213], true)) {
                throw new \UnexpectedValueException(self::BUSY);
            }
            throw $e;
        } finally {
            try {
                $this->db->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
            } catch (\Throwable) {
                // the connection is gone: nothing to put back
            }
        }
    }

    /** The guards held longer than HOLD_SEC before the writes (a huge day, a slow read): rolled back as BUSY rather than keep a snapshot waiting (H3). */
    private static function holdOk(float $t0): void
    {
        if (microtime(true) - $t0 > self::HOLD_SEC) {
            throw new \UnexpectedValueException(self::BUSY);
        }
    }

    // ---- an invoice's month ------------------------------------------------------------------------------------------------------------

    /**
     * An account's month settled (GPF5-GPF7): with a live invoice and the account switched on, the invoice's net charge shared over the
     * month's card orders by base and written day by day (each day in one transaction under its guards, its orders and bases checked against
     * the plan first: changed meanwhile, the month is planned again, at most SETTLE_TRIES times), then the invoice marked done (only while it
     * is the version planned). No live invoice (deleted) or the account switched off: the month's actual rows back to estimates and every
     * charge on no order of the month taken away. One settle at a time (a named lock; $wait seconds at most, else 'busy': the job does it).
     *
     * @return string done | back | busy (the lock, or a guard held elsewhere: left for the next run) | retry (an order kept changing: left due for the next run)
     *   | off (a live invoice, its account switched off: left due, nothing of it counts)
     */
    public function settleMonth(Caller $caller, string $acct, string $month, int $wait = 0): string
    {
        if (!in_array($acct, self::ACCOUNTS, true) || !ExpenseViews::isMonth($month)) {
            throw new \InvalidArgumentException('an account and a month YYYY-MM');
        }
        $lock = 'cw_gp_settle:' . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$lock, $wait]) !== 1) {
            return 'busy';
        }
        try {
            for ($try = 1; $try <= self::SETTLE_TRIES; $try++) {
                $this->fresh();
                $cfg = $this->config();
                $inv = $this->db->one('SELECT * FROM gp_invoice WHERE live_key = ?', [$acct . '|' . $month]);
                if ($inv === null || !$cfg[$acct]['on']) {
                    return $this->settleBack($acct, $month) ?? ($inv === null ? 'back' : 'off');
                }
                $r = $this->spread($caller, $cfg, $inv);
                if ($r !== 'retry') {
                    return $r;
                }
            }
            return 'retry';
        } finally {
            try {
                $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\Throwable) {
                // the connection is gone: MySQL released it with the session
            }
        }
    }

    /**
     * One spread of a live invoice (settleMonth()).
     *
     * @param array<string, mixed> $inv
     */
    private function spread(Caller $caller, array $cfg, array $inv): string
    {
        $acct = (string) $inv['account'];
        $month = (string) $inv['month'];
        [$first, $last] = ExpenseViews::monthDays($month);
        $id = (int) $inv['id'];
        $now = Clock::db(($this->clock)());
        $rows = $this->db->all("SELECT channel_id, order_ref, day, base_pence FROM gp_fee_order WHERE account = ? AND day BETWEEN ? AND ? AND state <> 'off'",
            [$acct, $first, $last]);
        // H2: the card orders of a shop before its own first day (no estimate): shared over too, their shares on the main shop (on no order)
        $pre = self::preStartOrders($this->db, $cfg[$acct], $first, $last);
        $all = [];
        foreach ($rows as $r) {
            $all[] = ['day' => (string) $r['day'], 'ch' => (int) $r['channel_id'], 'ref' => (string) $r['order_ref'], 'base' => (int) $r['base_pence'], 'pre' => false];
        }
        foreach ($pre as $r) {
            $all[] = ['day' => (string) $r['day'], 'ch' => (int) $r['channel_id'], 'ref' => (string) $r['order_ref'], 'base' => (int) $r['grand_inc'], 'pre' => true];
        }
        usort($all, static fn (array $a, array $b): int => [$a['day'], $a['ch'], strtolower($a['ref'])] <=> [$b['day'], $b['ch'], strtolower($b['ref'])]);
        $net = (int) $inv['net_pence'];
        $shares = ProfitMath::shareOut($net, array_column($all, 'base'));
        $takings = array_sum(array_column($all, 'base'));
        $preShare = 0;
        foreach ($all as $i => $r) {
            $preShare += $r['pre'] ? $shares[$i] : 0;
        }
        // no card order in the month (nothing to share it over): on the account's main shop, on the month's last counted day (GPF7); the shares of
        // the shops' card orders before their own first day go there too (H2)
        $other = $takings === 0 ? $net : $preShare;
        $otherDay = $cfg[$acct]['until'] !== null && $cfg[$acct]['until'] < $last ? (string) $cfg[$acct]['until'] : $last;
        $otherCh = $cfg[$acct]['main'];
        if ($other !== 0 && $otherCh === null) {
            return 'off';
        }
        $version = (int) $inv['spread_version'] + 1;
        $plan = [];
        $onOrders = 0;
        foreach ($all as $i => $r) {
            if (!$r['pre']) {
                $plan[$r['day']][$r['ch'] . '|' . strtolower($r['ref'])] = ['base' => $r['base'], 'fee' => $shares[$i]];
                $onOrders += $shares[$i];
            }
        }
        if (self::$afterPlan !== null) {
            (self::$afterPlan)();
        }
        // the charge on no order put somewhere else before (another day, or none now): taken away first, under its own day's guard
        try {
            foreach ($this->db->all('SELECT id, other_channel, other_day, other_pence FROM gp_invoice WHERE account = ? AND month = ? AND other_pence <> 0', [$acct, $month]) as $o) {
                if ((int) $o['id'] !== $id || $other === 0 || (int) $o['other_channel'] !== $otherCh || (string) $o['other_day'] !== $otherDay) {
                    $this->clearOther((int) $o['id']);
                }
            }
        } catch (\UnexpectedValueException $e) {
            if ($e->getMessage() !== self::BUSY) {
                throw $e;
            }
            return 'busy';
        }
        // the days: every day with a planned row, every day with an actual row of the account in the month (rows gone off are the snapshot's)
        $days = array_keys($plan);
        foreach ($this->db->column("SELECT DISTINCT day FROM gp_fee_order WHERE account = ? AND day BETWEEN ? AND ? AND state = 'actual'", [$acct, $first, $last]) as $d) {
            $days[] = (string) $d;
        }
        if ($other !== 0) {
            $days[] = $otherDay;
        }
        $days = array_values(array_unique($days));
        sort($days, SORT_STRING);
        foreach ($days as $d) {
            try {
                $this->spreadDay($cfg, $id, $version, $acct, $d, $plan[$d] ?? [], $d === $otherDay && $other !== 0 ? [$otherCh, $other] : null, $now);
            } catch (\UnexpectedValueException $e) {
                if ($e->getMessage() === self::BUSY) {
                    return 'busy'; // a guard held elsewhere: rolled back at once, left due for the next run (H3)
                }
                if ($e->getMessage() !== self::CHANGED) {
                    throw $e;
                }
                return 'retry'; // rolled back: an order of the day changed since the plan
            }
        }
        $planned = count($plan) === 0 ? 0 : array_sum(array_map('count', $plan));
        return $this->db->transaction(static function (Db $db) use ($caller, $inv, $id, $version, $rows, $net, $other, $takings, $now, $acct, $month, $first, $last,
            $planned, $onOrders): string {
            $cur = $db->one('SELECT * FROM gp_invoice WHERE id = ? FOR UPDATE', [$id]);
            if ($cur === null || (int) $cur['version'] !== (int) $inv['version'] || $cur['deleted_at'] !== null) {
                return 'retry'; // changed or deleted while it was spread: its own write set it due again
            }
            // H1: done only when the month is exactly what was spread: no month queued meanwhile (an order changed), no counted row of the month
            // that is not this spread's share (a late order's estimate), this spread's shares whole; else it stays due (the job spreads it again)
            if ($db->value('SELECT 1 FROM gp_due WHERE account = ? AND month = ? LIMIT 1', [$acct, $month]) !== null) {
                return 'retry';
            }
            $stray = (int) $db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = ? AND day BETWEEN ? AND ? AND state <> 'off' "
                . "AND NOT (state = 'actual' AND invoice_id = ? AND spread_version = ?)", [$acct, $first, $last, $id, $version]);
            $mine = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(fee_pence), 0) AS f FROM gp_fee_order WHERE invoice_id = ? AND spread_version = ? AND state = 'actual'",
                [$id, $version]) ?? ['n' => -1, 'f' => -1];
            if ($stray !== 0 || (int) $mine['n'] !== $planned || (int) $mine['f'] !== $onOrders) {
                return 'retry';
            }
            $db->exec("UPDATE gp_invoice SET spread_state = 'done', spread_version = ?, spread_at = ?, spread_orders = ?, spread_pence = ?, cw_takings_pence = ? WHERE id = ?",
                [$version, $now, $planned, $net - $other, $takings, $id]);
            GpInvoices::history($db, $caller, $id, null, 'spread', null, ['spread_version' => $version, 'orders' => $planned, 'on_orders' => $net - $other,
                'not_on_an_order' => $other, 'cw_takings' => $takings], null);
            return 'done';
        });
    }

    /**
     * One day of a spread, in one transaction under the guards of the day of every shop involved: the account's rows of the day must be
     * exactly the planned orders with the planned bases (else false: planned again); each written with its share; the day's figures moved
     * by the difference; the invoice's charge on no order put on the day when it goes there.
     *
     * @param array<string, array{base: int, fee: int}> $want
     * @param array{0: int, 1: int}|null $other [channel, pence]
     */
    private function spreadDay(array $cfg, int $invoiceId, int $version, string $acct, string $day, array $want, ?array $other, string $now): bool
    {
        return $this->bounded(static function (Db $db, float $t0) use ($cfg, $invoiceId, $version, $acct, $day, $want, $other, $now): bool {
            $chs = $cfg[$acct]['shops'];
            foreach (array_keys($want) as $k) {
                $chs[] = (int) explode('|', $k)[0];
            }
            foreach ($db->column('SELECT DISTINCT channel_id FROM gp_fee_order WHERE account = ? AND day = ?', [$acct, $day]) as $c) {
                $chs[] = (int) $c;
            }
            if ($other !== null) {
                $chs[] = $other[0];
            }
            $chs = array_values(array_unique($chs));
            sort($chs);
            foreach ($chs as $c) {
                ProfitDays::guard($db, $c, [$day]);
            }
            $have = [];
            foreach ($db->all("SELECT g.*, o.day AS o_day FROM gp_fee_order g JOIN sale_order o ON o.channel_id = g.channel_id AND o.order_ref = g.order_ref "
                . "WHERE g.account = ? AND g.day = ? AND g.state <> 'off'", [$acct, $day]) as $g) {
                $have[$g['channel_id'] . '|' . strtolower((string) $g['order_ref'])] = $g;
            }
            if (count($have) !== count($want)) {
                throw new \UnexpectedValueException(self::CHANGED);
            }
            $writes = [];
            $delta = [];
            foreach ($have as $k => $g) {
                if (!isset($want[$k]) || $want[$k]['base'] !== (int) $g['base_pence'] || (string) $g['o_day'] !== $day) {
                    throw new \UnexpectedValueException(self::CHANGED);
                }
                $new = ['state' => 'actual', 'fee_pence' => $want[$k]['fee'], 'rate_e4' => null, 'invoice_id' => $invoiceId, 'spread_version' => $version,
                    'updated_at' => $now] + $g;
                unset($new['o_day']);
                $old = $g;
                unset($old['o_day']);
                if (self::same($old, $new) && (int) $old['spread_version'] === $version) {
                    continue;
                }
                $writes[] = $new;
                $ch = (int) $g['channel_id'];
                $s0 = self::share($old);
                $s1 = self::share($new);
                $delta[$ch]['gp_fee'] = ($delta[$ch]['gp_fee'] ?? 0) + $s1['fee'] - $s0['fee'];
                $delta[$ch]['gp_fee_est'] = ($delta[$ch]['gp_fee_est'] ?? 0) + $s1['est'] - $s0['est'];
            }
            self::holdOk($t0);
            self::writeRows($db, $writes);
            if ($other !== null) {
                $inv = $db->one('SELECT other_channel, other_day, other_pence FROM gp_invoice WHERE id = ? FOR UPDATE', [$invoiceId]);
                $was = $inv !== null && (int) $inv['other_pence'] !== 0 && (int) $inv['other_channel'] === $other[0] && (string) $inv['other_day'] === $day
                    ? (int) $inv['other_pence'] : 0;
                if ($inv !== null && (int) $inv['other_pence'] !== 0 && $was === 0) {
                    throw new \UnexpectedValueException(self::CHANGED); // still counted on another day (clearOther() runs first): planned again
                }
                $db->exec('UPDATE gp_invoice SET other_channel = ?, other_day = ?, other_pence = ? WHERE id = ?', [$other[0], $day, $other[1], $invoiceId]);
                $delta[$other[0]]['gp_other'] = ($delta[$other[0]]['gp_other'] ?? 0) + $other[1] - $was;
            }
            foreach ($delta as $ch => $d) {
                ProfitDays::bump($db, (int) $ch, $day, $d);
            }
            foreach ($chs as $c) {
                DeliveryBills::tidy($db, $c, $day);
            }
            return true;
        });
    }

    /** An invoice's charge on no order taken away from its day (under that day's guard), the day's row tidied when nothing is left on it. */
    private function clearOther(int $invoiceId): void
    {
        $this->bounded(static function (Db $db, float $t0) use ($invoiceId): void {
            $peek = $db->one('SELECT other_channel, other_day FROM gp_invoice WHERE id = ?', [$invoiceId]);
            if ($peek === null || $peek['other_channel'] === null || $peek['other_day'] === null) {
                return;
            }
            ProfitDays::guard($db, (int) $peek['other_channel'], [(string) $peek['other_day']]);
            $inv = $db->one('SELECT other_channel, other_day, other_pence FROM gp_invoice WHERE id = ? FOR UPDATE', [$invoiceId]);
            if ($inv === null || (int) $inv['other_pence'] === 0 || (int) $inv['other_channel'] !== (int) $peek['other_channel']
                || (string) $inv['other_day'] !== (string) $peek['other_day']) {
                return;
            }
            $db->exec('UPDATE gp_invoice SET other_pence = 0 WHERE id = ?', [$invoiceId]);
            ProfitDays::bump($db, (int) $inv['other_channel'], (string) $inv['other_day'], ['gp_other' => -(int) $inv['other_pence']]);
            DeliveryBills::tidy($db, (int) $inv['other_channel'], (string) $inv['other_day']);
        });
    }

    /**
     * A month back to estimates (no live invoice, or the account switched off): its actual rows decided again without their invoice, every
     * charge on no order of it taken away. 'busy' when a guard was held elsewhere (the rest is left for the next run), else null.
     */
    private function settleBack(string $acct, string $month): ?string
    {
        [$first, $last] = ExpenseViews::monthDays($month);
        foreach ($this->db->column("SELECT DISTINCT day FROM gp_fee_order WHERE account = ? AND day BETWEEN ? AND ? AND state = 'actual' ORDER BY day",
            [$acct, $first, $last]) as $d) {
            if ($this->syncDay($acct, (string) $d, false)['busy']) {
                return 'busy';
            }
        }
        try {
            foreach ($this->db->column('SELECT id FROM gp_invoice WHERE account = ? AND month = ? AND other_pence <> 0', [$acct, $month]) as $id) {
                $this->clearOther((int) $id);
            }
        } catch (\UnexpectedValueException $e) {
            if ($e->getMessage() !== self::BUSY) {
                throw $e;
            }
            return 'busy';
        }
        return null;
    }

    /**
     * The card orders of the account's shops before each shop's own first day in $first..$last (H2): globalpay, paid in the account's days,
     * an amount, no re-send. No estimate is counted for them; an invoice is shared over them too, their shares on the main shop.
     *
     * @return list<array{channel_id: int|string, order_ref: string, day: string, grand_inc: int|string}>
     */
    public static function preStartOrders(Db $db, array $c, string $first, string $last): array
    {
        if (!$c['on']) {
            return [];
        }
        $cond = [];
        $params = [];
        foreach ($c['shop_from'] as $ch => $day) {
            if ($day !== null && $day > $first) {
                $cond[] = '(o.channel_id = ? AND o.day < ?)';
                array_push($params, (int) $ch, $day);
            }
        }
        if ($cond === []) {
            return [];
        }
        $lo = max($first, (string) $c['from']);
        $hi = $c['until'] !== null && $c['until'] < $last ? (string) $c['until'] : $last;
        if ($lo > $hi) {
            return [];
        }
        return $db->all("SELECT o.channel_id, o.order_ref, o.day, o.grand_inc FROM sale_order o WHERE o.day BETWEEN ? AND ? AND o.gateway = 'globalpay' AND o.grand_inc > 0 "
            . 'AND o.parent_ref IS NULL AND (' . implode(' OR ', $cond) . ')', [$lo, $hi, ...$params]);
    }

    // ---- the job -----------------------------------------------------------------------------------------------------------------------

    /**
     * The job (bin/gp_fees.php, GPF8), per account: the months queued taken (their invoice due again, under the spreads' lock: H1), the
     * account's days gone over again when its settings changed since its last good run (and every live invoice of it spread again or, switched
     * off, settled back: M6; the last RECHECK_DAYS days on the nightly; a backfill: the days asked), the estimates at an old rate worked out
     * again, the invoices due spread, an invoice's charge on no order left by a delete taken away. A run left by a process that stopped is
     * marked failed first (M14). Work stops at the deadline (workSec from the start: inside the installer's drain window, M14) and on a day
     * whose guard is held elsewhere (H3): what is left waits for the next run, whose settings signature is recorded only when its days were
     * all gone over. Each account's pass is a gp_run row (ok or failed in CW's words).
     *
     * @return array<string, array<string, int|string>> per account: what it did (left: days or months left for the next run; busy: of them, a guard held)
     */
    public function run(Caller $caller, string $kind = 'job', ?string $from = null, ?string $to = null, ?string $only = null): array
    {
        if (!in_array($kind, ['job', 'nightly', 'backfill'], true)) {
            throw new \InvalidArgumentException('job, nightly or backfill');
        }
        $deadline = microtime(true) + $this->workSec;
        // M14: a run still "running" is one whose process stopped (only the job's lock holder runs): failed, said why
        $this->db->exec("UPDATE gp_run SET state = 'failed', error = 'abandoned: its process stopped before it finished', finished_at = ? WHERE state = 'running'",
            [Clock::db(($this->clock)())]);
        $out = [];
        foreach (self::ACCOUNTS as $acct) {
            if ($only !== null && $only !== $acct) {
                continue;
            }
            $this->fresh();
            $cfg = $this->config()[$acct];
            $runId = $this->db->insert('INSERT INTO gp_run (account, kind, from_day, to_day, actor, started_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$acct, $kind, $from, $to, $caller->actor, Clock::db(($this->clock)())]);
            $did = ['dues' => 0, 'days' => 0, 'written' => 0, 'orders' => 0, 'spread' => 0, 'left' => 0, 'busy' => 0];
            $before = 0;
            $after = 0;
            $complete = true;
            try {
                $did['dues'] = $this->takeDues($acct);
                [$days, $changed] = $this->daysToSync($cfg, $kind, $from, $to);
                if ($changed) {
                    // its settings changed (first or last day, shops, a shop's first day, switched off): every live invoice of it spread again (or
                    // settled back while switched off), so nothing it counted under the old settings stays (M6)
                    $this->db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE account = ? AND deleted_at IS NULL", [$acct]);
                }
                foreach ($days as $d) {
                    if (microtime(true) >= $deadline) {
                        $did['left']++;
                        $complete = false;
                        continue;
                    }
                    $s = $this->syncDay($acct, $d);
                    if ($s['busy']) {
                        $did['busy']++;
                        $did['left']++;
                        $complete = false;
                        continue;
                    }
                    $did['days']++;
                    $did['written'] += $s['written'];
                    $did['orders'] += $s['orders'];
                    $before += $s['before'];
                    $after += $s['after'];
                }
                // estimates at an old rate (the rate changed): their days decided again
                if ($cfg['rate'] !== null) {
                    foreach ($this->db->column("SELECT DISTINCT day FROM gp_fee_order WHERE account = ? AND state = 'estimate' AND rate_e4 <> ? ORDER BY day",
                        [$acct, $cfg['rate']]) as $d) {
                        if (microtime(true) >= $deadline) {
                            $did['left']++;
                            continue;
                        }
                        $s = $this->syncDay($acct, (string) $d);
                        $s['busy'] ? $did['left']++ : $did['days']++;
                        $did['written'] += $s['written'];
                    }
                }
                $months = $this->db->column("SELECT month FROM gp_invoice WHERE account = ? AND ((deleted_at IS NULL AND spread_state = 'due') "
                    . 'OR other_pence <> 0) ORDER BY month', [$acct]);
                // a deleted invoice whose month still has actual rows of it (the delete's own settle did not finish): back to estimates
                foreach ($this->db->column("SELECT i.month FROM gp_invoice i WHERE i.account = ? AND i.deleted_at IS NOT NULL "
                    . "AND EXISTS (SELECT 1 FROM gp_fee_order g WHERE g.invoice_id = i.id AND g.state = 'actual')", [$acct]) as $m) {
                    $months[] = (string) $m;
                }
                foreach (array_values(array_unique(array_map('strval', $months))) as $m) {
                    // a done invoice of an account switched on with its charge on no order where it belongs: nothing to do
                    if ($cfg['on'] && $this->db->value("SELECT 1 FROM gp_invoice WHERE account = ? AND month = ? AND ((deleted_at IS NULL AND spread_state = 'due') "
                        . 'OR (deleted_at IS NOT NULL AND other_pence <> 0)) LIMIT 1', [$acct, $m]) === null
                        && $this->db->value("SELECT 1 FROM gp_invoice i WHERE i.account = ? AND i.month = ? AND i.deleted_at IS NOT NULL AND EXISTS (SELECT 1 FROM gp_fee_order g "
                            . "WHERE g.invoice_id = i.id AND g.state = 'actual') LIMIT 1", [$acct, $m]) === null) {
                        continue;
                    }
                    if (microtime(true) >= $deadline) {
                        $did['left']++;
                        continue;
                    }
                    $r = $this->settleMonth($caller, $acct, $m, self::SETTLE_WAIT_SEC);
                    in_array($r, ['done', 'back', 'off'], true) ? $did['spread']++ : $did['left']++;
                }
                // a backfill goes over the days asked only: it never says the account's days were gone over with its settings (the job does); a run
                // that left days of a settings change records no signature, so the next run goes over them again
                $this->db->exec("UPDATE gp_run SET state = 'ok', sig = ?, orders_read = ?, rows_written = ?, pence_before = ?, pence_after = ?, finished_at = ? WHERE id = ?",
                    [$kind === 'backfill' || !$complete ? null : $cfg['sig'], $did['orders'], $did['written'], $before, $after, Clock::db(($this->clock)()), $runId]);
            } catch (\Throwable $e) {
                $this->db->exec("UPDATE gp_run SET state = 'failed', error = ?, finished_at = ? WHERE id = ?",
                    [mb_substr(self::why($e), 0, 255), Clock::db(($this->clock)()), $runId]);
                throw $e;
            }
            $out[$acct] = $did;
        }
        return $out;
    }

    /** The months queued for the account (gp_due) taken: each one's live invoice marked due, its queue rows removed, in one transaction. */
    private function takeDues(string $acct): int
    {
        // under the spreads' lock (H1): no spread of the account's months is between its plan and its done while the queue is taken
        $lock = 'cw_gp_settle:' . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$lock, self::SETTLE_WAIT_SEC]) !== 1) {
            return 0; // a spread holds it: the queue waits for the next run (durable)
        }
        try {
            return $this->takeDuesLocked($acct);
        } finally {
            try {
                $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (\Throwable) {
                // the connection is gone: MySQL released it with the session
            }
        }
    }

    private function takeDuesLocked(string $acct): int
    {
        $n = 0;
        foreach ($this->db->all('SELECT month, MAX(id) AS top FROM gp_due WHERE account = ? GROUP BY month', [$acct]) as $q) {
            $this->db->transaction(static function (Db $db) use ($acct, $q): void {
                $db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE live_key = ?", [$acct . '|' . $q['month']]);
                $db->exec('DELETE FROM gp_due WHERE account = ? AND month = ? AND id <= ?', [$acct, $q['month'], (int) $q['top']]);
            });
            $n++;
        }
        return $n;
    }

    /**
     * The days the job goes over (in order): a backfill's days; on the nightly the last RECHECK_DAYS days of the account's counted days; and
     * whenever its settings changed since its last good run, every day from the earliest of its first day and its earliest counted row to
     * today (a first day moved earlier, a shop added or taken away, switched off).
     *
     * @return array{0: list<string>, 1: bool} the days, and whether the account's settings changed since its last good run
     */
    private function daysToSync(array $cfg, string $kind, ?string $from, ?string $to): array
    {
        $today = $this->today();
        $days = [];
        if ($kind === 'backfill') {
            if ($from === null || $to === null || $from > $to) {
                throw new \InvalidArgumentException('a backfill needs --from and --to, from on or before to');
            }
            return [self::range($from, $to), false];
        }
        $earliest = $this->db->value("SELECT MIN(day) FROM gp_fee_order WHERE account = ? AND state <> 'off'", [$cfg['account']]);
        $last = $this->db->value("SELECT sig FROM gp_run WHERE account = ? AND state = 'ok' AND sig IS NOT NULL ORDER BY id DESC LIMIT 1", [$cfg['account']]);
        // changed since its last good run; with none yet (the first run, or every one before left days) its days are gone over when it counts
        // anything or is switched on (the Codex review of 6043729: a switch-off before any good run is never skipped)
        $changed = $last === null ? ($earliest !== null || $cfg['on']) : (string) $last !== $cfg['sig'];
        if ($changed) {
            $start = $cfg['on'] ? (string) $cfg['from'] : null;
            if ($earliest !== null && ($start === null || (string) $earliest < $start)) {
                $start = (string) $earliest;
            }
            if ($start !== null && $start <= $today) {
                $days = self::range($start, $today);
            }
        }
        if ($kind === 'nightly' && $cfg['on']) {
            $start = max((string) $cfg['from'], self::addDays($today, -self::RECHECK_DAYS));
            $end = $cfg['until'] !== null && $cfg['until'] < $today ? (string) $cfg['until'] : $today;
            if ($start <= $end) {
                $days = [...$days, ...self::range($start, $end)];
            }
        }
        $days = array_values(array_unique($days));
        sort($days, SORT_STRING);
        return [$days, $changed];
    }

    // ---- the health alert (GPF15) -------------------------------------------------------------------------------------------------------

    /**
     * What the health alert says of the card fees (bin/health_alert.php through CW\Ops\Alerts; docs/decisions.md GPF15), per account switched
     * on (its first day reached), on its main shop:
     *   gp_not_run          no good run of bin/gp_fees.php for JOB_LATE_MIN minutes (or none since it was switched on SWITCH_GRACE_MIN ago):
     *                       estimates of a changed rate, months queued and invoices entered wait
     *   gp_invoice_overdue  a month (from its first month to last month, not after its last day's) whose invoice is not entered
     *                       profit.gp_invoice_late_days (12) days after its end: its card fees are still estimated
     *   gp_double_count     an expense of a Global Payments supplier that may be an invoice entered on Profit > Card fees (GPF9): counted twice
     *                       if it is the same invoice
     * Read only. Quiet while no account is switched on (and on a schema before 0084).
     *
     * @return list<array{key: string, kind: string, channel: ?string, channel_id: ?int, name: ?string, mode: ?string, detail: string, data: array<string, int|string|null>}>
     */
    public static function health(Db $db, \DateTimeImmutable $now): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gp_run'") === null) {
            return [];
        }
        $today = ReportRefresh::ukDay(Clock::db($now));
        $lateRaw = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.gp_invoice_late_days'");
        $late = is_numeric($d = json_decode((string) ($lateRaw ?? '12'), true)) ? max(0, (int) $d) : 12;
        $shops = [];
        foreach ($db->all('SELECT id, code, name FROM channel') as $c) {
            $shops[(int) $c['id']] = ['code' => (string) $c['code'], 'name' => (string) $c['name']];
        }
        $out = [];
        foreach (self::readConfig($db) as $acct => $c) {
            if (!$c['on'] || (string) $c['from'] > $today || $c['main'] === null) {
                continue;
            }
            $name = self::ACCOUNT_NAMES[$acct] ?? (string) $acct;
            $base = ['channel' => $shops[$c['main']]['code'] ?? null, 'channel_id' => (int) $c['main'], 'name' => $shops[$c['main']]['name'] ?? null, 'mode' => null];
            // the job: a good run within the hour (none yet: said once it is switched on longer than the grace)
            $last = $db->value("SELECT MAX(finished_at) FROM gp_run WHERE account = ? AND state = 'ok' AND kind IN ('job', 'nightly')", [$acct]);
            $mins = $last === null ? null : (int) floor(($now->getTimestamp() - Clock::fromDb((string) $last)->getTimestamp()) / 60);
            $switched = $db->value("SELECT MAX(created_at) FROM config_change WHERE subject_type = 'setting' AND action <> 'baseline' AND subject_key = ?",
                ['profit.gp_from_' . $acct]);
            $since = $switched === null ? null : (int) floor(($now->getTimestamp() - Clock::fromDb((string) $switched)->getTimestamp()) / 60);
            $justOn = $since !== null && $since >= 0 && $since < self::SWITCH_GRACE_MIN;
            if (($mins === null && !$justOn) || ($mins !== null && $mins >= self::JOB_LATE_MIN)) {
                $newest = $db->one('SELECT state, error FROM gp_run WHERE account = ? ORDER BY id DESC LIMIT 1', [$acct]);
                $why = $newest !== null && $newest['state'] === 'failed' ? (string) $newest['error'] : '';
                $out[] = ['key' => 'gp_not_run:' . $acct, 'kind' => 'gp_not_run', 'detail' => $mins === null
                    ? sprintf('%s: the card fees job (bin/gp_fees.php) has not run well since the account was switched on', $name)
                    : sprintf('%s: the card fees job (bin/gp_fees.php) has not run well for %d minutes (limit %d)%s', $name, $mins, self::JOB_LATE_MIN, $why === '' ? '' : ": {$why}"),
                    'data' => ['account' => $acct, 'account_name' => $name, 'minutes' => $mins, 'limit' => self::JOB_LATE_MIN, 'error' => $why]] + $base;
            }
            // the months whose invoice is overdue: from its first month to last month (not after its last day's month)
            $missing = [];
            $oldest = null;
            $live = array_flip(array_map('strval', $db->column('SELECT month FROM gp_invoice WHERE account = ? AND deleted_at IS NULL', [$acct])));
            $endMonth = substr(self::addDays(substr($today, 0, 7) . '-01', -1), 0, 7);
            if ($c['until'] !== null && substr((string) $c['until'], 0, 7) < $endMonth) {
                $endMonth = substr((string) $c['until'], 0, 7);
            }
            for ($m = substr((string) $c['from'], 0, 7); $m <= $endMonth; $m = substr(self::addDays(ExpenseViews::monthDays($m)[1], 1), 0, 7)) {
                $due = self::addDays(ExpenseViews::monthDays($m)[1], $late);
                if ($today > $due && !isset($live[$m])) {
                    $missing[] = $m;
                    $oldest ??= $due;
                }
            }
            if ($missing !== []) {
                $out[] = ['key' => 'gp_invoice_overdue:' . $acct, 'kind' => 'gp_invoice_overdue', 'detail' => sprintf('%s: the Global Payments invoice of %s is not '
                    . 'entered %d days after the month\'s end: its card fees are still estimated', $name, implode(', ', $missing), $late),
                    'data' => ['account' => $acct, 'account_name' => $name, 'months' => implode(', ', $missing), 'count' => count($missing), 'late' => $late,
                        'due' => $oldest]] + $base;
            }
            // an expense of a Global Payments supplier that may be an entered invoice of this account's shops
            // (dated from its first day on, a future date too: an expense may be dated ahead)
            $doubt = array_values(array_filter(GpInvoices::doubtful($db, (string) $c['from'], '2099-12-31', null), static fn (array $e): bool => in_array($e['shop'], $c['shops'], true)));
            if ($doubt !== []) {
                $e = end($doubt);
                $out[] = ['key' => 'gp_double_count:' . $acct, 'kind' => 'gp_double_count', 'detail' => sprintf('%s: %d expense(s) of Global Payments may be an invoice '
                    . 'entered on Profit > Card fees (the newest %s dated %s, the %s invoice)', $name, count($doubt), \CW\Expenses\ExpenseMoney::gbp($e['net']), $e['date'], $e['month']),
                    'data' => ['account' => $acct, 'account_name' => $name, 'count' => count($doubt), 'net' => \CW\Expenses\ExpenseMoney::gbp($e['net']),
                        'date' => $e['date'], 'month' => $e['month'], 'expense' => $e['id']]] + $base;
            }
        }
        return $out;
    }

    // ---- small helpers -----------------------------------------------------------------------------------------------------------------

    public function today(): string
    {
        return ReportRefresh::ukDay(Clock::db(($this->clock)()));
    }

    /**
     * Profit day figures moved by $delta for a store's day (inside the caller's transaction, under that day's guard).
     *
     * @param list<array<string, mixed>> $rows full gp_fee_order rows
     */
    private static function writeRows(Db $db, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                foreach (self::ROW_FIELDS as $f) {
                    $params[] = $r[$f] ?? null;
                }
            }
            $db->exec('INSERT INTO gp_fee_order (' . implode(', ', self::ROW_FIELDS) . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count(self::ROW_FIELDS), '?')) . ')'))
                . ' AS n ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "{$c} = n.{$c}",
                    array_diff(self::ROW_FIELDS, ['channel_id', 'order_ref']))), $params);
        }
    }

    /** @param list<array{0: string, 1: string}> $months */
    private static function queue(Db $db, array $months, string $now): void
    {
        $seen = [];
        foreach ($months as [$a, $m]) {
            if (isset($seen[$a . '|' . $m])) {
                continue;
            }
            $seen[$a . '|' . $m] = true;
            $db->exec('INSERT INTO gp_due (account, month, queued_at) VALUES (?, ?, ?)', [$a, $m, $now]);
        }
    }

    /** Whether an account's month has a live invoice (read when asked: never cached across transactions). @return \Closure(string, string): bool */
    private static function liveReader(Db $db): \Closure
    {
        $known = [];
        return static function (string $a, string $m) use ($db, &$known): bool {
            return $known[$a . '|' . $m] ??= $db->value('SELECT 1 FROM gp_invoice WHERE live_key = ?', [$a . '|' . $m]) !== null;
        };
    }

    /** Whether two rows count the same (every field but when it was written). */
    private static function same(array $a, array $b): bool
    {
        foreach (['account', 'day', 'base_pence', 'state', 'fee_pence', 'rate_e4', 'invoice_id'] as $f) {
            if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private static function dayOrNull(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $v) === 1 ? $v : null;
    }

    /** @return list<string> the days from $a to $b */
    public static function range(string $a, string $b): array
    {
        $out = [];
        for ($d = $a; $d <= $b; $d = self::addDays($d, 1)) {
            $out[] = $d;
        }
        return $out;
    }

    public static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable($day . ' 12:00:00', Clock::utc()))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    /** Why a run failed, in CW's own words (never a database message with values in it). */
    private static function why(\Throwable $e): string
    {
        return $e instanceof CwException ? $e->errorCode . ': ' . $e->getMessage() : 'stopped: ' . get_class($e);
    }
}
