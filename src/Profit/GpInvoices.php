<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Expenses\ExpenseAccess;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCostMath;
use CW\Settings;

/**
 * The Global Payments invoices entered on Profit > Card fees (CW-1174, 0084; docs/decisions.md GPF5, GPF6, GPF9, GPF10): one per account and
 * CW calendar month, as printed (merchant number, invoice date, sales, total net charge, VAT, charge applied). Saving one stores it as due
 * (durable before acting) and its spread runs right after the commit (GpFees::settleMonth; the job finishes it otherwise). A change (with a
 * reason) or a delete (a mark with a reason) spreads the month again, or sends it back to estimates. The newest month's invoice may set the
 * account's estimate rate (its net / sales: a version of the setting with the reason).
 *
 * Written by a person holding the Expenses job (ExpenseAccess::mayEdit, re-read in the transaction), or a server tool. The double count with
 * Expenses (GPF9): an expense of a Global Payments supplier name (profit.gp_expense_suppliers) for a shop of the account, dated in the
 * invoice's month or the next, with the same net or total, is refused both ways; both saves take the same row lock (that setting's row).
 */
final class GpInvoices
{
    public const NOTE_MAX = 500;
    private const SUPPLIERS_KEY = 'profit.gp_expense_suppliers';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * Adds an invoice. $in: account, month (YYYY-MM), merchant_no, invoice_date (YYYY-MM-DD), sales, net, vat, total (pounds as printed), note,
     * use_rate (true / '1' / 'on': set the account's estimate rate from it, when it is the newest month entered), gap_ok (the person checked
     * figures that differ from CW's card takings of the month by more than profit.gp_takings_gap_pct), current_ok (the month still running is
     * the one meant: F5).
     *
     * @param array<string, mixed> $in
     * @return array{id: int, rate: string, rate_set: bool}
     */
    public function add(Caller $caller, array $in): array
    {
        $r = $this->db->transaction(function (Db $db) use ($caller, $in): array {
            ExpenseAccess::mayEdit($db, $caller);
            self::lockGuard($db);
            $cfg = GpFees::readConfig($db);
            $acct = is_string($in['account'] ?? null) && in_array($in['account'], GpFees::ACCOUNTS, true) ? $in['account']
                : throw new CwException('gp_choose_account', 'choose the Global Payments account the invoice is for', 422, ['field' => 'account']);
            $month = trim((string) ($in['month'] ?? ''));
            $v = $this->fields($db, $cfg[$acct], $month, $in);
            // the month still running (its invoice comes after it ends): only with its own tick (the final review of 7332729, F5)
            if ($month === substr(\CW\Reports\ReportRefresh::ukDay(Clock::db(($this->clock)())), 0, 7) && !self::ticked($in['current_ok'] ?? null)) {
                throw new CwException('gp_current_month', 'the month is still running: its invoice comes after it ends. Check the month, then tick that it is this '
                    . 'month\'s invoice', 409, ['field' => 'current_ok']);
            }
            if (($live = $db->value('SELECT id FROM gp_invoice WHERE live_key = ? FOR UPDATE', [$acct . '|' . $month])) !== null) {
                throw new CwException('gp_month_entered', "the {$month} invoice of this account is entered already: change that one", 409,
                    ['field' => 'month', 'invoice' => (int) $live]);
            }
            $this->checkGap($db, $acct, $month, $v, self::ticked($in['gap_ok'] ?? null));
            self::checkExpenses($db, $cfg[$acct], $month, $v['invoice_date'], $v['net'], $v['net'] + $v['vat']);
            $now = Clock::db(($this->clock)());
            $id = $db->insert('INSERT INTO gp_invoice (account, month, merchant_no, invoice_date, sales_pence, net_pence, vat_pence, total_pence, rate_e4, note, '
                . 'created_by, created_actor, created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$acct, $month, $v['merchant_no'], $v['invoice_date'], $v['sales'], $v['net'], $v['vat'], $v['net'] + $v['vat'], $v['rate'], $v['note'],
                    $caller->staffUserId, $caller->actor, $now, $caller->staffUserId, $caller->actor, $now]);
            self::history($db, $caller, $id, 1, 'add', null, self::state($db, $id), null);
            $set = self::ticked($in['use_rate'] ?? null) && $this->setRate($db, $caller, $acct, $month, $v);
            return ['id' => $id, 'rate' => ProfitMath::ratePercent($v['rate']), 'rate_set' => $set, 'account' => $acct, 'month' => $month];
        });
        $this->settleAfter($caller, $r['account'], $r['month']);
        return ['id' => $r['id'], 'rate' => $r['rate'], 'rate_set' => $r['rate_set']];
    }

    /**
     * Changes an invoice's figures (its account and month never change: delete it and add the right one). $seen: the version the page
     * showed (409 changed_meanwhile otherwise); a reason (3-500 characters) is kept with the change. Nothing different: no new version.
     *
     * @param array<string, mixed> $in as add()
     * @return array{changed: bool, version: int}
     */
    public function change(Caller $caller, int $id, int $seen, array $in, string $reason): array
    {
        $r = $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $in, $reason): array {
            ExpenseAccess::mayEdit($db, $caller);
            $reason = \CW\Admin\ConfigHistory::reason($reason);
            self::lockGuard($db);
            $row = $this->locked($db, $id, $seen);
            $acct = (string) $row['account'];
            $month = (string) $row['month'];
            $cfg = GpFees::readConfig($db);
            $v = $this->fields($db, $cfg[$acct], $month, $in);
            $same = $v['merchant_no'] === (string) $row['merchant_no'] && $v['invoice_date'] === (string) $row['invoice_date'] && $v['sales'] === (int) $row['sales_pence']
                && $v['net'] === (int) $row['net_pence'] && $v['vat'] === (int) $row['vat_pence'] && $v['note'] === ($row['note'] === null ? null : (string) $row['note']);
            if ($same) {
                $set = self::ticked($in['use_rate'] ?? null) && $this->setRate($db, $caller, $acct, $month, $v);
                return ['changed' => false, 'version' => (int) $row['version'], 'account' => $acct, 'month' => $month, 'settle' => $set];
            }
            if ($v['sales'] !== (int) $row['sales_pence']) {
                $this->checkGap($db, $acct, $month, $v, self::ticked($in['gap_ok'] ?? null));
            }
            if ($v['net'] !== (int) $row['net_pence'] || $v['vat'] !== (int) $row['vat_pence'] || $v['invoice_date'] !== (string) $row['invoice_date']) {
                self::checkExpenses($db, $cfg[$acct], $month, $v['invoice_date'], $v['net'], $v['net'] + $v['vat']);
            }
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $db->exec("UPDATE gp_invoice SET merchant_no = ?, invoice_date = ?, sales_pence = ?, net_pence = ?, vat_pence = ?, total_pence = ?, rate_e4 = ?, note = ?, "
                . "spread_state = 'due', version = ?, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?",
                [$v['merchant_no'], $v['invoice_date'], $v['sales'], $v['net'], $v['vat'], $v['net'] + $v['vat'], $v['rate'], $v['note'], $version, $caller->staffUserId,
                    $caller->actor, Clock::db(($this->clock)()), $id]);
            self::history($db, $caller, $id, $version, 'change', $before, self::state($db, $id), $reason);
            if (self::ticked($in['use_rate'] ?? null)) {
                $this->setRate($db, $caller, $acct, $month, $v);
            }
            return ['changed' => true, 'version' => $version, 'account' => $acct, 'month' => $month, 'settle' => true];
        });
        if ($r['changed']) {
            $this->settleAfter($caller, $r['account'], $r['month']);
        }
        return ['changed' => $r['changed'], 'version' => $r['version']];
    }

    /** Deletes an invoice by a mark with a reason (3-500 characters): its month goes back to estimates (spread right after, else by the job). */
    public function delete(Caller $caller, int $id, int $seen, string $reason): void
    {
        $r = $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $reason): array {
            ExpenseAccess::mayEdit($db, $caller);
            $reason = \CW\Admin\ConfigHistory::reason($reason);
            $row = $this->locked($db, $id, $seen);
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $now = Clock::db(($this->clock)());
            $db->exec("UPDATE gp_invoice SET spread_state = 'due', version = ?, updated_by = ?, updated_actor = ?, updated_at = ?, deleted_at = ?, deleted_by = ?, "
                . 'deleted_actor = ?, deleted_reason = ? WHERE id = ?', [$version, $caller->staffUserId, $caller->actor, $now, $now, $caller->staffUserId, $caller->actor,
                    $reason, $id]);
            self::history($db, $caller, $id, $version, 'delete', $before, self::state($db, $id), $reason);
            return ['account' => (string) $row['account'], 'month' => (string) $row['month']];
        });
        $this->settleAfter($caller, $r['account'], $r['month']);
    }

    /**
     * The invoice's spread right after the commit (its due mark is durable: the job does it when this cannot, GPF8). Never fails the save:
     * what it did is said by the invoice's state on its page.
     */
    private function settleAfter(Caller $caller, string $acct, string $month): void
    {
        $this->db->afterCommit(function () use ($acct, $month): void {
            try {
                (new GpFees($this->db, $this->clock))->settleMonth(Caller::system('gp_fees'), $acct, $month, 0);
            } catch (\Throwable) {
                // due, durable: the next run of bin/gp_fees.php spreads it
            }
        });
    }

    // ---- reads -----------------------------------------------------------------------------------------------------------------------

    /** An invoice as its history keeps it. @return array<string, mixed> */
    public static function state(Db $db, int $id): array
    {
        $r = $db->one('SELECT account, month, merchant_no, invoice_date, sales_pence, net_pence, vat_pence, total_pence, rate_e4, note, deleted_at, deleted_reason '
            . 'FROM gp_invoice WHERE id = ?', [$id]) ?? throw new \LogicException('no such invoice');
        foreach (['sales_pence', 'net_pence', 'vat_pence', 'total_pence', 'rate_e4'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        return $r;
    }

    /** One line of an invoice's history (append-only). */
    public static function history(Db $db, Caller $caller, int $id, ?int $version, string $action, ?array $before, array $after, ?string $reason): void
    {
        $db->exec('INSERT INTO gp_invoice_history (invoice_id, version, action, before_json, after_json, reason, actor, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $version, $action, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR), $reason,
                $caller->actor, Clock::db(Clock::now())]);
    }

    /**
     * CW's card takings of an account's month (GPF6): the bases of its card orders that count, and the card orders of its shops before their own
     * first day (H2: an invoice holds them too). The one calculation of the invoice's page, its form's check and the list (cwTakingsByMonth()).
     */
    public static function cwTakings(Db $db, string $acct, string $month): int
    {
        return self::cwTakingsByMonth($db, $acct, $month)[$month] ?? 0;
    }

    /**
     * CW's card takings of each month of an account (GPF6; the final review of 7332729, M15: the list of Profit > Card fees and an invoice's page
     * say the same): per month YYYY-MM, its counted card orders' bases and its shops' card orders before their own first day. Only $month when
     * given.
     *
     * @return array<string, int>
     */
    public static function cwTakingsByMonth(Db $db, string $acct, ?string $month = null): array
    {
        [$first, $last] = $month === null ? ['2000-01-01', '2099-12-31'] : ExpenseViews::monthDays($month);
        $out = [];
        foreach ($db->all("SELECT LEFT(day, 7) AS m, SUM(base_pence) AS b FROM gp_fee_order WHERE account = ? AND day BETWEEN ? AND ? AND state <> 'off' GROUP BY m",
            [$acct, $first, $last]) as $r) {
            $out[(string) $r['m']] = (int) $r['b'];
        }
        foreach (GpFees::preStartOrders($db, GpFees::readConfig($db)[$acct], $first, $last) as $r) {
            $m = substr((string) $r['day'], 0, 7);
            $out[$m] = ($out[$m] ?? 0) + (int) $r['grand_inc'];
        }
        return $out;
    }

    /**
     * THE matching window of an invoice and an expense (M10: one rule for the saves both ways and the alert): from the first day of the
     * invoice's month to the later of the next month's last day and 31 days after the invoice's own printed date. SQL over gp_invoice i.
     */
    private const WINDOW_SQL = "DATE(CONCAT(i.month, '-01')) <= %s AND %s <= GREATEST(LAST_DAY(DATE_ADD(DATE(CONCAT(i.month, '-01')), INTERVAL 1 MONTH)), "
        . 'DATE_ADD(i.invoice_date, INTERVAL 31 DAY))';

    // ---- the double count with Expenses (GPF9) ---------------------------------------------------------------------------------------

    /** Whether an expense supplier name is one of Global Payments' (profit.gp_expense_suppliers; without case, spaces at the ends ignored). */
    public static function isGpSupplier(Db $db, string $name): bool
    {
        // compared the way the suppliers' own names compare (the table's collation: case and accents aside), so a name typed "Glóbal Payments"
        // that finds the supplier "Global Payments" is Global Payments here too (M11)
        foreach (self::gpNames($db) as $n) {
            if ((int) $db->value('SELECT ? = ? COLLATE utf8mb4_0900_ai_ci', [trim($name), $n]) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> the supplier names that are Global Payments (profit.gp_expense_suppliers), trimmed */
    private static function gpNames(Db $db): array
    {
        $v = $db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [self::SUPPLIERS_KEY]);
        return array_values(array_filter(array_map(static fn (string $n): string => trim($n), explode(',', $v === null ? '' : (string) json_decode((string) $v, true))),
            static fn (string $n): bool => $n !== ''));
    }

    /**
     * Expenses::add / change: an expense of a Global Payments supplier for $shop, dated $date, with this net and total, refused when it is an
     * invoice entered on Profit > Card fees (a live invoice of an account taking $shop whose matching window holds $date: WINDOW_SQL, M10, with
     * the same net or total): it counts on the orders there. $supplierName is the supplier the expense names (resolved: M11). Takes the guard's row lock first (the invoice's save takes it too). A credit note
     * (net below 0) is never refused.
     */
    public static function guardExpense(Db $db, string $supplierName, int $shop, string $date, int $net, int $total): void
    {
        if (!self::isGpSupplier($db, $supplierName)) {
            return;
        }
        self::lockGuard($db);
        if ($net <= 0) {
            return;
        }
        foreach (GpFees::readConfig($db) as $acct => $c) {
            if (!in_array($shop, $c['shops'], true)) {
                continue;
            }
            $hit = $db->one('SELECT i.id, i.month FROM gp_invoice i WHERE i.account = ? AND i.deleted_at IS NULL AND (i.net_pence = ? OR i.total_pence = ?) AND '
                . sprintf(self::WINDOW_SQL, '?', '?') . ' LIMIT 1', [$acct, $net, $total, $date, $date]);
            if ($hit !== null) {
                throw new CwException('gp_invoice_same', "this is the Global Payments invoice of {$hit['month']} entered on Profit > Card fees: it counts on the "
                    . 'card orders there, so it is not an expense too', 409, ['field' => 'net', 'invoice' => (int) $hit['id'], 'month' => (string) $hit['month']]);
            }
        }
    }

    /**
     * The expenses of a Global Payments supplier that may be an invoice entered on Profit > Card fees (GPF9, flagged on Real profit): live,
     * above 0, dated from $from to $to (a shop, or every shop), for a shop of an account with a live invoice of the expense's month or the month
     * before.
     *
     * @return list<array{id: int, shop: int, date: string, net: int, month: string}>
     */
    public static function doubtful(Db $db, string $from, string $to, ?int $shop): array
    {
        $names = self::gpNames($db);
        if ($names === []) {
            return [];
        }
        $out = [];
        foreach (GpFees::readConfig($db) as $acct => $c) {
            if ($c['shops'] === []) {
                continue;
            }
            $shops = $shop === null ? $c['shops'] : array_values(array_intersect($c['shops'], [$shop]));
            if ($shops === []) {
                continue;
            }
            // each live expense of a Global Payments supplier name (the table's collation: M11) inside a live invoice's matching window (M10)
            foreach ($db->all('SELECT e.id, e.channel_id, e.invoice_date, e.net_pence, MIN(i.month) AS month FROM expense e JOIN expense_supplier s ON s.id = e.supplier_id '
                . 'JOIN gp_invoice i ON i.account = ? AND i.deleted_at IS NULL AND ' . sprintf(self::WINDOW_SQL, 'e.invoice_date', 'e.invoice_date')
                . ' WHERE e.deleted_at IS NULL AND e.net_pence > 0 AND e.invoice_date BETWEEN ? AND ? AND e.channel_id IN (' . implode(', ', array_fill(0, count($shops), '?'))
                . ') AND s.name IN (' . implode(', ', array_fill(0, count($names), '?')) . ') GROUP BY e.id, e.channel_id, e.invoice_date, e.net_pence ORDER BY e.invoice_date, e.id',
                [$acct, $from, $to, ...$shops, ...$names]) as $e) {
                $out[(int) $e['id']] = ['id' => (int) $e['id'], 'shop' => (int) $e['channel_id'], 'date' => (string) $e['invoice_date'], 'net' => (int) $e['net_pence'],
                    'month' => (string) $e['month']];
            }
        }
        ksort($out);
        return array_values($out);
    }

    // ---- the form ----------------------------------------------------------------------------------------------------------------------

    /**
     * The fields of an invoice, checked (GPF6): the account switched on, the month from its first month to this month (and not after its last
     * day's), the merchant number its setting names, the invoice date, sales, net, VAT and charge applied (net + VAT) as printed, a note.
     *
     * @param array<string, mixed> $c the account's settings (GpFees::readConfig)
     * @param array<string, mixed> $in
     * @return array{merchant_no: string, invoice_date: string, sales: int, net: int, vat: int, rate: int, note: ?string}
     */
    private function fields(Db $db, array $c, string $month, array $in): array
    {
        if (!$c['on']) {
            throw new CwException('gp_account_off', 'this account\'s card fees are not switched on (its first day on Settings > Profit)', 422, ['field' => 'account']);
        }
        $today = \CW\Reports\ReportRefresh::ukDay(Clock::db(($this->clock)()));
        if (!ExpenseViews::isMonth($month) || $month < substr((string) $c['from'], 0, 7) || $month > substr($today, 0, 7)
            || ($c['until'] !== null && $month > substr((string) $c['until'], 0, 7))) {
            throw new CwException('gp_bad_month', 'the month must be one this account\'s card fees count in, up to this month', 422, ['field' => 'month']);
        }
        if ($c['merchant'] === '') {
            throw new CwException('gp_no_merchant', 'this account has no merchant number yet: it is set on Settings > Profit first', 422, ['field' => 'merchant_no']);
        }
        $merchant = preg_replace('/\s+/', '', (string) ($in['merchant_no'] ?? '')) ?? '';
        $merchant = ltrim($merchant, '0');
        if ($merchant !== ltrim($c['merchant'], '0') || $merchant === '') {
            throw new CwException('gp_wrong_merchant', 'the merchant number is not this account\'s: check you have the right statement', 422, ['field' => 'merchant_no']);
        }
        $date = trim((string) ($in['invoice_date'] ?? ''));
        [$first] = ExpenseViews::monthDays($month);
        if (!RegularCostMath::isDay($date) || $date < $first || $date > RegularCostMath::addDays($first, 92)) {
            throw new CwException('gp_bad_date', 'the invoice date must be a day from the start of its month to two months after it', 422, ['field' => 'invoice_date']);
        }
        $money = [];
        foreach (['sales' => 'the sales', 'net' => 'the total net charge', 'vat' => 'the VAT'] as $f => $what) {
            $p = ExpenseMoney::pence((string) ($in[$f] ?? ''));
            if ($p === null) {
                throw new CwException("gp_bad_{$f}", "{$what} is not an amount in pounds and pence (0 or more; a credit from Global Payments is added in Expenses as a credit note)",
                    422, ['field' => $f]);
            }
            $money[$f] = $p;
        }
        $total = ExpenseMoney::pence((string) ($in['total'] ?? ''), ExpenseMoney::MAX_TOTAL_PENCE);
        if ($total === null || $total !== $money['net'] + $money['vat']) {
            throw new CwException('gp_total_mismatch', 'the total net charge and the VAT must add up to the charge applied to the account', 422,
                ['field' => 'total', 'sum' => $money['net'] + $money['vat']]);
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::NOTE_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw new CwException('gp_bad_note', 'the note is at most ' . self::NOTE_MAX . ' characters', 422, ['field' => 'note']);
        }
        // the invoice's own rate (net / sales); an invoice always settles at its full net charge, whatever its rate (a low-volume month with a
        // minimum charge is 25% and more: M12); only the estimate rate is capped at 10% (setRate())
        $rate = $money['sales'] > 0 ? min(ProfitMath::mulDivRound($money['net'], 1_000_000, $money['sales']), 4_000_000_000) : 0;
        return ['merchant_no' => $c['merchant'], 'invoice_date' => $date, 'sales' => $money['sales'], 'net' => $money['net'], 'vat' => $money['vat'], 'rate' => $rate,
            'note' => $note === '' ? null : $note];
    }

    /** The invoice's sales far from CW's card takings of its month: refused until the person ticks that they checked (GPF6). */
    private function checkGap(Db $db, string $acct, string $month, array $v, bool $ok): void
    {
        if ($ok) {
            return;
        }
        $cw = self::cwTakings($db, $acct, $month);
        $pct = (string) (json_decode((string) ($db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.gp_takings_gap_pct'") ?? '"10"'), true) ?? '10');
        $bp = ProfitMath::vatBp($pct) ?? 1000;
        $far = $v['sales'] === 0 ? $cw > 0 : abs($v['sales'] - $cw) * 10000 > $v['sales'] * $bp;
        if ($far) {
            throw new CwException('gp_takings_gap', 'the invoice\'s sales are far from CW\'s card takings of the month: check the month and the account, then tick that '
                . 'the figures are right', 409, ['field' => 'gap_ok', 'cw' => $cw, 'sales' => $v['sales']]);
        }
    }

    /**
     * An expense of a Global Payments supplier that is this invoice (same net or total, dated inside the invoice's matching window: WINDOW_SQL,
     * M10): refused (GPF9). The names compare the way the suppliers' do (M11).
     */
    private static function checkExpenses(Db $db, array $c, string $month, string $invoiceDate, int $net, int $total): void
    {
        if ($c['shops'] === [] || $net <= 0) {
            return;
        }
        $names = self::gpNames($db);
        if ($names === []) {
            return;
        }
        $hit = $db->one('SELECT e.id FROM expense e JOIN expense_supplier s ON s.id = e.supplier_id JOIN (SELECT ? AS month, ? AS invoice_date) i WHERE e.deleted_at IS NULL '
            . 'AND e.channel_id IN (' . implode(', ', array_fill(0, count($c['shops']), '?')) . ') AND ' . sprintf(self::WINDOW_SQL, 'e.invoice_date', 'e.invoice_date')
            . ' AND (e.net_pence = ? OR e.total_pence = ?) AND s.name IN (' . implode(', ', array_fill(0, count($names), '?')) . ') LIMIT 1',
            [$month, $invoiceDate, ...$c['shops'], $net, $total, ...$names]);
        if ($hit !== null) {
            throw new CwException('gp_expense_same', 'this invoice is entered as an expense already (Profit > Expenses): delete that expense first, so it is not counted twice',
                409, ['field' => 'net', 'expense' => (int) $hit['id']]);
        }
    }

    /**
     * The account's estimate rate set from this invoice (GPF3) when it is the newest month entered for the account and it has sales: a version
     * of the setting with the reason, by CW (the person who entered the invoice is named in the reason). Whether it was set.
     */
    private function setRate(Db $db, Caller $caller, string $acct, string $month, array $v): bool
    {
        if ($v['sales'] <= 0 || $v['rate'] <= 0 || $v['rate'] > 100_000) {
            return false; // no sales, or above 10%: never the estimate rate (M12; the setting's own limit)
        }
        $newer = $db->value('SELECT 1 FROM gp_invoice WHERE account = ? AND deleted_at IS NULL AND month > ? LIMIT 1', [$acct, $month]);
        if ($newer !== null) {
            return false;
        }
        $r = (new Settings($db))->set(Caller::system('gp_invoice'), "profit.gp_rate_{$acct}", ProfitMath::ratePercent($v['rate']),
            "the {$month} Global Payments invoice (" . mb_substr($caller->actor, 0, 64) . ')');
        return $r['changed'];
    }

    /** The guard's row lock (GPF9): an invoice's save and a Global Payments expense's save one after the other. */
    private static function lockGuard(Db $db): void
    {
        $db->value('SELECT setting_key FROM app_setting WHERE setting_key = ? FOR UPDATE', [self::SUPPLIERS_KEY]);
    }

    /** @return array<string, mixed> the invoice locked, at the version the page showed */
    private function locked(Db $db, int $id, int $seen): array
    {
        $row = $db->one('SELECT * FROM gp_invoice WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('not_found', 'there is no such invoice', 404);
        if ($row['deleted_at'] !== null) {
            throw new CwException('gp_deleted', 'this invoice is deleted', 409);
        }
        if ((int) $row['version'] !== $seen) {
            throw new CwException('changed_meanwhile', 'someone changed this invoice since you opened it: open it again', 409, ['version' => (int) $row['version']]);
        }
        return $row;
    }

    private static function ticked(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'on'], true);
    }
}
