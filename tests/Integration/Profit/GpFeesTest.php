<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Expenses\Expenses;
use CW\Profit\GpFees;
use CW\Profit\GpInvoices;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1174 (0084, docs/decisions.md GPF1-GPF14): Global Payments' card fees. Each card order of an account switched on is estimated at the
 * account's rate on its paid day (a refund never touches it; it follows the order); an invoice entered for a month replaces the month's
 * estimates to the penny (spread by base, the remainders to the largest); a correction spreads again, a delete goes back to estimates; a
 * month with no card order puts the charge on no order; a late order is queued and spread again by the job; a rate change re-estimates
 * only estimates; the form's rules; the double count with Expenses refused both ways; the largest real day inside the hold. After each,
 * a rebuild of the days gives the same figures.
 */
final class GpFeesTest extends StockTestCase
{
    private const MERCHANT = '55829291';

    private static function sys(): Caller
    {
        return Caller::system('test');
    }

    private function on(string $acct = 'vpg_online', string $from = '2026-11-01', array $more = []): void
    {
        $s = new Settings(self::$db);
        $s->set(self::sys(), "profit.gp_from_{$acct}", $from, 'switched on for the test');
        foreach ($more as $k => $v) {
            $s->set(self::sys(), $k, $v, 'set for the test');
        }
    }

    /** @return array<string, mixed> */
    private static function body(?string $paid, int $grand, string $gateway = 'globalpay', int $version = 1000, array $lines = []): array
    {
        return ['v' => 1, 'version' => $version, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid ?? '2026-11-01T09:00:00Z', 'paid_at' => $paid,
            'gateway' => $gateway, 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $grand,
            'parcels' => []];
    }

    private function store(Caller $site, string $ref, ?string $paid, int $grand, string $gateway = 'globalpay', int $version = 1000, array $lines = []): void
    {
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, self::body($paid, $grand, $gateway, $version, $lines), 'api');
    }

    /** @return array<string, mixed>|null */
    private static function row(Caller $site, string $ref): ?array
    {
        return self::$db->one('SELECT * FROM gp_fee_order WHERE channel_id = ? AND order_ref = ?', [$site->channelId, $ref]);
    }

    /** @return array{0: int, 1: int, 2: int} gp_fee, gp_fee_est, gp_other of a store's day (0s: no row) */
    private static function day(Caller $site, string $day): array
    {
        $r = self::$db->one('SELECT gp_fee, gp_fee_est, gp_other FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, $day]);
        return $r === null ? [0, 0, 0] : [(int) $r['gp_fee'], (int) $r['gp_fee_est'], (int) $r['gp_other']];
    }

    private static function same(Caller $site, string ...$days): void
    {
        foreach ($days as $d) {
            self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, $d), "a rebuild of {$d} gives the same figures");
        }
    }

    /** As same(), the rows of zeros an order moved to another day leaves (the snapshot's way since 0074: --apply takes them away) aside. */
    private static function sameButZeros(Caller $site, string ...$days): void
    {
        foreach ($days as $d) {
            self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, (int) $site->channelId, $d),
                static fn (string $l): bool => !str_contains($l, 'row of zeros'))), "a rebuild of {$d} gives the same figures");
        }
    }

    /** @param array<string, string> $over */
    private static function invoice(array $over = []): array
    {
        return $over + ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '0000000055829291', 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0.00', 'total' => '1.00', 'note' => ''];
    }

    private function threeOrders(Caller $vpg): void
    {
        $this->on('vpg_online', '2026-11-01', ['profit.gp_merchant_vpg_online' => self::MERCHANT]);
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 2999);
        $this->store($vpg, '9100002', '2026-11-19T11:00:00Z', 1500);
        $this->store($vpg, '9100003', '2026-11-20T10:00:00Z', 501);
    }

    private static function code(\Closure $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e->errorCode;
        }
        return 'no refusal';
    }

    public function testACardOrderIsEstimatedOnItsPaidDay(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 2999);
        self::assertNull(self::row($vpg, '9100001'), 'no account switched on: no fee');
        $this->on();
        $this->store($vpg, '9100002', '2026-11-19T10:00:00Z', 2999);
        $r = self::row($vpg, '9100002');
        self::assertSame(['vpg_online', 'estimate', '2999', '30', '10100', '2026-11-19'],
            [(string) $r['account'], (string) $r['state'], (string) $r['base_pence'], (string) $r['fee_pence'], (string) $r['rate_e4'], (string) $r['day']], '£29.99 at 1.01%: 30p');
        $this->store($vpg, '9100003', '2026-11-19T12:00:00Z', 4_000_000);
        self::assertSame('40400', (string) self::row($vpg, '9100003')['fee_pence'], 'a £40,000 day at 1.01% is £404');
        self::assertSame([40430, 40430, 0], self::day($vpg, '2026-11-19'), 'the day holds the sum of its orders, all estimated');
        self::same($vpg, '2026-11-19');
    }

    public function testOnlyCardOrdersOfTheAccountsShopsAndDaysCount(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $vb = $this->site('vapebig', 'live');
        $ef = $this->site('electrofag', 'live');
        $this->on();
        foreach (['pp' => '9100011', 'pphf' => '9100012', 'office' => '9100013', '' => '9100014'] as $gw => $ref) {
            $this->store($vpg, $ref, '2026-11-19T10:00:00Z', 2999, $gw);
            self::assertNull(self::row($vpg, $ref), "paid by {$gw}: not a Global Payments order");
        }
        $this->store($vpg, '9100015', '2026-10-31T12:00:00Z', 2999);
        self::assertNull(self::row($vpg, '9100015'), 'before the first day');
        $this->store($vpg, '9100016', null, 2999);
        self::assertNull(self::row($vpg, '9100016'), 'not paid');
        $this->store($vpg, '9100017', '2026-11-19T10:00:00Z', 0);
        self::assertNull(self::row($vpg, '9100017'), 'nothing charged');
        // Vape Big's card payments go through Vape and Go Online; the fee stays on Vape Big's own order (VB10)
        $this->store($vb, '9100018', '2026-11-19T10:00:00Z', 1500);
        self::assertSame(['vpg_online', '15'], [(string) self::row($vb, '9100018')['account'], (string) self::row($vb, '9100018')['fee_pence']]);
        self::assertSame([15, 15, 0], self::day($vb, '2026-11-19'));
        self::assertSame(0, (int) self::$db->value('SELECT own_units + own_sale_ex + own_cost FROM profit_day WHERE channel_id = ? AND day = ?', [$vb->channelId, '2026-11-19']));
        $this->store($ef, '9100019', '2026-11-19T10:00:00Z', 1000);
        self::assertNull(self::row($ef, '9100019'), 'Electrofag Online not switched on');
        $this->on('ef_online', '2026-11-01', ['profit.gp_until_ef_online' => '2026-11-20']);
        $this->store($ef, '9100020', '2026-11-20T10:00:00Z', 1000);
        self::assertSame(['ef_online', '23'], [(string) self::row($ef, '9100020')['account'], (string) self::row($ef, '9100020')['fee_pence']], '£10 at 2.28%: 23p');
        $this->store($ef, '9100021', '2026-11-21T10:00:00Z', 1000);
        self::assertNull(self::row($ef, '9100021'), 'after the last day (the contract ended)');
        self::same($vpg, '2026-11-19', '2026-10-31');
        self::same($vb, '2026-11-19');
        self::same($ef, '2026-11-19', '2026-11-20', '2026-11-21');
    }

    public function testTheFeeFollowsTheOrderAndARefundNeverChangesIt(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $line = static fn (int $refund): array => [['line_id' => '9100110', 'variant_id' => '1', 'qty' => 1, 'price_inc' => 2999, 'discount_inc' => 0, 'refund_inc' => $refund,
            'cancelled' => false, 'free' => false]];
        $this->store($vpg, '9100010', '2026-11-19T10:00:00Z', 2999, 'globalpay', 1000, $line(0));
        self::assertSame([30, 30, 0], self::day($vpg, '2026-11-19'));
        $this->store($vpg, '9100010', '2026-11-19T10:00:00Z', 2999, 'globalpay', 1001, $line(2999));
        self::assertSame(['estimate', '30'], [(string) self::row($vpg, '9100010')['state'], (string) self::row($vpg, '9100010')['fee_pence']], 'refunded: the fee stays');
        self::assertSame([30, 30, 0], self::day($vpg, '2026-11-19'));
        $this->store($vpg, '9100010', '2026-11-20T09:00:00Z', 2999, 'globalpay', 1002, $line(2999));
        self::assertSame([[0, 0, 0], [30, 30, 0]], [self::day($vpg, '2026-11-19'), self::day($vpg, '2026-11-20')], 'it moves with the paid day');
        $this->store($vpg, '9100010', '2026-11-20T09:00:00Z', 2999, 'pp', 1003, $line(2999));
        self::assertSame(['off', '0'], [(string) self::row($vpg, '9100010')['state'], (string) self::row($vpg, '9100010')['fee_pence']]);
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-20'));
        $this->store($vpg, '9100010', '2026-11-20T09:00:00Z', 4000, 'globalpay', 1004, $line(0));
        self::assertSame([40, 40, 0], self::day($vpg, '2026-11-20'));
        $this->store($vpg, '9100010', null, 4000, 'globalpay', 1005, $line(0));
        self::assertSame(['off', [0, 0, 0]], [(string) self::row($vpg, '9100010')['state'], self::day($vpg, '2026-11-20')], 'not paid any more: counts nothing');
        self::sameButZeros($vpg, '2026-11-19', '2026-11-20');
    }

    public function testTheInvoiceReplacesTheMonthToThePenny(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->threeOrders($vpg);
        self::assertSame([45, 45, 0], self::day($vpg, '2026-11-19'), '30p + 15p estimated');
        $r = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['use_rate' => '1']));
        self::assertSame(['2', true], [$r['rate'], $r['rate_set']], '£1.00 on £50.00 is 2%, the newest month: the rate now');
        $fees = array_map(static fn (string $ref): string => (string) self::row($vpg, $ref)['fee_pence'], ['9100001', '9100002', '9100003']);
        self::assertSame(['60', '30', '10'], $fees, '100p by 2999 : 1500 : 501 (59.98, 30.00, 10.02): the penny left to the largest remainder');
        self::assertSame(['actual', 'actual', 'actual'], array_map(static fn (string $ref): string => (string) self::row($vpg, $ref)['state'], ['9100001', '9100002', '9100003']));
        self::assertSame([[90, 0, 0], [10, 0, 0]], [self::day($vpg, '2026-11-19'), self::day($vpg, '2026-11-20')], 'the days add up to the invoice; nothing estimated');
        $inv = self::$db->one('SELECT * FROM gp_invoice WHERE id = ?', [$r['id']]);
        self::assertSame(['done', '100', '5000', '3', '0'], [(string) $inv['spread_state'], (string) $inv['spread_pence'], (string) $inv['cw_takings_pence'],
            (string) $inv['spread_orders'], (string) $inv['other_pence']]);
        self::assertSame('"2"', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.gp_rate_vpg_online'"));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_key = 'profit.gp_rate_vpg_online' AND action = 'change' "
            . "AND reason LIKE '%2026-11 Global Payments invoice%'"), 'the rate changed with its reason');
        self::same($vpg, '2026-11-19', '2026-11-20');
    }

    public function testACorrectionSpreadsAgainAndADeleteGoesBackToEstimates(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->threeOrders($vpg);
        $inv = new GpInvoices(self::$db);
        $id = $inv->add(self::sys(), self::invoice())['id'];
        self::assertSame('no refusal', self::code(static fn () => $inv->change(self::sys(), $id, 1, self::invoice(['net' => '2.00', 'total' => '2.00']), 'typed wrongly')));
        self::assertSame(['120', '60', '20'], array_map(static fn (string $ref): string => (string) self::row($vpg, $ref)['fee_pence'], ['9100001', '9100002', '9100003']));
        self::assertSame([[180, 0, 0], [20, 0, 0]], [self::day($vpg, '2026-11-19'), self::day($vpg, '2026-11-20')]);
        $inv->delete(self::sys(), $id, 2, 'the wrong statement');
        self::assertSame(['30', '15', '5'], array_map(static fn (string $ref): string => (string) self::row($vpg, $ref)['fee_pence'], ['9100001', '9100002', '9100003']),
            'back to estimates at the rate (1.01%)');
        self::assertSame([[45, 45, 0], [5, 5, 0]], [self::day($vpg, '2026-11-19'), self::day($vpg, '2026-11-20')]);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM gp_invoice_history WHERE invoice_id = ? AND action IN ('add', 'change', 'delete')", [$id]));
        self::same($vpg, '2026-11-19', '2026-11-20');
        // the month can be entered again
        self::assertSame('no refusal', self::code(static fn () => $inv->add(self::sys(), self::invoice())));
        self::same($vpg, '2026-11-19', '2026-11-20');
    }

    public function testAMonthWithNoCardOrdersPutsTheChargeOnNoOrder(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on('vpg_online', '2026-11-01', ['profit.gp_merchant_vpg_online' => self::MERCHANT]);
        $inv = new GpInvoices(self::$db);
        $id = $inv->add(self::sys(), self::invoice(['sales' => '0', 'net' => '25.00', 'total' => '25.00']))['id'];
        self::assertSame([0, 0, 2500], self::day($vpg, '2026-11-30'), 'on the main shop, the month\'s last day');
        self::same($vpg, '2026-11-30');
        $inv->delete(self::sys(), $id, 1, 'entered by mistake');
        self::assertNull(self::$db->one('SELECT * FROM profit_day WHERE channel_id = ? AND day = ?', [$vpg->channelId, '2026-11-30']), 'nothing left: the row goes');
        self::same($vpg, '2026-11-30');
    }

    public function testALateOrderInAnAppliedMonthIsEstimatedThenSpreadAgain(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->threeOrders($vpg);
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice());
        $this->store($vpg, '9100004', '2026-11-21T10:00:00Z', 1000);
        self::assertSame(['estimate', '10'], [(string) self::row($vpg, '9100004')['state'], (string) self::row($vpg, '9100004')['fee_pence']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM gp_due WHERE account = 'vpg_online' AND month = '2026-11'"), 'the month is queued with the snapshot');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame(['50', '25', '8', '17'], array_map(static fn (string $ref): string => (string) self::row($vpg, $ref)['fee_pence'],
            ['9100001', '9100002', '9100003', '9100004']), '100p over 6000p of takings again: 49.98, 25.00, 8.35, 16.67');
        self::assertSame([[75, 0, 0], [8, 0, 0], [17, 0, 0]], [self::day($vpg, '2026-11-19'), self::day($vpg, '2026-11-20'), self::day($vpg, '2026-11-21')]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_due'));
        self::same($vpg, '2026-11-19', '2026-11-20', '2026-11-21');
    }

    public function testARateChangeWorksOutEveryEstimateAgainAndLeavesActualsAlone(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on('vpg_online', '2026-10-01', ['profit.gp_merchant_vpg_online' => self::MERCHANT]);
        $this->store($vpg, '9100031', '2026-10-15T10:00:00Z', 1000);
        $this->store($vpg, '9100032', '2026-11-19T10:00:00Z', 2999);
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['month' => '2026-10', 'invoice_date' => '2026-10-31', 'sales' => '10.00', 'net' => '0.50', 'total' => '0.50']));
        self::assertSame(['actual', '50'], [(string) self::row($vpg, '9100031')['state'], (string) self::row($vpg, '9100031')['fee_pence']]);
        (new Settings(self::$db))->set(self::sys(), 'profit.gp_rate_vpg_online', '1.5', 'the next estimates at 1.5%');
        self::assertSame('30', (string) self::row($vpg, '9100032')['fee_pence'], 'until the job runs');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame(['estimate', '45', '15000'], [(string) self::row($vpg, '9100032')['state'], (string) self::row($vpg, '9100032')['fee_pence'],
            (string) self::row($vpg, '9100032')['rate_e4']], '£29.99 at 1.5% is 44.985p: 45p');
        self::assertSame(['actual', '50'], [(string) self::row($vpg, '9100031')['state'], (string) self::row($vpg, '9100031')['fee_pence']], 'the invoice\'s share stays');
        self::assertSame([[50, 0, 0], [45, 45, 0]], [self::day($vpg, '2026-10-15'), self::day($vpg, '2026-11-19')]);
        self::same($vpg, '2026-10-15', '2026-11-19');
    }

    public function testASettingChangedIsGoneOverByTheJob(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->store($vpg, '9100041', '2026-11-19T10:00:00Z', 2999);
        $fees = new GpFees(self::$db);
        $fees->run(self::sys());
        self::assertNull(self::row($vpg, '9100041'));
        $this->on();
        $fees->run(self::sys());
        self::assertSame(['estimate', '30'], [(string) self::row($vpg, '9100041')['state'], (string) self::row($vpg, '9100041')['fee_pence']],
            'switched on: the days from the first day gone over (an order stored before)');
        (new Settings(self::$db))->set(self::sys(), 'profit.gp_from_vpg_online', '', 'switched off for the test');
        $fees->run(self::sys());
        self::assertSame(['off', [0, 0, 0]], [(string) self::row($vpg, '9100041')['state'], self::day($vpg, '2026-11-19')], 'switched off: nothing counts');
        self::same($vpg, '2026-11-19');
        // the go-live kit's backfill: the days asked
        $this->on();
        $fees->run(self::sys(), 'backfill', '2026-11-19', '2026-11-19', 'vpg_online');
        self::assertSame([30, 30, 0], self::day($vpg, '2026-11-19'));
        self::same($vpg, '2026-11-19');
    }

    public function testTheInvoiceFormsRules(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $inv = new GpInvoices(self::$db);
        self::assertSame('gp_account_off', self::code(static fn () => $inv->add(self::sys(), self::invoice())));
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        self::assertSame('gp_no_merchant', self::code(static fn () => $inv->add(self::sys(), self::invoice())));
        (new Settings(self::$db))->set(self::sys(), 'profit.gp_merchant_vpg_online', self::MERCHANT, 'the merchant number');
        self::assertSame('gp_wrong_merchant', self::code(static fn () => $inv->add(self::sys(), self::invoice(['merchant_no' => '55829371']))), 'the MOTO account\'s statement');
        self::assertSame('gp_total_mismatch', self::code(static fn () => $inv->add(self::sys(), self::invoice(['total' => '1.70']))));
        self::assertSame('gp_bad_month', self::code(static fn () => $inv->add(self::sys(), self::invoice(['month' => '2026-10', 'invoice_date' => '2026-10-31']))));
        self::assertSame('gp_bad_month', self::code(static fn () => $inv->add(self::sys(), self::invoice(['month' => '2027-01', 'invoice_date' => '2027-01-31']))));
        self::assertSame('gp_bad_net', self::code(static fn () => $inv->add(self::sys(), self::invoice(['net' => '-1.00']))), 'a credit goes in Expenses');
        self::assertSame('gp_bad_date', self::code(static fn () => $inv->add(self::sys(), self::invoice(['invoice_date' => '2026-10-31']))));
        self::assertSame('gp_takings_gap', self::code(static fn () => $inv->add(self::sys(), self::invoice(['sales' => '100.00']))), '£100 against CW\'s £50');
        $id = $inv->add(self::sys(), self::invoice(['sales' => '100.00', 'gap_ok' => '1']))['id'];
        self::assertSame('gp_month_entered', self::code(static fn () => $inv->add(self::sys(), self::invoice(['gap_ok' => '1']))));
        self::assertSame('changed_meanwhile', self::code(static fn () => $inv->change(self::sys(), $id, 9, self::invoice(['gap_ok' => '1']), 'a reason')));
        self::assertSame('bad_reason', self::code(static fn () => $inv->change(self::sys(), $id, 1, self::invoice(['gap_ok' => '1']), '')));
        self::assertSame(['changed' => false, 'version' => 1], $inv->change(self::sys(), $id, 1, self::invoice(['sales' => '100.00']), 'nothing different'));
    }

    public function testTheDoubleCountWithExpensesIsRefusedBothWaysAndFlagged(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->threeOrders($vpg);
        $fees = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $exp = new Expenses(self::$db);
        $e = static fn (string $net, string $date, bool $credit = false): array => ['shop' => (string) $vpg->channelId, 'supplier' => 'Global Payments', 'new_supplier' => '1',
            'type' => (string) $fees, 'invoice_date' => $date, 'invoice_no' => '', 'net' => $net, 'vat' => '0', 'note' => '', 'credit' => $credit ? '1' : ''];
        $first = $exp->add(self::sys(), $e('1.00', '2026-11-30'))['id'];
        $inv = new GpInvoices(self::$db);
        self::assertSame('gp_expense_same', self::code(static fn () => $inv->add(self::sys(), self::invoice())), 'the invoice is an expense already');
        $exp->delete(self::sys(), $first, 1, 'it is the card fees invoice');
        self::assertSame('no refusal', self::code(static fn () => $inv->add(self::sys(), self::invoice())));
        self::assertSame('gp_invoice_same', self::code(static fn () => $exp->add(self::sys(), $e('1.00', '2026-11-29'))), 'and never again as an expense');
        self::assertSame('gp_invoice_same', self::code(static fn () => $exp->add(self::sys(), $e('1.00', '2026-12-01'))), 'dated in the next month too');
        self::assertSame('no refusal', self::code(static fn () => $exp->add(self::sys(), $e('1.00', '2026-11-28', true))), 'a credit note is never refused');
        self::assertSame('no refusal', self::code(static fn () => $exp->add(self::sys(), $e('7.00', '2026-11-27'))), 'another amount: kept, flagged');
        $flags = GpInvoices::doubtful(self::$db, '2026-11-01', '2026-11-30', null);
        self::assertCount(1, $flags);
        self::assertSame([700, '2026-11'], [$flags[0]['net'], $flags[0]['month']]);
    }

    public function testTheLargestRealDayStaysInsideTheHold(): void
    {
        // the largest card day since 1 Nov 2026 (the lead's read): 1,541 orders on 11 Nov
        $vpg = $this->site('vapeandgo', 'live');
        $n = 1541;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [$vpg->channelId, (string) (8000000 + $i), 'completed', '2026-11-11 10:00:00', '2026-11-11', 'globalpay', 2000, 500 + ($i * 37) % 9500, 1,
                '2026-11-11 10:00:00', '2026-11-11 10:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, status, paid_at, day, gateway, vat_bp, grand_inc, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), array_merge(...$chunk));
        }
        $this->on('vpg_online', '2026-11-01', ['profit.gp_merchant_vpg_online' => self::MERCHANT]);
        $fees = new GpFees(self::$db);
        $t = microtime(true);
        $s = $fees->syncDay('vpg_online', '2026-11-11');
        $sync = microtime(true) - $t;
        self::assertSame([$n, $n], [$s['orders'], $s['written']]);
        self::assertLessThan(2.5, $sync, sprintf('the day gone over in %.2f s, inside the 2.5 s hold', $sync));
        self::same($vpg, '2026-11-11');
        $takings = (int) self::$db->value('SELECT SUM(grand_inc) FROM sale_order WHERE channel_id = ?', [$vpg->channelId]);
        $inv = new GpInvoices(self::$db);
        // the invoice saved and its spread right after (every one of the day's 1,541 rows written): the whole save inside one hold
        $t = microtime(true);
        $id = $inv->add(self::sys(), self::invoice(['sales' => sprintf('%d.%02d', intdiv($takings, 100), $takings % 100), 'net' => '512.34', 'total' => '512.34']))['id'];
        $spread = microtime(true) - $t;
        self::assertSame('done', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]));
        self::assertLessThan(2.5, $spread, sprintf('the invoice saved and the day spread in %.2f s, inside the 2.5 s hold', $spread));
        self::assertSame([51234, 0, 0], self::day($vpg, '2026-11-11'), 'to the penny');
        self::assertSame($n, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'actual' AND invoice_id = ?", [$id]));
        self::same($vpg, '2026-11-11');
        // a correction of a penny: spread again, still to the penny
        $inv->change(self::sys(), $id, 1, self::invoice(['sales' => sprintf('%d.%02d', intdiv($takings, 100), $takings % 100), 'net' => '512.35', 'total' => '512.35']),
            'a penny out');
        self::assertSame([51235, 0, 0], self::day($vpg, '2026-11-11'));
        self::same($vpg, '2026-11-11');
    }
}
