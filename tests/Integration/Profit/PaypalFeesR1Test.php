<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Expenses\ExpenseViews;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalInvariants;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1172 fix round 1 (the Codex review of dd93337, runs/codex_25nov/paypal_r1_final.md; the lead's decisions 1-8; docs/decisions.md
 * PPF23-): MADE-UP rows. A chargeback's money counts only beyond what CW already holds as a refund on the order (fees always); a shop's form
 * edited never moves money to another shop (a row losing its shop needs a look); refunds and disputes follow PayPal's reference to their sale;
 * a renumbered or doubled order is matched again; the decisions remember the forms they were made by; switching off needs no PayPal read and
 * the pages show what the days hold; the long outage is read back; the alarm per shop; the custom field kept only when it is an order number;
 * the "not on an order" line.
 */
final class PaypalFeesR1Test extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];
    private const PP_NOW = '2026-11-25 05:20:00';

    private int $vpg = 0;
    private int $ef = 0;
    /** @var array<string, string> */
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

    private static function readPp(FakePaypalHttp $http, string $from = '2026-10-31', string $to = '2026-11-24', string $now = self::PP_NOW): array
    {
        return self::fees($now)->run(Caller::system('paypal_test'), self::api($http), $from, $to, 'backfill');
    }

    private static function decideAll(): int
    {
        return self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
    }

    /** An order with one goods line (refunds as given, inc VAT, at order level). */
    private static function order(int $ch, string $ref, string $no, ?string $paid, int $version = 1, int $refundInc = 0): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-30T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => $refundInc, 'grand_inc' => 6499,
            'parcels' => []], 'api');
    }

    /** @return array<string, int> "day|field" => pence on the store's days (zeros left out) */
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

    private static function assertDaysRebuild(int $ch): void
    {
        for ($d = '2026-10-25'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
            self::assertSame([], $diff, "day {$d}: the kept figures are what a rebuild works out");
        }
    }

    /** @return array<string, mixed> the row of a PayPal id */
    private static function row(string $id): array
    {
        return (array) self::$db->one('SELECT * FROM paypal_txn WHERE txn_id = ? ORDER BY id LIMIT 1', [$id]);
    }

    /** Vape and Go (orders 1001-1003) and Electrofag (orders 2001, 2002), both switched on from 1 Nov. */
    private function shops(): void
    {
        $this->vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $this->ef = (int) $this->site('electrofag', 'live')->channelId;
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-01"');
        $this->setting('profit.paypal_merchant_vapeandgo', '"TESTACCOUNT"');
        $this->setting('profit.paypal_from_electrofag', '"2026-11-01"');
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z');
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z');
        self::order($this->vpg, '1003', '1312003', '2026-11-05T09:00:00Z');
        self::order($this->ef, '2001', '5457', '2026-11-06T08:00:00Z');
        self::order($this->ef, '2002', '5458', '2026-11-06T08:30:00Z');
    }

    public function testAChargebacksMoneyCountsOnlyBeyondWhatCwHoldsAsARefund(): void
    {
        $this->shops();
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 2, 1000); // the shop refunded £10 of it in its admin
        $http = new FakePaypalHttp([
            PaypalTx::sale('2A0000000000000B1', '2026-11-04T09:01:00Z', '64.99', '-1.50', '1312002'),
            PaypalTx::row('2A0000000000000B2', 'T1201', '2026-11-07T12:00:00Z', '-30.00', null, '1312002'),
            PaypalTx::row('2A0000000000000B3', 'T0106', '2026-11-07T12:05:00Z', '-14.00', null, '1312002'),
        ]);
        self::readPp($http);
        $cb = self::row('2A0000000000000B2');
        self::assertSame([3000, 1000, 2000, 1000], [(int) $cb['cost_pence'], (int) $cb['refund_offset_pence'], (int) $cb['alloc_pence'], (int) $cb['refund_seen_pence']],
            'the £30 taken back: £10 of it CW holds as a refund already, £20 counts');
        self::assertSame(1400, (int) self::row('2A0000000000000B3')['alloc_pence'], 'the chargeback fee always counts');
        self::assertSame(['2026-11-04|fee_cost' => 150, '2026-11-07|fee_cost' => 3400], self::days($this->vpg));
        // the shop refunds the rest later: the next run takes the whole £30 off (only the fee is left)
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 3, 3000);
        self::readPp($http, '2026-11-24', '2026-11-24');
        $cb = self::row('2A0000000000000B2');
        self::assertSame([3000, 3000, 0, null], [(int) $cb['refund_offset_pence'], (int) $cb['refund_seen_pence'], (int) $cb['alloc_pence'], $cb['alloc_dest']]);
        self::assertSame(['2026-11-04|fee_cost' => 150, '2026-11-07|fee_cost' => 1400], self::days($this->vpg));
        self::assertDaysRebuild($this->vpg);
    }

    public function testAShopsFormEditedNeverMovesMoneyToAnotherShop(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([
            PaypalTx::sale('2A0000000000000E1', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457'),
            PaypalTx::sale('2A0000000000000H1', '2026-11-05T11:30:00Z', '12.00', '-0.40', null) + ['invoice_id' => 'faaeeb-3090', 'custom_field' => '3090'],
            PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001'),
        ]);
        self::readPp($http);
        $vpg = self::days($this->vpg);
        self::assertSame(['2026-11-06|fee_cost' => 70], self::days($this->ef));
        // Hayati Vapes and Electrofag taken off the account's shops: their rows need a look, never Vape and Go's line
        $this->setting('profit.paypal_shops_vapeandgo', '"vapeandgo"');
        self::decideAll();
        self::assertSame(['look', 'form_lost', 'electrofag'], [self::row('2A0000000000000E1')['count_state'], self::row('2A0000000000000E1')['look_reason'],
            self::row('2A0000000000000E1')['form_shop']]);
        self::assertSame(['look', 'form_lost'], [self::row('2A0000000000000H1')['count_state'], self::row('2A0000000000000H1')['look_reason']]);
        self::assertSame($vpg, self::days($this->vpg), 'nothing moved to Vape and Go');
        self::assertSame([], self::days($this->ef));
        // two shops' forms for one invoice: a row first read so needs a look, never a guess; a row whose shop was captured before keeps it (the
        // evidence of the forms it was read by, final review a7, PPF36), read again or not
        $this->setting('profit.paypal_shops_vapeandgo', '"vapeandgo,electrofag,hayati"');
        $this->setting('profit.paypal_form_hayati', '"^([0-9]{7})-[0-9]{10}$"');
        $http->set([PaypalTx::sale('2A0000000000000E1', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457'),
            PaypalTx::sale('2A0000000000000H1', '2026-11-05T11:30:00Z', '12.00', '-0.40', null) + ['invoice_id' => 'faaeeb-3090', 'custom_field' => '3090'],
            PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::sale('2A0000000000000V2', '2026-11-04T10:01:00Z', '30.00', '-0.90', '1312002')]);
        self::readPp($http);
        self::assertSame(['look', 'form_overlap'], [self::row('2A0000000000000V2')['count_state'], self::row('2A0000000000000V2')['look_reason']]);
        self::assertSame(['counts', '1001', 'vapeandgo'], [self::row('2A0000000000000V1')['count_state'], self::row('2A0000000000000V1')['match_order_ref'],
            self::row('2A0000000000000V1')['invoice_shop']]);
        self::assertSame($vpg, self::days($this->vpg), 'the new row of two forms counts nowhere');
        self::assertSame(['2026-11-06|fee_cost' => 70], self::days($this->ef), 'Electrofag back on its order');
        self::assertContains('paypal_needs_look', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind'));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    public function testRefundsAndDisputesFollowPaypalsReferenceToTheirSale(): void
    {
        $this->shops();
        $rows = [
            PaypalTx::sale('2A0000000000000E1', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457'),
            // a chargeback with no invoice, its fee referring to the chargeback: both on Electrofag's order through the references
            PaypalTx::row('2A0000000000000E2', 'T1201', '2026-11-08T12:00:00Z', '-20.00', null, null, 'S', ['paypal_reference_id' => '2A0000000000000E1']),
            PaypalTx::row('2A0000000000000E3', 'T0106', '2026-11-08T12:05:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '2A0000000000000E2']),
            // a refund whose invoice says another shop's number than its sale: a look
            ['paypal_reference_id' => '2A0000000000000E1'] + PaypalTx::refund('2A0000000000000E4', '2026-11-09T12:00:00Z', '-5.00', '0.10', '1312001'),
        ];
        $http = new FakePaypalHttp($rows);
        self::readPp($http);
        self::assertSame(['order', '2001', 'reference'], [self::row('2A0000000000000E2')['alloc_dest'], self::row('2A0000000000000E2')['alloc_order_ref'],
            self::row('2A0000000000000E2')['matched_by']]);
        self::assertSame(['order', '2001', 1400], [self::row('2A0000000000000E3')['alloc_dest'], self::row('2A0000000000000E3')['alloc_order_ref'],
            (int) self::row('2A0000000000000E3')['alloc_pence']]);
        self::assertSame(['look', 'ref_conflict'], [self::row('2A0000000000000E4')['count_state'], self::row('2A0000000000000E4')['look_reason']]);
        self::assertSame(['2026-11-06|fee_cost' => 70, '2026-11-08|fee_cost' => 3400], self::days($this->ef));
        // a refund read before its sale (its window first), with no invoice of its own: it waits for a look, never on Vape and Go's line (final
        // review a6); the sale read later takes it to its own order
        $refund = ['paypal_reference_id' => '2A0000000000000F1'] + PaypalTx::refund('2A0000000000000F2', '2026-11-12T12:00:00Z', '-5.00', '0.10', null);
        $http->set([...$rows, $refund]);
        self::readPp($http, '2026-11-12', '2026-11-12');
        self::assertSame(['look', 'ref_missing', null], [self::row('2A0000000000000F2')['count_state'], self::row('2A0000000000000F2')['look_reason'],
            self::row('2A0000000000000F2')['alloc_dest']]);
        $http->set([...$rows, $refund, PaypalTx::sale('2A0000000000000F1', '2026-11-06T09:30:00Z', '20.00', '-0.70', '5458')]);
        self::readPp($http, '2026-11-06', '2026-11-06');
        self::assertSame(['order', '2002', (string) $this->ef], [self::row('2A0000000000000F2')['alloc_dest'], self::row('2A0000000000000F2')['alloc_order_ref'],
            (string) self::row('2A0000000000000F2')['alloc_channel']]);
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    public function testARenumberedOrDoubledOrderIsMatchedAgain(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([
            PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::sale('2A0000000000000V2', '2026-11-04T09:01:00Z', '64.99', '-1.50', '1312002'),
        ]);
        self::readPp($http);
        self::order($this->vpg, '1001', '1312901', '2026-11-03T10:00:00Z', 2); // renumbered
        self::order($this->vpg, '1009', '1312002', '2026-11-04T10:00:00Z');    // a second order with the number
        self::readPp($http, '2026-11-24', '2026-11-24');
        self::assertSame(['no_order', 'shop'], [self::row('2A0000000000000V1')['match_state'], self::row('2A0000000000000V1')['alloc_dest']]);
        self::assertSame(['ambiguous', 'shop'], [self::row('2A0000000000000V2')['match_state'], self::row('2A0000000000000V2')['alloc_dest']]);
        self::assertDaysRebuild($this->vpg);
    }

    public function testDecisionsRememberTheFormsTheyWereMadeBy(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000E1', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457')]);
        self::readPp($http);
        // B (Electrofag's numbers of 5 digits only) decided without a PayPal read (--decide): decided again by B, its shop as captured (a form
        // edit takes effect at a row's next read; the evidence is kept: final review a7), then A put back: decided again by A
        $sigA = (string) self::row('2A0000000000000E1')['decided_sig'];
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{5})-[0-9]{10}$"');
        self::decideAll();
        self::assertSame(['counts', '2001'], [self::row('2A0000000000000E1')['count_state'], self::row('2A0000000000000E1')['alloc_order_ref']]);
        self::assertNotSame($sigA, (string) self::row('2A0000000000000E1')['decided_sig']);
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,6})-[0-9]{10}$"');
        self::decideAll();
        self::assertSame(['counts', '2001', $sigA], [self::row('2A0000000000000E1')['count_state'], self::row('2A0000000000000E1')['alloc_order_ref'],
            (string) self::row('2A0000000000000E1')['decided_sig']]);
        self::assertSame(['2026-11-06|fee_cost' => 70], self::days($this->ef));
    }

    public function testSwitchingOffNeedsNoReadAndThePagesShowWhatTheDaysHold(): void
    {
        $this->shops();
        $this->setting('profit.real_from_vapeandgo', '"2026-10-01"');
        self::order($this->vpg, '1004', '1312004', '2026-10-31T10:00:00Z'); // paid 31 Oct; its PayPal sale read on 1 Nov
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000V4', '2026-11-01T10:00:00Z', '30.00', '-0.90', '1312004'),
            PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')]);
        self::readPp($http);
        $views = new ExpenseViews(self::$db);
        $fee = static fn (string $a, string $b): int => (int) $views->realProfit($a, $b, null, false)[0]['fees'];
        self::assertSame($fee('2026-10-01', '2026-10-31') + $fee('2026-11-01', '2026-11-30'), $fee('2026-10-01', '2026-11-30'), 'the months add up');
        self::assertSame(90, $fee('2026-10-01', '2026-10-31'), 'the sale fee follows its order to 31 Oct');
        // Vape and Go switched off: the page shows what the days hold until the local decision takes it off (no PayPal read needed)
        $this->setting('profit.paypal_from_vapeandgo', '""');
        self::assertSame(210, $fee('2026-10-01', '2026-11-30'));
        self::decideAll();
        self::assertSame(0, $fee('2026-10-01', '2026-11-30'));
        self::assertSame([], self::days($this->vpg));
    }

    public function testALongOutageIsReadBackAndTheAlertWaitsForIt(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000V1', '2026-11-01T10:01:00Z', '50.00', '-1.20', '1312001')]);
        self::readPp($http, '2026-11-01', '2026-11-01', '2026-11-01 12:00:00');
        // an old-style run of the last 7 days only, on 12 Nov: the days 2-4 Nov were never read
        $runId = self::$db->insert("INSERT INTO paypal_run (account, channel_id, kind, from_day, to_day, state, actor, started_at, finished_at) "
            . "VALUES ('vapeandgo', ?, 'nightly', '2026-11-05', '2026-11-12', 'ok', 'system:paypal_test', '2026-11-12 05:20:00', '2026-11-12 05:21:00')", [$this->vpg]);
        self::assertGreaterThan(0, $runId);
        self::assertSame('2026-11-01', self::fees('2026-11-12 05:20:00')->nightlyFrom('vapeandgo'),
            'read back from the last day covered without a gap (1 Nov, never before the first fee day)');
        self::assertContains('paypal_not_read', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable('2026-11-12T06:00:00Z')), 'kind'),
            'a gap (2-4 Nov) keeps the alert');
    }

    public function testTheNotOnAnOrderAlarmIsPerShopAndItsSettingInPence(): void
    {
        $this->shops();
        $this->setting('profit.paypal_not_on_order_alarm', '"1.00"');
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000V9', '2026-11-20T10:00:00Z', '30.00', '-0.90', '1312999'),
            PaypalTx::sale('2A0000000000000E9', '2026-11-20T10:00:00Z', '30.00', '-0.60', '5999')]);
        self::readPp($http);
        self::assertNotContains('paypal_not_on_order', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind'),
            '90p in one shop and 60p in the other: neither above £1');
        try {
            (new Settings(self::$db))->checkRule('profit.paypal_not_on_order_alarm', '5.005');
            self::fail('a third decimal is refused');
        } catch (CwException $e) {
            self::assertSame('bad_value', $e->errorCode);
        }
    }

    public function testOnlyOrderNumbersAreKeptAndDueRowsAreTimedFromWhenTheyBecameDue(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', null)
            + ['invoice_id' => '1312001-1763700000', 'custom_field' => '07700900123']]);
        self::readPp($http);
        $r = self::row('2A0000000000000V1');
        self::assertSame(['order', 'vapeandgo', '1312001', 'dropped', null], [$r['invoice_state'], $r['invoice_shop'], $r['invoice_no'], $r['custom_state'], $r['custom_no']]);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn_history WHERE CAST(after_json AS CHAR) LIKE '%07700900123%' OR CAST(after_json AS CHAR) LIKE '%1763700000%'"));
        // made due long after it was read (a form change): never "due for 26 hours" at once
        self::$db->exec("UPDATE paypal_txn SET changed_at = '2026-10-01 00:00:00' WHERE txn_id = '2A0000000000000V1'");
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,5})-[0-9]{10}$"');
        self::fees()->rematch('vapeandgo');
        self::assertSame(1, (int) self::$db->value('SELECT due FROM paypal_txn WHERE txn_id = ?', ['2A0000000000000V1']));
        self::assertSame([], array_values(array_filter(PaypalInvariants::check(self::$db), static fn (string $l): bool => str_starts_with($l, 'PP4'))));
        self::decideAll();
    }

    public function testTheRealProfitShowsWhatIsNotOnAnOrder(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000V1', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::sale('2A0000000000000V9', '2026-11-20T10:00:00Z', '30.00', '-0.90', null)]);
        self::readPp($http);
        $r = (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', $this->vpg, false)[0];
        self::assertSame([210, 90], [$r['fees'], $r['fees_other']]);
    }

    public function testElectrofagCountsFromItsFirstDayOnly(): void
    {
        // its first fee day (1 Dec on production: the kit sets it; here 20 Nov, as CW takes no order paid in the future): the day before out,
        // the day itself in, by the UK day
        $this->shops();
        $this->setting('profit.paypal_from_electrofag', '"2026-11-20"');
        self::order($this->ef, '2003', '5600', '2026-11-19T23:00:00Z');
        self::order($this->ef, '2004', '5601', '2026-11-20T00:10:00Z');
        $http = new FakePaypalHttp([PaypalTx::sale('2A0000000000000N1', '2026-11-19T23:59:00Z', '10.00', '-0.30', '5600'),
            PaypalTx::sale('2A0000000000000N2', '2026-11-20T00:15:00Z', '10.00', '-0.40', '5601')]);
        self::readPp($http, '2026-11-19', '2026-11-20');
        self::assertSame(['before_start', 'counts'], [self::row('2A0000000000000N1')['count_state'], self::row('2A0000000000000N2')['count_state']],
            'the day before Electrofag\'s first fee day out, the day itself counted');
        self::assertSame(['2026-11-20|fee_cost' => 40], self::days($this->ef));
    }
}
