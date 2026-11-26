<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Expenses\ExpenseViews;
use CW\Expenses\Expenses;
use CW\Profit\GpFees;
use CW\Profit\GpInvoices;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1174, the Codex review of 6043729 (runs/gpfees_review_r1/codex_findings.md) with the lead's decisions, each shown failing first:
 * H1 a late order met by the job during a spread is never left beside a full invoice; H2 a shop's own first day (Vape Big from 1 Dec): its
 * earlier card orders get no estimate and their share of an invoice goes on the main shop's "on no order" line, so Vape and Go's month
 * carries the whole invoice; H3 a contended guard of either shop makes a day pass or a spread give way at once, never holding the other shop;
 * M6 switching off clears an invoice's charge on no order; M7 a batch with an old configuration never restores fees; M8 an order that is no
 * card order never reads the card fee table; M10 an invoice dated late still meets its expense; M11 a supplier typed with an accent is still
 * Global Payments; M12 a low-volume invoice settles at its full charge; M13 a re-send gets no card fee; M14 an abandoned run is marked failed
 * and the job stops at its deadline; L15 re-dating an actual order inside its month spreads the month again.
 */
final class GpFeesR1Test extends StockTestCase
{
    private const MERCHANT = '55829291';

    private static function sys(): Caller
    {
        return Caller::system('test');
    }

    private function set(string $key, string $value): void
    {
        (new Settings(self::$db))->set(self::sys(), $key, $value, 'set for the test');
    }

    private function on(string $from = '2026-11-01'): void
    {
        $this->set('profit.gp_from_vpg_online', $from);
        $this->set('profit.gp_merchant_vpg_online', self::MERCHANT);
    }

    private function store(Caller $site, string $ref, ?string $paid, int $grand, string $gateway = 'globalpay', int $version = 1000, ?string $parent = null, ?OrderSnapshots $s = null): void
    {
        ($s ?? new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, ['v' => 1, 'version' => $version, 'order_no' => '', 'status' => 'completed',
            'placed_at' => $paid ?? '2026-11-01T09:00:00Z', 'paid_at' => $paid, 'gateway' => $gateway, 'currency' => 'GBP', 'parent_ref' => $parent, 'lines' => [],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $grand, 'parcels' => []], 'api');
    }

    private static function row(Caller $site, string $ref): ?array
    {
        return self::$db->one('SELECT * FROM gp_fee_order WHERE channel_id = ? AND order_ref = ?', [$site->channelId, $ref]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function day(Caller $site, string $day): array
    {
        $r = self::$db->one('SELECT gp_fee, gp_fee_est, gp_other FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, $day]);
        return $r === null ? [0, 0, 0] : [(int) $r['gp_fee'], (int) $r['gp_fee_est'], (int) $r['gp_other']];
    }

    private static function invoice(array $over = []): array
    {
        return $over + ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => self::MERCHANT, 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0', 'total' => '1.00', 'note' => '', 'current_ok' => '1'];
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

    private static function same(Caller $site, string ...$days): void
    {
        foreach ($days as $d) {
            self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, $d), "a rebuild of {$d} gives the same figures");
        }
    }

    protected function tearDown(): void
    {
        GpFees::$afterPlan = null;
        parent::tearDown();
    }

    public function testH1ALateOrderMetByTheJobDuringASpreadIsNeverLeftBesideAFullInvoice(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 2999);
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 2001);
        $inv = new GpInvoices(self::$db);
        $id = $inv->add(self::sys(), self::invoice())['id'];
        // the next spread plans, then a late order arrives and the job takes its queue entry before the spread ends (as cron would)
        GpFees::$afterPlan = function () use ($vpg): void {
            GpFees::$afterPlan = null;
            $this->store($vpg, '9100003', '2026-11-21T10:00:00Z', 1000);
            self::$db->exec("UPDATE gp_invoice SET spread_state = 'due'");
            self::$db->exec('DELETE FROM gp_due');
        };
        self::$db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE id = ?", [$id]);
        (new GpFees(self::$db))->settleMonth(self::sys(), 'vpg_online', '2026-11');
        // the plan without the late order is never marked done: planned again (it then holds the late order) or left due, never done beside an estimate
        $state = (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]);
        $estimates = (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate'");
        self::assertTrue($state === 'due' || ($estimates === 0 && (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'") === 100),
            "the late order is not in the first plan: never done beside its estimate ({$state}, {$estimates} estimates)");
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]));
        self::assertSame(100, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'"));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate'"));
        self::same($vpg, '2026-11-19', '2026-11-20', '2026-11-21');
    }

    public function testH2VapeBigBeforeItsFirstDayGetsNoEstimateAndItsShareGoesOnTheMainShop(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $vb = $this->site('vapebig', 'live');
        $this->on();
        $this->store($vb, '9200001', '2026-11-19T10:00:00Z', 5000);
        self::assertNull(self::row($vb, '9200001'), 'Vape Big\'s card fees start on its own first day (1 Dec): no estimate in November');
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        self::assertSame('51', (string) self::row($vpg, '9100001')['fee_pence']);
        // the invoice holds both shops' takings: no tick asked (CW's card takings count Vape Big's too)
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '100.00']))['id'];
        self::assertSame('50', (string) self::row($vpg, '9100001')['fee_pence'], 'Vape and Go\'s order: its share');
        self::assertSame([50, 50], [self::day($vpg, '2026-11-19')[0], self::day($vpg, '2026-11-30')[2]],
            'Vape Big\'s share on Vape and Go\'s "on no order" line, the month\'s last day');
        $real = (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', (int) $vpg->channelId, false);
        self::assertSame(100, $real[0]['gp'], 'Vape and Go\'s November carries the whole invoice to the penny');
        // another Vape Big order of the month: the month is spread again
        $this->store($vb, '9200002', '2026-11-20T10:00:00Z', 5000);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM gp_due WHERE month = '2026-11'"));
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame(['34', 66], [(string) self::row($vpg, '9100001')['fee_pence'], self::day($vpg, '2026-11-30')[2]],
            '100p over 5000 : 5000 : 5000 (33.33 each): the penny left to the earliest, Vape and Go\'s order');
        self::assertSame(100, (int) self::$db->value('SELECT spread_pence + other_pence FROM gp_invoice WHERE id = ?', [$id]));
        self::same($vpg, '2026-11-19', '2026-11-30');
        self::same($vb, '2026-11-19', '2026-11-20');
        // from Vape Big's own first day its card orders carry their fee
        $this->store($vb, '9200003', '2026-11-25T10:00:00Z', 1000);
        $this->set('profit.gp_shop_from_vapebig', '2026-11-25');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('vpg_online', (string) self::row($vb, '9200003')['account'], 'a shop\'s first day moved: its days gone over');
    }

    public function testH3AContendedGuardOfEitherShopMakesTheDayPassAndTheSpreadGiveWay(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $vb = $this->site('vapebig', 'live');
        $this->on();
        $this->set('profit.gp_shop_from_vapebig', '2026-11-01');
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $this->store($vb, '9200001', '2026-11-19T10:00:00Z', 5000);
        foreach ([$vb, $vpg] as $held) {
            $other = TestDb::connect();
            $other->exec('START TRANSACTION');
            ProfitDays::guard($other, (int) $held->channelId, ['2026-11-19']);
            try {
                $t = microtime(true);
                $s = (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-19');
                self::assertTrue($s['busy'], 'the held guard: the day gives way');
                self::assertLessThan(2.0, microtime(true) - $t, 'at once (its own short wait), never past a snapshot\'s 3 s');
                // the other shop's guard is not kept: a snapshot of it gets it at once
                $free = $held === $vb ? $vpg : $vb;
                $probe = TestDb::connect();
                $probe->exec('SET SESSION innodb_lock_wait_timeout = 1');
                $probe->exec('START TRANSACTION');
                ProfitDays::guard($probe, (int) $free->channelId, ['2026-11-19']);
                $probe->exec('ROLLBACK');
                $t = microtime(true);
                $id = (int) (self::$db->value("SELECT id FROM gp_invoice WHERE account = 'vpg_online' AND month = '2026-11' AND deleted_at IS NULL")
                    ?? (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '100.00']))['id']);
                self::assertLessThan(3.0, microtime(true) - $t);
                self::assertSame('due', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]), 'the spread gave way: still due');
            } finally {
                $other->exec('ROLLBACK');
            }
        }
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', (string) self::$db->value("SELECT spread_state FROM gp_invoice WHERE account = 'vpg_online'"));
        self::assertSame(100, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'"));
        self::same($vpg, '2026-11-19');
        self::same($vb, '2026-11-19');
    }

    public function testM6SwitchingOffClearsAnInvoicesChargeOnNoOrder(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $fees = new GpFees(self::$db);
        $fees->run(self::sys());
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '0', 'net' => '25.00', 'total' => '25.00']));
        self::assertSame([0, 0, 2500], self::day($vpg, '2026-11-30'));
        $this->set('profit.gp_from_vpg_online', '');
        $fees->run(self::sys());
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-30'), 'switched off: nothing of the account counts');
        self::assertSame(0, (int) self::$db->value('SELECT SUM(other_pence) FROM gp_invoice'));
        self::same($vpg, '2026-11-30');
    }

    public function testM7ABatchWithAnOldConfigurationNeverRestoresFees(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $batch = new OrderSnapshots(self::$db);
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 2999, 'globalpay', 1000, null, $batch);
        self::assertNotNull(self::row($vpg, '9100001'));
        $this->set('profit.gp_from_vpg_online', '');
        (new GpFees(self::$db))->run(self::sys());
        $this->store($vpg, '9100002', '2026-11-19T11:00:00Z', 2999, 'globalpay', 1000, null, $batch);
        self::assertNull(self::row($vpg, '9100002'), 'the batch reads the settings in each snapshot: switched off, no fee');
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-19'));
    }

    public function testM8AnOrderThatIsNoCardOrderNeverReadsTheCardFeeTable(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        self::$db->exec('RENAME TABLE gp_fee_order TO gp_fee_order_away');
        try {
            foreach (['pp' => '9100011', 'pphf' => '9100012', 'office' => '9100013', '' => '9100014'] as $gw => $ref) {
                $this->store($vpg, $ref, '2026-11-19T10:00:00Z', 2999, $gw);
            }
            $this->store($vpg, '9100011', '2026-11-19T10:00:00Z', 3999, 'pp', 1001);
        } finally {
            self::$db->exec('RENAME TABLE gp_fee_order_away TO gp_fee_order');
        }
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM sale_order WHERE channel_id = ?", [$vpg->channelId]), 'stored without the card fee table');
    }

    public function testM10AnInvoiceDatedLateStillMeetsItsExpense(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['invoice_date' => '2027-01-05']));
        $fees = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $e = static fn (string $net, string $date): array => ['shop' => (string) $vpg->channelId, 'supplier' => 'Global Payments', 'new_supplier' => '1', 'type' => (string) $fees,
            'invoice_date' => $date, 'invoice_no' => '', 'net' => $net, 'vat' => '0', 'note' => ''];
        self::assertSame('gp_invoice_same', self::code(static fn () => (new Expenses(self::$db))->add(self::sys(), $e('1.00', '2027-01-05'))), 'the invoice\'s own date');
        (new Expenses(self::$db))->add(self::sys(), $e('7.00', '2027-01-05'));
        self::assertCount(1, GpInvoices::doubtful(self::$db, '2026-11-01', '2027-12-31', null), 'flagged on the invoice\'s own date too');
    }

    public function testM11ASupplierTypedWithAnAccentIsStillGlobalPayments(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $fees = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $e = static fn (string $supplier, string $net, bool $new): array => ['shop' => (string) $vpg->channelId, 'supplier' => $supplier, 'new_supplier' => $new ? '1' : '',
            'type' => (string) $fees, 'invoice_date' => '2026-11-25', 'invoice_no' => '', 'net' => $net, 'vat' => '0', 'note' => ''];
        (new Expenses(self::$db))->add(self::sys(), $e('Global Payments', '3.00', true));
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice());
        self::assertSame('gp_invoice_same', self::code(static fn () => (new Expenses(self::$db))->add(self::sys(), $e('Glóbal Payments', '1.00', false))),
            'it resolves to the Global Payments supplier: the same invoice refused');
    }

    public function testM12ALowVolumeInvoiceSettlesAtItsFullCharge(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 10000);
        $r = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '100.00', 'net' => '25.00', 'total' => '25.00', 'use_rate' => '1']));
        self::assertFalse($r['rate_set'], 'a 25% rate is never the estimate rate (at most 10%)');
        self::assertSame('2500', (string) self::row($vpg, '9100001')['fee_pence'], 'the invoice settles at its full net charge');
        self::assertSame('"1.01"', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.gp_rate_vpg_online'"));
    }

    public function testM13AReSendGetsNoCardFee(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 3000);
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 3000, 'globalpay', 1000, '9100001');
        self::assertNull(self::row($vpg, '9100002'), 'a re-send is a replacement with no sale: no card fee');
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-20');
        self::assertNull(self::row($vpg, '9100002'));
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-20'));
    }

    public function testM14AnAbandonedRunIsMarkedFailedAndTheJobStopsAtItsDeadline(): void
    {
        $this->site('vapeandgo', 'live');
        (new GpFees(self::$db))->run(self::sys());
        $this->on();
        self::$db->exec("INSERT INTO gp_run (account, kind, actor, started_at) VALUES ('vpg_online', 'nightly', 'system:gp_fees', UTC_TIMESTAMP(6) - INTERVAL 2 HOUR)");
        $fees = new GpFees(self::$db, null, 0.0);
        $d = $fees->run(self::sys());
        self::assertSame(['failed', 'abandoned: its process stopped before it finished'], array_map('strval', array_values(self::$db->one("SELECT state, error FROM gp_run "
            . "WHERE kind = 'nightly'") ?? [])), 'the next run marks it failed');
        self::assertGreaterThan(0, $d['vpg_online']['left'], 'its deadline passed at once: the days are left for the next run');
        self::assertNull(self::$db->value("SELECT sig FROM gp_run WHERE kind = 'job' AND account = 'vpg_online' ORDER BY id DESC LIMIT 1"), 'not finished: its settings are gone over again');
        (new GpFees(self::$db))->run(self::sys());
        self::assertNotNull(self::$db->value("SELECT sig FROM gp_run WHERE kind = 'job' AND account = 'vpg_online' ORDER BY id DESC LIMIT 1"));
    }

    public function testL15ReDatingAnActualOrderInsideItsMonthSpreadsTheMonthAgain(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 1000);
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 1000);
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '20.00', 'net' => '0.01', 'total' => '0.01']));
        self::assertSame(['1', '0'], [(string) self::row($vpg, '9100001')['fee_pence'], (string) self::row($vpg, '9100002')['fee_pence']], 'the earlier order has the penny');
        $this->store($vpg, '9100001', '2026-11-21T10:00:00Z', 1000, 'globalpay', 1001);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM gp_due WHERE month = '2026-11'"), 'moved inside its month: queued');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame(['0', '1'], [(string) self::row($vpg, '9100001')['fee_pence'], (string) self::row($vpg, '9100002')['fee_pence']],
            'the penny follows the day order again');
    }
}
