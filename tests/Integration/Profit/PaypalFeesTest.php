<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Expenses\ExpenseViews;
use CW\Ops\AlertDispatch;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;

/**
 * PayPal's fees in CW's profit (CW-1172; docs/decisions.md PPF1-PPF22), from MADE-UP PayPal rows served by a fake PayPal (no network): each
 * kind counted where the owner said (a sale's fee on its order's paid day, a refund's fee back and a chargeback's money and fees on the order
 * on their own day, anything with no CW order on the shop's "not on an order" line, holds and bank moves nowhere, an unknown code shown and
 * never counted), a second run that changes nothing, an order reaching CW late, paid late or moved taking its fees along, a pending row
 * that comes good, a window that fails storing nothing of itself, the health alert, the Real profit line and the order's page; after every
 * step the kept days equal a rebuild from scratch.
 */
final class PaypalFeesTest extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo'];
    private const PP_NOW = '2026-11-25 05:20:00';

    private int $ef = 0;
    /** @var array<string, string> settings a test changed, put back after it */
    private array $settingsBefore = [];

    protected function tearDown(): void
    {
        foreach ($this->settingsBefore as $k => $v) {
            self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$v, $k]);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->settingsBefore[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [$json, $key]);
    }

    private static function fees(string $now = self::PP_NOW): PaypalFees
    {
        return new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')));
    }

    private static function api(FakePaypalHttp $http): PaypalApi
    {
        return new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
    }

    private static function system(): Caller
    {
        return Caller::system('paypal_test');
    }

    /** An order with one goods line, stored and worked out now (paid: its UK day counts). */
    private static function order(int $ch, string $ref, string $no, ?string $paid, int $version = 1): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-11-01T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1699, 'parcels' => []],
            'api');
    }

    /** @return array<string, int> "day|field" => pence: the PayPal figures kept on the store's days (zeros left out) */
    private static function days(int $ch): array
    {
        $out = [];
        foreach (self::$db->all('SELECT day, fee_cost, fee_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]) as $r) {
            foreach (['fee_cost', 'fee_other'] as $f) {
                if ((int) $r[$f] !== 0) {
                    $out[$r['day'] . '|' . $f] = (int) $r[$f];
                }
            }
        }
        return $out;
    }

    /** Every day of the store from 25 Oct to 30 Nov is what its orders and PayPal rows add up to (the rebuild check). */
    private static function assertDaysRebuild(int $ch): void
    {
        for ($d = '2026-10-25'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
            self::assertSame([], $diff, "day {$d}: the kept figures are what a rebuild works out");
        }
    }

    /** @return array<string, array<string, mixed>> the rows by PayPal id and code */
    private static function rows(): array
    {
        $out = [];
        foreach (self::$db->all('SELECT * FROM paypal_txn ORDER BY id') as $r) {
            $out[$r['txn_id'] . '|' . $r['event_code']] = $r;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the made-up month: every kind once */
    private static function month(): array
    {
        return [
            PaypalTx::sale('1A0000000000000A1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::row('1A0000000000000B1', 'T0005', '2026-11-04T09:01:00Z', '30.00', '-0.80', '1312002'),
            PaypalTx::refund('1A0000000000000A2', '2026-11-06T12:00:00Z', '-50.00', '0.20', '1312001'),
            PaypalTx::row('1A0000000000000B2', 'T1201', '2026-11-07T12:00:00Z', '-30.00', null, '1312002'),
            PaypalTx::row('1A0000000000000B3', 'T0106', '2026-11-07T12:05:00Z', '-14.00', null, '1312002'),
            PaypalTx::row('1A0000000000000B4', 'T0114', '2026-11-07T12:06:00Z', '-12.00', null, '1312002'),
            PaypalTx::sale('1A0000000000000C1', '2026-11-05T10:00:00Z', '20.00', '-0.60', '1312009'),
            PaypalTx::sale('1A0000000000000D1', '2026-11-05T11:00:00Z', '10.00', '-0.30', null),
            PaypalTx::sale('1A0000000000000D2', '2026-11-05T11:30:00Z', '12.00', '-0.40', null) + ['invoice_id' => 'faaeeb-3090', 'custom_field' => '3090'],
            PaypalTx::sale('1A0000000000000L1', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457'),
            PaypalTx::row('1A0000000000000E1', 'T1501', '2026-11-05T11:00:00Z', '-10.00', null, null, 'P'),
            PaypalTx::row('1A0000000000000E2', 'T0403', '2026-11-05T12:00:00Z', '-900.00', null),
            PaypalTx::row('1A0000000000000E3', 'T0107', '2026-11-05T13:00:00Z', '-1.00', null),
            PaypalTx::sale('1A0000000000000F1', '2026-11-08T10:00:00Z', '25.00', '-0.50', '1312004', 'P'),
            PaypalTx::sale('1A0000000000000G1', '2026-10-31T10:00:00Z', '40.00', '-0.99', '1312001'),
            PaypalTx::sale('1A0000000000000H1', '2026-11-08T10:00:00Z', '15.00', '-0.40', '1312003'),
            PaypalTx::row('1A0000000000000K1', 'T1110', '2026-11-09T10:00:00Z', '-20.00', null, '1312002', 'P'),
            PaypalTx::row('1A0000000000000K1', 'T1111', '2026-11-09T10:00:00Z', '20.00', null, '1312002', 'D'),
        ];
    }

    /** @return array{0: int, 1: FakePaypalHttp} the shop with its orders, and PayPal serving the month */
    private function shopWithOrders(): array
    {
        // Vape and Go switched on from 1 Nov (the go-live kit's step; 0080 leaves it empty)
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-01"');
        $ch = (int) $this->site('vapeandgo', 'live')->channelId;
        self::order($ch, '1001', '1312001', '2026-11-03T10:00:00Z');
        self::order($ch, '1002', '1312002', '2026-11-04T09:00:00Z');
        self::order($ch, '1003', '1312003', null);
        self::order($ch, '1004', '1312004', '2026-11-08T09:59:00Z');
        // Electrofag: the same PayPal account (the owner, 25 Nov 2026), its order numbers of 4 digits; its fees not switched on yet
        $this->ef = (int) $this->site('electrofag', 'live')->channelId;
        self::order($this->ef, '2001', '5457', '2026-11-06T08:59:00Z');
        return [$ch, new FakePaypalHttp(self::month())];
    }

    public function testEachKindCountsWhereTheOwnerSaid(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        $r = self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        self::assertSame([4, 18, 18, 0], [$r['windows'], $r['read'], $r['new'], $r['changed']]);
        $rows = self::rows();
        $where = static fn (string $k): array => [$rows[$k]['kind'], $rows[$k]['count_state'], $rows[$k]['match_state'], $rows[$k]['alloc_dest'], $rows[$k]['alloc_order_ref'],
            $rows[$k]['alloc_day'], (int) $rows[$k]['alloc_pence']];
        self::assertSame(['sale', 'counts', 'order', 'order', '1001', null, 120], $where('1A0000000000000A1|T0006'), 'a sale\'s fee on its order, on the order\'s day');
        self::assertSame(['sale', 'counts', 'order', 'order', '1002', null, 80], $where('1A0000000000000B1|T0005'));
        self::assertSame(['refund', 'counts', 'order', 'order', '1001', '2026-11-06', -20], $where('1A0000000000000A2|T1107'), 'the fee back on the order, on the refund\'s day');
        self::assertSame(['chargeback', 'counts', 'order', 'order', '1002', '2026-11-07', 3000], $where('1A0000000000000B2|T1201'), 'the money taken back');
        self::assertSame(['fee', 'counts', 'order', 'order', '1002', '2026-11-07', 1400], $where('1A0000000000000B3|T0106'), 'the chargeback fee');
        self::assertSame(['fee', 'counts', 'order', 'order', '1002', '2026-11-07', 1200], $where('1A0000000000000B4|T0114'), 'the dispute fee');
        self::assertSame(['sale', 'counts', 'no_order', 'shop', null, '2026-11-05', 60], $where('1A0000000000000C1|T0006'), 'a number no CW order has (yet)');
        self::assertSame(['sale', 'counts', 'no_number', 'shop', null, '2026-11-05', 30], $where('1A0000000000000D1|T0006'), 'no invoice: the main shop\'s line');
        self::assertSame(['sale', 'outside', null, null, null, null, 0], $where('1A0000000000000D2|T0006'), 'Hayati Vapes: kept, never counted');
        self::assertSame(['hayati', '3090'], [$rows['1A0000000000000D2|T0006']['form_shop'], $rows['1A0000000000000D2|T0006']['order_no']]);
        self::assertSame(['sale', 'before_start', null, null, null, null, 0], $where('1A0000000000000L1|T0006'), 'Electrofag not switched on: never on Vape and Go');
        self::assertSame([(string) $this->ef, 'electrofag'], [(string) $rows['1A0000000000000L1|T0006']['shop_channel'], $rows['1A0000000000000L1|T0006']['form_shop']]);
        self::assertSame(['ignore', 'not_counted', null, null, null, null, 0], $where('1A0000000000000E1|T1501'), 'a hold');
        self::assertSame(['ignore', 'not_counted', null, null, null, null, 0], $where('1A0000000000000E2|T0403'), 'money to the bank');
        self::assertSame(['unknown', 'look', null, null, null, null, 0], $where('1A0000000000000E3|T0107'), 'never guessed');
        self::assertSame('unknown_code', $rows['1A0000000000000E3|T0107']['look_reason']);
        self::assertSame(['sale', 'pending', null, null, null, null, 0], $where('1A0000000000000F1|T0006'), 'pending: read again');
        self::assertSame(['sale', 'before_start', null, null, null, null, 0], $where('1A0000000000000G1|T0006'), 'before the shop\'s first fee day');
        self::assertSame(['sale', 'counts', 'order', 'order', '1003', null, 40], $where('1A0000000000000H1|T0006'), 'on its order, not paid in CW yet');
        self::assertSame(['ignore', 'ignore'], [$rows['1A0000000000000K1|T1110']['kind'], $rows['1A0000000000000K1|T1111']['kind']], 'one PayPal id, two rows');
        // the days: Nov 3 the sale's fee, Nov 4 the other, Nov 5 the two on no order, Nov 6 the fee back, Nov 7 the chargeback with its fees;
        // the unpaid order's fee nowhere yet
        self::assertSame(['2026-11-03|fee_cost' => 120, '2026-11-04|fee_cost' => 80, '2026-11-05|fee_other' => 90, '2026-11-06|fee_cost' => -20,
            '2026-11-07|fee_cost' => 5600], self::days($ch));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'));
        self::assertDaysRebuild($ch);
        // nothing personal kept: no item text, no invoice as written
        $cols = array_map('strval', self::$db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paypal_txn'"));
        foreach (['transaction_subject', 'invoice_id', 'custom_field', 'payer', 'email', 'name'] as $c) {
            self::assertNotContains($c, $cols);
        }
        // ProfitViews: the totals say it; the order's page lists its rows
        $t = (new ProfitViews(self::$db))->totals('2026-11-01', '2026-11-30', $ch);
        self::assertSame([5780, 90, 5870], [$t['fee_cost'], $t['fee_other'], $t['fees']]);
        $o = (new ProfitViews(self::$db))->order($ch, '1002');
        self::assertSame([['T0005', null, 80], ['T1201', '2026-11-07', 3000], ['T0106', '2026-11-07', 1400], ['T0114', '2026-11-07', 1200]],
            array_map(static fn (array $l): array => [$l['code'], $l['counts_on'], $l['pence']], $o['paypal']));
    }

    public function testASecondRunChangesNothingAndARereadChangesInPlace(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        $days = self::days($ch);
        $hist = (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn_history');
        $r = self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        self::assertSame([18, 0, 0, 0], [$r['read'], $r['new'], $r['changed'], $r['allocated']], 'the same rows again: nothing new, changed or moved');
        self::assertSame($days, self::days($ch));
        self::assertSame($hist, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn_history'));
        self::assertSame(18, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn'));
        // PayPal says the pending sale is done now: the same row, changed in place with its history, its fee on its order's day
        $month = self::month();
        $month[13] = PaypalTx::sale('1A0000000000000F1', '2026-11-08T10:00:00Z', '25.00', '-0.50', '1312004', 'S');
        $http->set($month);
        $r = self::fees()->run(self::system(), self::api($http), '2026-11-02', '2026-11-24', 'nightly');
        self::assertSame([0, 1, 1], [$r['new'], $r['changed'], $r['allocated']]);
        $row = self::rows()['1A0000000000000F1|T0006'];
        self::assertSame(['S', 'counts', 'order', '1004', 50, 4], [$row['status'], $row['count_state'], $row['alloc_dest'], $row['alloc_order_ref'], (int) $row['alloc_pence'],
            (int) $row['version']]);
        // version 1 read; 2 decided pending; 3 PayPal says done; 4 counted on its order
        self::assertSame(['allocated', 'changed', 'allocated'], array_map('strval', self::$db->column('SELECT what FROM paypal_txn_history WHERE txn_row = ? ORDER BY version',
            [(int) $row['id']])));
        $h = self::$db->one("SELECT before_json, after_json FROM paypal_txn_history WHERE txn_row = ? AND what = 'changed'", [(int) $row['id']]);
        self::assertSame(['P', 'S'], [json_decode((string) $h['before_json'], true)['status'], json_decode((string) $h['after_json'], true)['status']]);
        self::assertSame(50, self::days($ch)['2026-11-08|fee_cost'] ?? null);
        self::assertSame(18, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn'), 'never a second row');
        self::assertDaysRebuild($ch);
    }

    public function testAnOrderReachingCwLatePaidLateOrMovedTakesItsFees(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        // the order of 1312009 reaches CW after its payment was read: the next run moves its fee from the shop's line onto it (the same day)
        self::order($ch, '1009', '1312009', '2026-11-05T09:59:00Z');
        self::assertSame(90, self::days($ch)['2026-11-05|fee_other'], 'not moved by the snapshot itself');
        $r = self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
        self::assertSame(1, $r['allocated']);
        self::assertSame(['order', '1009'], [self::rows()['1A0000000000000C1|T0006']['match_state'], self::rows()['1A0000000000000C1|T0006']['alloc_order_ref']]);
        self::assertSame([30, 60], [self::days($ch)['2026-11-05|fee_other'], self::days($ch)['2026-11-05|fee_cost']]);
        self::assertDaysRebuild($ch);
        // the unpaid order is paid on 9 Nov: its fee comes with it, no PayPal run needed
        self::order($ch, '1003', '1312003', '2026-11-09T08:00:00Z', 2);
        self::assertSame(40, self::days($ch)['2026-11-09|fee_cost']);
        // order 1001 moved to 2 Nov: its sale's fee goes along; its refund's fee back stays on the refund's day
        self::order($ch, '1001', '1312001', '2026-11-02T10:00:00Z', 2);
        $d = self::days($ch);
        self::assertSame([120, null, -20], [$d['2026-11-02|fee_cost'] ?? null, $d['2026-11-03|fee_cost'] ?? null, $d['2026-11-06|fee_cost']]);
        self::assertDaysRebuild($ch);
        // the switch-on day moved back to 31 Oct: the sale of 31 Oct counts on its order's day now (the next run)
        $this->setting('profit.paypal_from_vapeandgo', '"2026-10-31"');
        self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
        self::assertSame(['counts', '1001', 99], [self::rows()['1A0000000000000G1|T0006']['count_state'], self::rows()['1A0000000000000G1|T0006']['alloc_order_ref'],
            (int) self::rows()['1A0000000000000G1|T0006']['alloc_pence']]);
        self::assertSame(219, self::days($ch)['2026-11-02|fee_cost']);
        self::assertDaysRebuild($ch);
    }

    public function testAWindowThatFailsStoresNothingOfItselfAndTheNextRunFinishes(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        // the second window's page fails every try: the first window is stored, the run failed, the alert says so
        $http->fail = static fn (int $n, string $p, array $q): ?array => $p === '/v1/reporting/transactions' && str_starts_with($q['start_date'], '2026-11-07')
            ? ['status' => 503, 'body' => 'down'] : null;
        try {
            self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
            self::fail('the run fails');
        } catch (CwException $e) {
            self::assertSame('paypal_unreachable', $e->errorCode);
        }
        self::assertSame(['failed', 1], [(string) self::$db->value('SELECT state FROM paypal_run ORDER BY id DESC LIMIT 1'),
            (int) self::$db->value('SELECT windows FROM paypal_run ORDER BY id DESC LIMIT 1')]);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE day >= '2026-11-07'"), 'nothing of the failed window');
        self::assertSame(11, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn'), 'the first window (31 Oct - 6 Nov) is stored whole');
        self::assertSame(11, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'), 'its rows due: decided by the next run');
        self::assertSame([], self::days($ch), 'nothing counted before it is decided');
        $kinds = array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind');
        self::assertContains('paypal_failed', $kinds);
        self::assertContains('paypal_not_read', $kinds, 'no ok run yet');
        // PayPal answers again: the next run reads everything and decides the rows the failed one left
        $http->fail = null;
        self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'));
        self::assertSame(5600, self::days($ch)['2026-11-07|fee_cost']);
        $kinds = array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind');
        self::assertSame(['paypal_needs_look'], $kinds, 'only the unknown code is left to say');
        self::assertDaysRebuild($ch);
    }

    public function testTheHealthAlertTheRealProfitAndTheRefusals(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        $kinds = static fn (): array => array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind');
        self::assertSame(['paypal_not_read'], $kinds(), 'switched on, never read');
        self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        self::assertSame(['paypal_needs_look'], $kinds());
        $p = PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z'))[0];
        self::assertStringContainsString('1 PayPal rows', AlertDispatch::line($p));
        // the alarm at 50p: 5 Nov's 90p on no order is above it (within the re-read days of the 12th)
        $this->setting('profit.paypal_not_on_order_alarm', '"0.50"');
        self::assertSame(['paypal_needs_look', 'paypal_not_on_order'], array_column(PaypalFees::health(self::$db, new \DateTimeImmutable('2026-11-12T05:20:00Z')), 'kind'));
        // 27 hours later with no run: not read
        self::assertContains('paypal_not_read', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable('2026-11-26T08:30:00Z')), 'kind'));
        // the Real profit: Vape and Go's fees line is its PayPal money of the month
        $real = (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', $ch, false);
        self::assertSame([5870, '2026-11-01'], [$real[0]['fees'], $real[0]['fees_from']]);
        // not switched on: 0 and not said
        $this->setting('profit.paypal_from_vapeandgo', '""');
        $real = (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', $ch, false);
        self::assertSame([0, null], [$real[0]['fees'], $real[0]['fees_from']]);
        self::assertSame([], $kinds(), 'a shop not switched on is not watched');
        // a person never writes PayPal's rows; a key refused is a failed run the alert says
        try {
            self::fees()->run(Caller::staff(1), self::api($http), '2026-11-01', '2026-11-02', 'backfill');
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('staff_cannot', $e->errorCode);
        }
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-01"');
        self::fees()->refused('vapeandgo', 'the PayPal key file of vapeandgo is missing: nothing was read');
        self::assertContains('paypal_failed', $kinds());
    }

    public function testTheSharedAccountPutsEachShopsRowsOnItsOwnShop(): void
    {
        [$ch, $http] = $this->shopWithOrders();
        $month = self::month();
        $month[] = PaypalTx::sale('1A0000000000000L2', '2026-11-06T10:00:00Z', '30.00', '-0.90', '5999'); // an Electrofag number no CW order has
        $http->set($month);
        self::fees()->run(self::system(), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        $vpg = self::days($ch);
        self::assertSame([], self::days($this->ef), 'Electrofag not switched on: nothing counted, and nothing of it on Vape and Go');
        // Electrofag switched on from 1 Nov: the next run puts its rows on its own order and its own "not on an order" line
        $this->setting('profit.paypal_from_electrofag', '"2026-11-01"');
        $r = self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
        self::assertSame(2, $r['allocated']);
        $rows = self::rows();
        self::assertSame(['counts', 'order', 'order', '2001', 70], [$rows['1A0000000000000L1|T0006']['count_state'], $rows['1A0000000000000L1|T0006']['match_state'],
            $rows['1A0000000000000L1|T0006']['alloc_dest'], $rows['1A0000000000000L1|T0006']['alloc_order_ref'], (int) $rows['1A0000000000000L1|T0006']['alloc_pence']]);
        self::assertSame(['no_order', 'shop', (string) $this->ef], [$rows['1A0000000000000L2|T0006']['match_state'], $rows['1A0000000000000L2|T0006']['alloc_dest'],
            (string) $rows['1A0000000000000L2|T0006']['alloc_channel']]);
        self::assertSame(['2026-11-06|fee_cost' => 70, '2026-11-06|fee_other' => 90], self::days($this->ef));
        self::assertSame($vpg, self::days($ch), 'Vape and Go\'s days did not move');
        self::assertSame('outside', $rows['1A0000000000000D2|T0006']['count_state'], 'Hayati Vapes still never counted');
        self::assertDaysRebuild($ch);
        self::assertDaysRebuild($this->ef);
        // the account's shops changed (Electrofag taken off the list): every row decided again once; Electrofag's numbers are unrecognised now
        // and go on the main shop's line (a setting fault the "not on an order" alarm shows), and back when it is put back
        $this->setting('profit.paypal_shops_vapeandgo', '"vapeandgo,hayati"');
        self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
        self::assertSame([], self::days($this->ef));
        self::assertSame(90 + 70 + 90, self::days($ch)['2026-11-05|fee_other'] + self::days($ch)['2026-11-06|fee_other']);
        $this->setting('profit.paypal_shops_vapeandgo', '"vapeandgo,electrofag,hayati"');
        self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
        self::assertSame(['2026-11-06|fee_cost' => 70, '2026-11-06|fee_other' => 90], self::days($this->ef));
        self::assertSame($vpg, self::days($ch));
        self::assertDaysRebuild($ch);
        // a form that is not a pattern with one (part): the run is refused, nothing decided, the alert says it
        $this->setting('profit.paypal_form_hayati', '"faaeeb-[0-9]+"');
        try {
            self::fees()->run(self::system(), self::api($http), '2026-11-20', '2026-11-24', 'nightly');
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('paypal_bad_form', $e->errorCode);
        }
        self::assertContains('paypal_failed', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind'));
    }
}
