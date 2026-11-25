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
 * CW-1172 final review (Codex astra on 5f197ca, runs/codex_25nov/paypal_final_astra_final.md; docs/decisions.md PPF34-): MADE-UP rows. The
 * chargeback offset: only countable chargebacks use CW's refunds, and only the refunds CW counts that PayPal did not pay out as refunds;
 * a chargeback moved takes the offset back to its old order. Children follow a corrected sale; an unresolved parent holds its child. The
 * evidence of a shop's form is kept across rereads; the main shop is pinned; an older PayPal version never undoes a newer one. The merchant
 * and live host checked at every run. Coverage only with fresh data and kept window by window. Status checked before ignore.
 *
 * (Round 1's header follows.)
 * CW-1172 fix round 1 (the Codex review of dd93337, runs/codex_25nov/paypal_r1_final.md; the lead's decisions 1-8; docs/decisions.md
 * PPF23-): MADE-UP rows. A chargeback's money counts only beyond what CW already holds as a refund on the order (fees always); a shop's form
 * edited never moves money to another shop (a row losing its shop needs a look); refunds and disputes follow PayPal's reference to their sale;
 * a renumbered or doubled order is matched again; the decisions remember the forms they were made by; switching off needs no PayPal read and
 * the pages show what the days hold; the long outage is read back; the alarm per shop; the custom field kept only when it is an order number;
 * the "not on an order" line.
 */
final class PaypalFeesR2Test extends StockTestCase
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
        $this->setting('profit.paypal_from_electrofag', '"2026-11-01"');
        $this->setting('profit.paypal_merchant_vapeandgo', '"TESTACCOUNT"');
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z');
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z');
        self::order($this->vpg, '1003', '1312003', '2026-11-05T09:00:00Z');
        self::order($this->ef, '2001', '5457', '2026-11-06T08:00:00Z');
        self::order($this->ef, '2002', '5458', '2026-11-06T08:30:00Z');
    }


    private static function cb(string $id, string $at, string $amount, ?string $no, string $status = 'S', array $over = []): array
    {
        return PaypalTx::row($id, 'T1201', $at, $amount, null, $no, $status, $over);
    }

    public function testOnlyCountableChargebacksAreCheckedAgainstTheRefundsAndPaypalsOwnRefundsCoverThem(): void
    {
        // round 3 (the lead's decision a3, PPF43): a refund in CW not on PayPal is never taken as the chargeback's own money (a look instead)
        $this->shops();
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 2, 3000);
        // a denied chargeback first, then a settled one, on an order with £30 refunded in CW: the denied one counts nothing, the settled one
        // needs a look (a1)
        $http = new FakePaypalHttp([self::cb('3A00000000000CB1', '2026-11-06T12:00:00Z', '-30.00', '1312002', 'D'),
            self::cb('3A00000000000CB2', '2026-11-07T12:00:00Z', '-30.00', '1312002')]);
        self::readPp($http);
        self::assertSame(['not_counted', 0], [self::row('3A00000000000CB1')['count_state'], (int) self::row('3A00000000000CB1')['refund_offset_pence']]);
        self::assertSame(['look', 'refund_not_on_paypal', 0], [self::row('3A00000000000CB2')['count_state'], self::row('3A00000000000CB2')['look_reason'],
            (int) self::row('3A00000000000CB2')['alloc_pence']]);
        // a £20 refund PayPal paid out (T1107) that CW holds, then an £80 chargeback: both are real money out, the chargeback counts whole (a3)
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 2000);
        $http->set([PaypalTx::refund('3A00000000000RF1', '2026-11-05T12:00:00Z', '-20.00', null, '1312001'),
            self::cb('3A00000000000CB3', '2026-11-08T12:00:00Z', '-80.00', '1312001')]);
        self::readPp($http);
        self::assertSame(['counts', 0, 8000], [self::row('3A00000000000CB3')['count_state'], (int) self::row('3A00000000000CB3')['refund_offset_pence'],
            (int) self::row('3A00000000000CB3')['alloc_pence']]);
        // an order not paid in CW counts none of its refunds: the chargeback counts whole; paid later, its £30 refund counts and is not on
        // PayPal: decided again, a look (a4)
        self::order($this->vpg, '1003', '1312003', null, 2, 3000);
        $http->set([self::cb('3A00000000000CB4', '2026-11-09T12:00:00Z', '-30.00', '1312003')]);
        self::readPp($http);
        self::assertSame(['counts', 3000], [self::row('3A00000000000CB4')['count_state'], (int) self::row('3A00000000000CB4')['alloc_pence']]);
        self::order($this->vpg, '1003', '1312003', '2026-11-05T09:00:00Z', 3, 3000);
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal', 3000], [self::row('3A00000000000CB4')['count_state'], self::row('3A00000000000CB4')['look_reason'],
            (int) self::row('3A00000000000CB4')['refund_seen_pence']]);
        self::assertDaysRebuild($this->vpg);
    }

    public function testAChargebackMovedIsDecidedOnItsNewOrderAndItsOldOrdersOtherStaysChecked(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        $rows = [self::cb('3A00000000000CB1', '2026-11-07T12:00:00Z', '-30.00', '1312001', 'S', ['transaction_updated_date' => '2026-11-07T12:00:00Z']),
            self::cb('3A00000000000CB2', '2026-11-08T12:00:00Z', '-30.00', '1312001')];
        $http = new FakePaypalHttp($rows);
        self::readPp($http);
        self::assertSame(['look', 'look'], [self::row('3A00000000000CB1')['count_state'], self::row('3A00000000000CB2')['count_state']], 'the £30 refund in CW is not on PayPal');
        // PayPal corrects the first one to order 1312002 (a newer version; no refund there): it counts whole there; the second stays a look
        $rows[0] = self::cb('3A00000000000CB1', '2026-11-07T12:00:00Z', '-30.00', '1312002', 'S', ['transaction_updated_date' => '2026-11-09T12:00:00Z']);
        $http->set($rows);
        self::readPp($http);
        self::assertSame(['counts', '1002', 3000], [self::row('3A00000000000CB1')['count_state'], self::row('3A00000000000CB1')['match_order_ref'],
            (int) self::row('3A00000000000CB1')['alloc_pence']]);
        self::assertSame(['look', 'refund_not_on_paypal'], [self::row('3A00000000000CB2')['count_state'], self::row('3A00000000000CB2')['look_reason']]);
        // PayPal's own £30 refund of 1312001 read: CW's refund is PayPal's, the second counts whole
        $http->set([...$rows, PaypalTx::refund('3A00000000000RF1', '2026-11-06T12:00:00Z', '-30.00', '0.50', '1312001')]);
        self::readPp($http);
        self::assertSame(['counts', 3000], [self::row('3A00000000000CB2')['count_state'], (int) self::row('3A00000000000CB2')['alloc_pence']]);
        self::assertDaysRebuild($this->vpg);
    }

    public function testChildrenFollowACorrectedSaleAndAnUnresolvedParentHoldsThem(): void
    {
        $this->shops();
        $sale = PaypalTx::sale('3A00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $fee = PaypalTx::row('3A00000000000F01', 'T0114', '2026-11-07T12:00:00Z', '-12.00', null, null, 'S', ['paypal_reference_id' => '3A00000000000S01']);
        $http = new FakePaypalHttp([$sale, $fee]);
        self::readPp($http);
        self::assertSame(['1001', (string) $this->vpg], [self::row('3A00000000000F01')['match_order_ref'], (string) self::row('3A00000000000F01')['alloc_channel']]);
        // the sale corrected to Electrofag's order 5457: its dispute fee goes with it (a5)
        $http->set([['invoice_id' => '5457-1763700000', 'custom_field' => '5457-1763700000', 'transaction_updated_date' => '2026-11-10T10:00:00Z'] + $sale, $fee]);
        self::readPp($http);
        self::assertSame(['2001', (string) $this->ef], [self::row('3A00000000000S01')['match_order_ref'], (string) self::row('3A00000000000S01')['alloc_channel']]);
        self::assertSame(['2001', (string) $this->ef], [self::row('3A00000000000F01')['match_order_ref'], (string) self::row('3A00000000000F01')['alloc_channel']]);
        // a chargeback whose reference and invoice disagree needs a look, and so does its fee with no invoice; a fee referring to a row CW does
        // not hold, with no invoice: a look too, never Vape and Go's line (a6)
        $vpgBefore = self::days($this->vpg);
        $http->set([['invoice_id' => '5457-1763700000', 'custom_field' => '5457-1763700000', 'transaction_updated_date' => '2026-11-10T10:00:00Z'] + $sale,
            self::cb('3A00000000000CB1', '2026-11-08T12:00:00Z', '-20.00', '1312002', 'S', ['paypal_reference_id' => '3A00000000000S01']),
            PaypalTx::row('3A00000000000F02', 'T0106', '2026-11-08T12:05:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '3A00000000000CB1']),
            PaypalTx::row('3A00000000000F03', 'T0106', '2026-11-08T12:06:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '3A0000000000NOPE'])]);
        self::readPp($http);
        self::assertSame(['look', 'ref_conflict'], [self::row('3A00000000000CB1')['count_state'], self::row('3A00000000000CB1')['look_reason']]);
        self::assertSame(['look', 'ref_unresolved'], [self::row('3A00000000000F02')['count_state'], self::row('3A00000000000F02')['look_reason']]);
        self::assertSame(['look', 'ref_missing'], [self::row('3A00000000000F03')['count_state'], self::row('3A00000000000F03')['look_reason']]);
        self::assertSame($vpgBefore, self::days($this->vpg));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    public function testTheEvidenceOfAFormIsKeptAcrossRereadsAndRecoveredWhenTheFormComesBack(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('3A00000000000E01', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457')]);
        self::readPp($http);
        // Electrofag's form narrowed to 5 digits, the same row read again: it keeps its capture as evidence (a7), with the forms that made it,
        // and is held until a read under the forms of now gives it (round 4, H3: never its old shop by guess)
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{5})-[0-9]{10}$"');
        $http->set([PaypalTx::sale('3A00000000000E01', '2026-11-06T09:00:00Z', '20.00', '-0.70', '5457'),
            PaypalTx::sale('3A00000000000E02', '2026-11-06T09:30:00Z', '20.00', '-0.70', '5458')]);
        self::readPp($http);
        self::assertSame(['look', 'form_unread', 'order', 'electrofag', '5457'], [self::row('3A00000000000E01')['count_state'], self::row('3A00000000000E01')['look_reason'],
            self::row('3A00000000000E01')['invoice_state'], self::row('3A00000000000E01')['invoice_shop'], self::row('3A00000000000E01')['invoice_no']]);
        // a row first read while the form was narrowed: its invoice is not kept (only that there was one); the form put back, the nightly reads
        // its day again and takes it to its order
        self::assertSame(['dropped', null, 'shop'], [self::row('3A00000000000E02')['invoice_state'], self::row('3A00000000000E02')['invoice_no'],
            self::row('3A00000000000E02')['alloc_dest']]);
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,6})-[0-9]{10}$"');
        $from = self::fees()->nightlyFrom('vapeandgo');
        self::assertSame([['2026-11-06', '2026-11-06']], self::fees()->nightlyExtra('vapeandgo', $from), 'the days read under other forms are read again, in their own window');
        self::fees()->run(Caller::system('paypal_test'), self::api($http), $from, '2026-11-24', 'nightly', null, self::fees()->nightlyExtra('vapeandgo', $from));
        self::assertSame(['order', '2002', '5458'], [self::row('3A00000000000E02')['alloc_dest'], self::row('3A00000000000E02')['match_order_ref'],
            self::row('3A00000000000E02')['invoice_no']]);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paypal_txn' "
            . "AND COLUMN_NAME IN ('invoice_ref', 'custom_ref')"), 'no invoice or custom text kept: only the order number a form captured (e1)');
    }

    public function testTheMainShopIsPinnedAndAnOlderVersionNeverUndoesANewer(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', null)]);
        self::readPp($http);
        $vpg = self::days($this->vpg);
        // the account's shops reordered (Electrofag first): refused before any money moves (a8)
        $this->setting('profit.paypal_shops_vapeandgo', '"electrofag,vapeandgo,hayati"');
        try {
            self::decideAll();
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('paypal_bad_shops', $e->errorCode);
        }
        self::assertSame($vpg, self::days($this->vpg));
        self::assertSame([], self::days($this->ef));
        $this->setting('profit.paypal_shops_vapeandgo', '"vapeandgo,electrofag,hayati"');
        // an older version (09:00, pending) after the settled one of 12:00: ignored (a9)
        $http->set([['transaction_updated_date' => '2026-11-03T09:00:00Z'] + PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', null, 'P')]);
        self::readPp($http);
        self::assertSame(['S', 'counts', 120], [self::row('3A00000000000V01')['status'], self::row('3A00000000000V01')['count_state'],
            (int) self::row('3A00000000000V01')['alloc_pence']]);
        // the same time, other facts: a look until a newer version says which
        $http->set([['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.90', null)]);
        self::readPp($http);
        self::assertSame(['look', 'pp_conflict', -120], [self::row('3A00000000000V01')['count_state'], self::row('3A00000000000V01')['look_reason'],
            (int) self::row('3A00000000000V01')['fee_pence']]);
        $http->set([['transaction_updated_date' => '2026-11-03T13:00:00Z'] + PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.90', null)]);
        self::readPp($http);
        self::assertSame(['counts', 190], [self::row('3A00000000000V01')['count_state'], (int) self::row('3A00000000000V01')['alloc_pence']]);
    }

    public function testTheMerchantAndTheLiveHostAreCheckedAtEveryRunAndARefusalIsAFailedRun(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')]);
        self::readPp($http);
        // the key names another merchant than CW's setting (b1)
        $this->setting('profit.paypal_merchant_vapeandgo', '"OTHERMERCHANT1"');
        try {
            self::readPp($http);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('paypal_key_refused', $e->errorCode);
        }
        self::assertContains('paypal_failed', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind'), 'said at once (b2)');
        // a sandbox key where the live host is required
        $this->setting('profit.paypal_merchant_vapeandgo', '"TESTACCOUNT"');
        try {
            self::fees()->run(Caller::system('paypal_test'), new PaypalApi(PaypalKey::fromArray(['api_base' => 'https://api-m.sandbox.paypal.com'] + self::KEY, 'vapeandgo'),
                $http, static function (int $s): void {
                }), '2026-11-01', '2026-11-02', 'backfill');
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('paypal_key_refused', $e->errorCode);
        }
        self::assertSame('failed', (string) self::$db->value('SELECT state FROM paypal_run ORDER BY id DESC LIMIT 1'));
    }

    public function testCoverageOnlyWithFreshDataKeptWindowByWindow(): void
    {
        $this->shops();
        // PayPal's data complete only up to 1 Nov 02:00: nothing after 31 Oct is proven read (c1)
        $http = new FakePaypalHttp([PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')], '', null, '2026-11-01T02:00:00Z');
        self::readPp($http, '2026-11-01', '2026-11-24');
        self::assertNull(PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertSame('2026-11-01', self::fees()->nightlyFrom('vapeandgo'));
        self::assertContains('paypal_stale', array_column(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), 'kind'));
        // fresh data, but the third window fails: the first two stay proven, the next night starts there (c2)
        $http = new FakePaypalHttp([PaypalTx::sale('3A00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')], '',
            static fn (int $n, string $p, array $q): ?array => $p === '/v1/reporting/transactions' && str_starts_with($q['start_date'], '2026-11-15')
                ? ['status' => 503, 'body' => 'down'] : null);
        try {
            self::readPp($http, '2026-11-01', '2026-11-24');
            self::fail('the third window fails');
        } catch (CwException $e) {
            self::assertSame('paypal_unreachable', $e->errorCode);
        }
        self::assertSame('2026-11-14', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertSame('2026-11-15', self::fees()->nightlyFrom('vapeandgo'), 'the first day not proven (round 4, H8)');
        // a healthy read at 05:20 of data refreshed at 02:20: 26 hours after that refresh (04:20 the next day) is no stale alert (c3)
        $http = new FakePaypalHttp([], '', null, '2026-11-25T02:20:00Z');
        self::readPp($http, '2026-11-13', '2026-11-25', '2026-11-25 05:20:00');
        $kinds = array_column(PaypalFees::health(self::$db, new \DateTimeImmutable('2026-11-26T04:20:00Z')), 'kind');
        self::assertNotContains('paypal_stale', $kinds);
        self::assertNotContains('paypal_not_read', $kinds);
    }

    public function testInvariantsLeaveDueRowsAloneAndStatusIsCheckedBeforeIgnore(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([self::cb('3A00000000000CB1', '2026-11-07T12:00:00Z', '-100.00', '1312001'),
            PaypalTx::row('3A00000000000B01', 'T0801', '2026-11-07T12:00:00Z', '5.00', null, null, 'X')]);
        self::readPp($http);
        self::assertSame(['look', 'status'], [self::row('3A00000000000B01')['count_state'], self::row('3A00000000000B01')['look_reason']], 'e2');
        // read again smaller: due with its old place until decided; the checks of a decided row do not judge it meanwhile (c4)
        self::$db->exec("UPDATE paypal_txn SET amount_pence = -5000, cost_pence = 5000, due = 1, due_since = UTC_TIMESTAMP(6) WHERE txn_id = '3A00000000000CB1'");
        self::assertSame([], array_values(array_filter(PaypalInvariants::check(self::$db), static fn (string $l): bool => str_starts_with($l, 'PP1') || str_starts_with($l, 'PP2'))));
        self::decideAll();
        $s = PaypalFees::status(self::$db, 'vapeandgo');
        self::assertSame(1, $s['look_total']);
        self::assertSame((int) self::row('3A00000000000B01')['id'], (int) $s['look'][0]['id'], 'e3: the row id shown');
    }
}
