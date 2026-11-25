<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1172 round 3 (Codex astra re-check of 20faffa, runs/codex_25nov/paypal_verify_astra_final.md: NO GO; the lead's decisions; docs/decisions.md
 * PPF41-PPF45): MADE-UP rows. Coverage is only what PayPal's answer proves (its interval, its least fresh page); the forward read is never held
 * back by old pending rows (they have their own windows) and goes on night by night; the refunds and chargebacks of the same orders are
 * followed to the end or the run fails saying so; a chargeback counts whole only when PayPal's own refunds cover the order's refunds in CW
 * (else a look: refund_not_on_paypal), the refunds counted by the profit's own line rules; a parent of two PayPal versions never gives its
 * shop to its children; a row PayPal leaves out keeps the forms it was read by and is read again; the newest version's captures win, a
 * positive overlap is held; two versions of the same time naming other shops are a conflict; a version of no update time is older than any
 * dated one; a conflict is checked before an ignored row is let go; every order a chargeback touches is guarded.
 */
final class PaypalFeesR3Test extends StockTestCase
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

    private static function fees(string $now = self::PP_NOW, int $chunk = PaypalFees::CHUNK): PaypalFees
    {
        return new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')), null, $chunk);
    }

    private static function api(FakePaypalHttp $http): PaypalApi
    {
        return new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        });
    }

    private static function readPp(FakePaypalHttp $http, string $from = '2026-10-31', string $to = '2026-11-24', string $now = self::PP_NOW, int $chunk = PaypalFees::CHUNK): array
    {
        return self::fees($now, $chunk)->run(Caller::system('paypal_test'), self::api($http), $from, $to, 'backfill');
    }

    /** The nightly as bin/paypal_fees.php runs it: forward from nightlyFrom(), then the old days in their own windows. */
    private static function nightly(FakePaypalHttp $http, string $now = self::PP_NOW): array
    {
        $fees = self::fees($now);
        $from = $fees->nightlyFrom('vapeandgo');
        return $fees->run(Caller::system('paypal_test'), self::api($http), $from, $fees->today(), 'nightly', null, $fees->nightlyExtra('vapeandgo', $from));
    }

    private static function decideAll(string $now = self::PP_NOW): int
    {
        return self::fees($now)->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
    }

    /**
     * An order with one goods line (refunds as given, inc VAT, at order level), and any more lines given (a cancelled or free line with a
     * refund of its own).
     *
     * @param list<array<string, mixed>> $more
     */
    private static function order(int $ch, string $ref, string $no, ?string $paid, int $version = 1, int $refundInc = 0, array $more = []): void
    {
        $lines = [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
            'refund_inc' => 0, 'free' => false]];
        foreach ($more as $i => $l) {
            $lines[] = $l + ['line_id' => (string) (8100000 + (int) $ref * 10 + $i), 'variant_id' => '40124', 'qty' => 1, 'price_inc' => 3000, 'discount_inc' => 0,
                'cancelled' => false, 'refund_inc' => 0, 'free' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-30T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
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

    /** @return list<string> the health alert's kinds at the run's time */
    private static function alerts(string $now = self::PP_NOW): array
    {
        return array_column(PaypalFees::health(self::$db, new \DateTimeImmutable($now . 'Z')), 'kind');
    }

    /** Vape and Go (orders 1001-1003) and Electrofag (orders 2001, 2002), both switched on from 1 Nov (Electrofag's real day is 1 Dec: the fuzz). */
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

    // ---- c1: coverage only as far as PayPal's answer proves -------------------------------------------------------------------------

    public function testCoverageIsOnlyWhatPaypalsAnswerCovers(): void
    {
        $this->shops();
        // a 1-7 Nov window answered for 1-4 Nov only (its end_date) with a fresh refresh time: proven to 4 Nov, the rest still to read, the
        // alert on until it is
        $http = new FakePaypalHttp([PaypalTx::sale('3B00000000000V01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')]);
        $http->alter = static function (array $a, array $q): array {
            if (str_starts_with($q['start_date'], '2026-11-01')) {
                $a['end_date'] = '2026-11-04T23:59:59Z';
            }
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-24');
        self::assertSame('2026-11-04', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'), 'proven only to the end of what the answer covered');
        self::assertSame('2026-11-03', self::fees()->nightlyFrom('vapeandgo'), 'the next read starts from there');
        self::assertContains('paypal_not_read', self::alerts(), 'the alert stays until the days are covered');
        // the whole days answered the next time: covered to yesterday, the alert clears
        $http->alter = null;
        self::nightly($http);
        self::assertSame('2026-11-24', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertNotContains('paypal_not_read', self::alerts());
    }

    public function testTheLeastFreshPageDecidesAndAPageOfAnotherNumberIsRefused(): void
    {
        $this->shops();
        // 501 rows on one day: two pages of 500; the first gives no refresh time, the second a fresh one: nothing of the window is proven
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = PaypalTx::sale(sprintf('3C%014d', $i), '2026-11-20T10:00:00Z', '10.00', '-0.30', null);
        }
        $http = new FakePaypalHttp($rows);
        $http->alter = static function (array $a, array $q): array {
            if ($q['page'] === '1') {
                unset($a['last_refreshed_datetime']);
            }
            return $a;
        };
        self::readPp($http, '2026-11-18', '2026-11-24');
        self::assertNull(PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-18'), 'one page of unknown completeness: nothing proven');
        // the first page refreshed to 21 Nov 02:00, the second fresh: proven to 20 Nov only (the least fresh page)
        $http->alter = static function (array $a, array $q): array {
            if ($q['page'] === '1') {
                $a['last_refreshed_datetime'] = '2026-11-21T02:00:00Z';
            }
            return $a;
        };
        self::readPp($http, '2026-11-18', '2026-11-24');
        self::assertSame('2026-11-20', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-18'));
        // an answer to page 2 that says it is page 1: not this window's, refused whole
        $http->alter = static function (array $a, array $q): array {
            if ($q['page'] === '2') {
                $a['page'] = 1;
            }
            return $a;
        };
        try {
            self::readPp($http, '2026-11-18', '2026-11-24');
            self::fail('a page of another number is refused');
        } catch (CwException $e) {
            self::assertSame('paypal_moved', $e->errorCode);
        }
    }

    // ---- c2: old pending rows in their own windows, forward progress every night ------------------------------------------------------

    public function testOldPendingRowsNeverHoldTheForwardReadBackAndACatchUpFinishesNightByNight(): void
    {
        $this->shops();
        $rows = [['transaction_updated_date' => '2026-11-01T09:00:00Z'] + PaypalTx::sale('3D00000000000P01', '2026-11-01T09:00:00Z', '30.00', '-0.90', '1312001', 'P')];
        for ($d = 2; $d <= 26; $d++) {
            $rows[] = PaypalTx::sale(sprintf('3D0000000000%04d', $d), sprintf('2026-11-%02dT11:00:00Z', $d), '10.00', '-0.30', null);
        }
        // read to 5 Nov only (PayPal complete to 6 Nov 02:00): covered to 5 Nov; the 1 Nov sale stays pending
        self::readPp(new FakePaypalHttp($rows, '', null, '2026-11-06T02:00:00Z'), '2026-11-01', '2026-11-05', '2026-11-06 05:20:00');
        self::assertSame(['pending', '2026-11-05'], [self::row('3D00000000000P01')['count_state'], PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01')]);
        // every night only two windows can be read (the third fails, as the run's deadline would): the forward days come first, the old pending
        // row's own window last
        $night = static function (string $day, ?int $windows) use ($rows): void {
            $n = 0;
            $http = new FakePaypalHttp($rows, '', static function (int $i, string $p, array $q) use (&$n, $windows): ?array {
                if ($p === PaypalApi::SEARCH_PATH && $q['page'] === '1') {
                    $n++;
                }
                return $windows !== null && $n > $windows ? ['status' => 503, 'body' => 'down'] : null;
            }, $day . 'T02:00:00Z');
            try {
                self::nightly($http, $day . ' 05:20:00');
            } catch (CwException $e) {
                self::assertSame('paypal_unreachable', $e->errorCode);
            }
        };
        $night('2026-11-25', 2);
        self::assertSame('2026-11-17', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'), 'night 1: two forward windows from the last day proven');
        self::assertSame(0, (int) self::row('3D0000000000' . '0012')['due'], 'what the night read is decided the same night, its run failed or not');
        $night('2026-11-26', 2);
        self::assertSame('2026-11-25', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'), 'night 2: the catch-up is finished');
        // the pending row's own window, once the forward days leave time for it; a whole night: the alert clears
        $night('2026-11-27', null);
        self::assertSame((int) self::$db->value('SELECT MAX(id) FROM paypal_run'), (int) self::row('3D00000000000P01')['last_run'], 'the old pending row read again');
        self::assertSame('2026-11-26', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertNotContains('paypal_not_read', self::alerts('2026-11-27 06:00:00'));
    }

    // ---- a2: the same orders' refunds and chargebacks followed to the end, or a failed run that says so --------------------------------

    public function testTheSameOrdersChargebacksAreFollowedToTheEndOrTheRunFailsSayingSo(): void
    {
        $this->shops();
        $k = PaypalFees::SIBLING_PASSES + 1;
        for ($i = 1; $i <= $k + 1; $i++) {
            self::order($this->vpg, (string) (5000 + $i), (string) (1320000 + $i), '2026-11-02T10:00:00Z');
        }
        // a chain: chargeback i on order i, then PayPal corrects it to order i + 1 (a newer version)
        $chain = static fn (int $shift): array => array_map(static fn (int $i): array => self::cb(sprintf('3E%014d', $i), '2026-11-07T12:00:00Z', '-10.00',
            (string) (1320000 + $i + $shift), 'S', ['transaction_updated_date' => $shift === 0 ? '2026-11-07T12:00:00Z' : '2026-11-08T12:00:00Z']), range(1, $k));
        $http = new FakePaypalHttp($chain(0));
        self::readPp($http);
        $http->set($chain(1));
        // one row a write: each one brings the next on its order, more steps than CW follows in one decision: the run fails, nothing half-decided
        try {
            self::readPp($http, '2026-10-31', '2026-11-24', self::PP_NOW, 1);
            self::fail('the chain is longer than one decision follows');
        } catch (CwException $e) {
            self::assertSame('paypal_siblings', $e->errorCode);
        }
        $run = self::$db->one('SELECT state, error FROM paypal_run ORDER BY id DESC LIMIT 1');
        self::assertSame('failed', $run['state']);
        self::assertStringContainsString('more than ' . PaypalFees::SIBLING_PASSES . ' steps', (string) $run['error']);
        self::assertSame($k, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE txn_id LIKE '3E%' AND due = 1"), 'nothing of the chain decided');
    }

    public function testAChainWithinTheBoundIsDecidedWholeInOneWrite(): void
    {
        $this->shops();
        for ($i = 1; $i <= 11; $i++) {
            self::order($this->vpg, (string) (5100 + $i), (string) (1321000 + $i), '2026-11-02T10:00:00Z');
        }
        $chain = static fn (int $shift): array => array_map(static fn (int $i): array => self::cb(sprintf('3N%014d', $i), '2026-11-07T12:00:00Z', '-10.00',
            (string) (1321000 + $i + $shift), 'S', ['transaction_updated_date' => $shift === 0 ? '2026-11-07T12:00:00Z' : '2026-11-08T12:00:00Z']), range(1, 10));
        $http = new FakePaypalHttp($chain(0));
        self::readPp($http);
        $http->set($chain(1));
        // one row a write: the first one brings the other nine (each on the next one's order): all decided in that write, each on its new order
        $writes = 0;
        $fees = new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::PP_NOW, new \DateTimeZone('UTC')),
            static function (int $rows, float $sec) use (&$writes): void {
                $writes++;
            }, 1);
        $fees->run(Caller::system('paypal_test'), self::api($http), '2026-10-31', '2026-11-24', 'backfill');
        for ($i = 1; $i <= 10; $i++) {
            self::assertSame([(string) (5101 + $i), 1000, 0], [self::row(sprintf('3N%014d', $i))['match_order_ref'], (int) self::row(sprintf('3N%014d', $i))['alloc_pence'],
                (int) self::row(sprintf('3N%014d', $i))['due']]);
        }
        self::assertSame(1, $writes, 'the whole chain in the first write');
        self::assertDaysRebuild($this->vpg);
    }

    // ---- a3, a4: a chargeback counts whole only when PayPal's own refunds cover the order's refunds in CW ----------------------------

    public function testAChargebackCountsWholeOnlyWhenPaypalsRefundsCoverTheOrdersRefundsInCw(): void
    {
        $this->shops();
        // 1001: £30 refunded in CW, not on PayPal (by bank, or before the backfill): the chargeback needs a look, its fee counts
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        // 1002: £20 refunded in CW and £20 refunded by PayPal: the £80 chargeback counts whole
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 2, 2000);
        // 1003: £30 refunded only on a cancelled line, 1004: only on a free line: neither takes anything off the profit, both count whole (a4)
        self::order($this->vpg, '1003', '1312003', '2026-11-05T09:00:00Z', 2, 0, [['cancelled' => true, 'refund_inc' => 3000]]);
        self::order($this->vpg, '1004', '1312004', '2026-11-05T09:30:00Z', 1, 0, [['free' => true, 'refund_inc' => 3000]]);
        // 1005: not paid in CW, £30 refunded: counts none of it yet, the chargeback counts whole
        self::order($this->vpg, '1005', '1312005', null, 1, 3000);
        $http = new FakePaypalHttp([
            self::cb('3F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            PaypalTx::row('3F00000000000F01', 'T0106', '2026-11-07T12:05:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '3F00000000000C01']),
            PaypalTx::refund('3F00000000000R02', '2026-11-05T12:00:00Z', '-20.00', null, '1312002'),
            self::cb('3F00000000000C02', '2026-11-08T12:00:00Z', '-80.00', '1312002'),
            self::cb('3F00000000000C03', '2026-11-09T12:00:00Z', '-30.00', '1312003'),
            self::cb('3F00000000000C04', '2026-11-09T12:10:00Z', '-30.00', '1312004'),
            self::cb('3F00000000000C05', '2026-11-10T12:00:00Z', '-30.00', '1312005'),
        ]);
        self::readPp($http);
        $c1 = self::row('3F00000000000C01');
        self::assertSame(['look', 'refund_not_on_paypal', 3000, 0, null, 0], [$c1['count_state'], $c1['look_reason'], (int) $c1['refund_seen_pence'],
            (int) $c1['alloc_pence'], $c1['alloc_dest'], (int) $c1['refund_offset_pence']], 'its order\'s refund in CW: the chargeback\'s own money, or paid besides it? staff decide');
        self::assertSame(['counts', '1001', 1400], [self::row('3F00000000000F01')['count_state'], self::row('3F00000000000F01')['alloc_order_ref'],
            (int) self::row('3F00000000000F01')['alloc_pence']], 'the chargeback fee is known: it counts');
        foreach (['3F00000000000C02' => 8000, '3F00000000000C03' => 3000, '3F00000000000C04' => 3000, '3F00000000000C05' => 3000] as $id => $p) {
            self::assertSame(['counts', $p, 0], [self::row($id)['count_state'], (int) self::row($id)['alloc_pence'], (int) self::row($id)['refund_offset_pence']], "{$id} counts whole");
        }
        self::assertContains('paypal_needs_look', self::alerts());
        // 1005 paid in CW: its £30 refund counts now and is not on PayPal: its chargeback needs a look (decided again by the rematch)
        self::order($this->vpg, '1005', '1312005', '2026-11-05T10:00:00Z', 2, 3000);
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal'], [self::row('3F00000000000C05')['count_state'], self::row('3F00000000000C05')['look_reason']]);
        // the chargeback on 1001 reversed: as uncertain as the chargeback, a look too
        $http->set([self::cb('3F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            PaypalTx::row('3F00000000000X01', 'T1202', '2026-11-12T12:00:00Z', '30.00', null, '1312001')]);
        self::readPp($http);
        self::assertSame(['look', 'refund_not_on_paypal'], [self::row('3F00000000000X01')['count_state'], self::row('3F00000000000X01')['look_reason']]);
        // PayPal's own £30 refund of 1001 read: CW's refund is PayPal's, the chargeback and its reversal count whole
        $http->set([self::cb('3F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            PaypalTx::row('3F00000000000X01', 'T1202', '2026-11-12T12:00:00Z', '30.00', null, '1312001'),
            PaypalTx::refund('3F00000000000R01', '2026-11-06T12:00:00Z', '-30.00', null, '1312001')]);
        self::readPp($http);
        self::assertSame([['counts', 3000], ['counts', -3000]], [[self::row('3F00000000000C01')['count_state'], (int) self::row('3F00000000000C01')['alloc_pence']],
            [self::row('3F00000000000X01')['count_state'], (int) self::row('3F00000000000X01')['alloc_pence']]]);
        self::assertDaysRebuild($this->vpg);
    }

    public function testAChargebackOnAnOrderPaidBeforeTheFirstDayReadWithARefundInCwNeedsALook(): void
    {
        // the lead's correction of a3 (PPF43): an order paid before the first day read from PayPal (the backfill's 1 Nov) may have PayPal refunds
        // CW never read: any refund of it in CW makes its chargeback a look, even when the PayPal refunds read match it
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-10-28T10:00:00Z', 2, 1500);
        self::order($this->vpg, '1002', '1312002', '2026-10-28T11:00:00Z', 2, 0);
        $http = new FakePaypalHttp([PaypalTx::refund('3O00000000000R01', '2026-11-02T12:00:00Z', '-15.00', null, '1312001'),
            self::cb('3O00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            self::cb('3O00000000000C02', '2026-11-07T13:00:00Z', '-30.00', '1312002')]);
        self::readPp($http);
        self::assertSame(['look', 'refund_not_on_paypal', 1500, 0], [self::row('3O00000000000C01')['count_state'], self::row('3O00000000000C01')['look_reason'],
            (int) self::row('3O00000000000C01')['refund_seen_pence'], (int) self::row('3O00000000000C01')['alloc_pence']]);
        // paid before it too, but no refund in CW: nothing uncertain, it counts whole
        self::assertSame(['counts', 3000], [self::row('3O00000000000C02')['count_state'], (int) self::row('3O00000000000C02')['alloc_pence']]);
        self::assertDaysRebuild($this->vpg);
    }

    // ---- a6: a parent of two PayPal versions never gives its shop to its children ------------------------------------------------------

    public function testAParentOfTwoVersionsAtOneTimeNeverGivesItsShopToItsChildren(): void
    {
        $this->shops();
        $sale = ['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3G00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $fee = PaypalTx::row('3G00000000000F01', 'T0114', '2026-11-07T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '3G00000000000S01']);
        $http = new FakePaypalHttp([$sale, $fee]);
        self::readPp($http);
        self::assertSame(['counts', '1001'], [self::row('3G00000000000F01')['count_state'], self::row('3G00000000000F01')['alloc_order_ref']]);
        // the same version of the sale read again with another fee: the sale needs a look, its dispute fee waits with it (never Vape and Go's)
        $http->set([['fee_amount' => ['currency_code' => 'GBP', 'value' => '-1.90']] + $sale, $fee]);
        self::readPp($http);
        self::assertSame(['look', 'pp_conflict'], [self::row('3G00000000000S01')['count_state'], self::row('3G00000000000S01')['look_reason']]);
        self::assertSame(['look', 'ref_unresolved', null], [self::row('3G00000000000F01')['count_state'], self::row('3G00000000000F01')['look_reason'],
            self::row('3G00000000000F01')['alloc_dest']]);
        self::assertSame([], self::days($this->vpg), 'neither counts while PayPal says two things of the sale');
        // a newer version settles the sale: the fee follows it again
        $http->set([['transaction_updated_date' => '2026-11-04T12:00:00Z', 'fee_amount' => ['currency_code' => 'GBP', 'value' => '-1.90']] + $sale, $fee]);
        self::readPp($http);
        self::assertSame(['counts', '1001', 1400], [self::row('3G00000000000F01')['count_state'], self::row('3G00000000000F01')['alloc_order_ref'],
            (int) self::row('3G00000000000F01')['alloc_pence']]);
        self::assertDaysRebuild($this->vpg);
    }

    // ---- a7: a row PayPal leaves out keeps the forms it was read by and is read again ---------------------------------------------------

    public function testARowLeftOutOfTheRestorationReadKeepsItsFormsAndIsReadAgain(): void
    {
        $this->shops();
        $e2 = PaypalTx::sale('3H00000000000E02', '2026-11-06T09:30:00Z', '20.00', '-0.70', '5458');
        // Electrofag's form narrowed to 5 digits: a row first read now is no shop's (Vape and Go's "not on an order" line)
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{5})-[0-9]{10}$"');
        $http = new FakePaypalHttp([$e2]);
        self::readPp($http);
        $narrow = (string) self::row('3H00000000000E02')['read_sig'];
        self::assertSame(['dropped', 'shop'], [self::row('3H00000000000E02')['invoice_state'], self::row('3H00000000000E02')['alloc_dest']]);
        // the form put back; PayPal leaves the row out of the read of its day: it keeps the forms it was read by and is read again
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,6})-[0-9]{10}$"');
        $http->set([]);
        self::assertSame([['2026-11-06', '2026-11-06']], self::fees()->nightlyExtra('vapeandgo', self::fees()->nightlyFrom('vapeandgo')), 'its day, in its own window');
        self::nightly($http);
        self::assertSame($narrow, (string) self::row('3H00000000000E02')['read_sig'], 'never marked read under forms it was not read by');
        self::assertSame([['2026-11-06', '2026-11-06']], self::fees()->nightlyExtra('vapeandgo', self::fees()->nightlyFrom('vapeandgo')), 'still to be read again');
        // PayPal gives it again: Electrofag's order
        $http->set([$e2]);
        self::nightly($http);
        self::assertSame(['order', '2002', '5458'], [self::row('3H00000000000E02')['alloc_dest'], self::row('3H00000000000E02')['match_order_ref'],
            self::row('3H00000000000E02')['invoice_no']]);
        // the same left out until it is older than the days read again: a look (form_unread), never a shop guessed from the forms of then
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{5})-[0-9]{10}$"');
        $e3 = PaypalTx::sale('3H00000000000E03', '2026-11-06T09:40:00Z', '20.00', '-0.70', '5457');
        $http->set([$e3]);
        self::readPp($http, '2026-11-06', '2026-11-06');
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,6})-[0-9]{10}$"');
        self::decideAll('2026-12-10 05:20:00');
        self::assertSame(['look', 'form_unread', null], [self::row('3H00000000000E03')['count_state'], self::row('3H00000000000E03')['look_reason'],
            self::row('3H00000000000E03')['alloc_dest']]);
    }

    // ---- the newest version's captures win; a positive overlap is held -----------------------------------------------------------------

    public function testTheNewestVersionsCapturesWinAndAPositiveOverlapIsHeld(): void
    {
        $this->shops();
        $s = ['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3I00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$s]);
        self::readPp($http);
        self::assertSame(['counts', '1001'], [self::row('3I00000000000S01')['count_state'], self::row('3I00000000000S01')['alloc_order_ref']]);
        // a newer version clears its invoice and carries Hayati Vapes' order in its custom field: Hayati's (kept, never counted), off Vape and Go
        $corrected = array_diff_key($s, ['invoice_id' => true, 'custom_field' => true]);
        $http->set([['transaction_updated_date' => '2026-11-04T12:00:00Z', 'custom_field' => 'faaeeb-3090'] + $corrected]);
        self::readPp($http);
        self::assertSame(['outside', 'hayati', 'none', null], [self::row('3I00000000000S01')['count_state'], self::row('3I00000000000S01')['form_shop'],
            self::row('3I00000000000S01')['invoice_state'], self::row('3I00000000000S01')['alloc_dest']]);
        self::assertSame([], self::days($this->vpg), 'its fee is no longer Vape and Go\'s');
        // a newer version back to Vape and Go's invoice while Hayati Vapes' form takes the same numbers: two shops' forms, a look
        $this->setting('profit.paypal_form_hayati', '"^([0-9]{7})-[0-9]{10}$"');
        $http->set([['transaction_updated_date' => '2026-11-05T12:00:00Z'] + $s]);
        self::readPp($http);
        self::assertSame(['look', 'form_overlap'], [self::row('3I00000000000S01')['count_state'], self::row('3I00000000000S01')['look_reason']]);
        self::assertSame([], self::days($this->vpg));
    }

    // ---- two versions of the same time naming other shops: a conflict ------------------------------------------------------------------

    public function testTheSameVersionNamingAnotherShopIsAConflictNeverTheLastReadWins(): void
    {
        $this->shops();
        $s = ['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3J00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $f = PaypalTx::row('3J00000000000F01', 'T0114', '2026-11-07T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '3J00000000000S01']);
        $http = new FakePaypalHttp([$s, $f]);
        self::readPp($http);
        self::assertSame(['2026-11-03|fee_cost' => 120, '2026-11-07|fee_cost' => 1400], self::days($this->vpg));
        // the same version (12:00) read again naming Electrofag's order 5457, every amount the same: a contradiction, a look; the kept stay
        $http->set([['invoice_id' => '5457-1763700000', 'custom_field' => '5457-1763700000'] + $s, $f]);
        self::readPp($http);
        $r = self::row('3J00000000000S01');
        self::assertSame(['look', 'pp_conflict', 'vapeandgo', '1312001'], [$r['count_state'], $r['look_reason'], $r['invoice_shop'], $r['invoice_no']]);
        self::assertSame(['look', 'ref_unresolved'], [self::row('3J00000000000F01')['count_state'], self::row('3J00000000000F01')['look_reason']]);
        self::assertSame([], self::days($this->ef), 'nothing moved to Electrofag');
        self::assertSame([], self::days($this->vpg), 'nor counted on Vape and Go while PayPal says two things');
        // a newer version says which (Electrofag's): the sale and its fee there
        $http->set([['invoice_id' => '5457-1763700000', 'custom_field' => '5457-1763700000', 'transaction_updated_date' => '2026-11-04T12:00:00Z'] + $s, $f]);
        self::readPp($http);
        self::assertSame(['2001', '2001'], [self::row('3J00000000000S01')['alloc_order_ref'], self::row('3J00000000000F01')['alloc_order_ref']]);
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- a9: a version of no update time is older than any dated one -----------------------------------------------------------------

    public function testAVersionWithNoUpdateTimeIsOlderThanAnyDatedOne(): void
    {
        $this->shops();
        $s = ['transaction_updated_date' => '2026-11-03T12:00:00Z'] + PaypalTx::sale('3K00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$s]);
        self::readPp($http);
        $undated = PaypalTx::sale('3K00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001', 'P');
        unset($undated['transaction_updated_date']);
        $http->set([$undated]);
        self::readPp($http);
        $r = self::row('3K00000000000S01');
        self::assertSame(['S', 'counts', 120, '2026-11-03 12:00:00.000000'], [$r['status'], $r['count_state'], (int) $r['alloc_pence'], (string) $r['pp_updated_at']],
            'an undated pending version never undoes the dated settled one, nor erases its time');
    }

    // ---- a conflict is checked before an ignored row is let go ----------------------------------------------------------------------

    public function testAnIgnoredRowWithAFeeInConflictNeedsALook(): void
    {
        $this->shops();
        $b = PaypalTx::row('3L00000000000B01', 'T0801', '2026-11-07T12:00:00Z', '5.00', null, null, 'S');
        $http = new FakePaypalHttp([$b]);
        self::readPp($http);
        self::assertSame('not_counted', self::row('3L00000000000B01')['count_state']);
        // the same time, a £14 fee now: the kept zero stays, the row needs a look (never let go as an ignored row of no fee)
        $http->set([['fee_amount' => ['currency_code' => 'GBP', 'value' => '-14.00']] + $b]);
        self::readPp($http);
        self::assertSame(['look', 'pp_conflict', 0], [self::row('3L00000000000B01')['count_state'], self::row('3L00000000000B01')['look_reason'],
            (int) self::row('3L00000000000B01')['fee_pence']]);
        self::assertContains('paypal_needs_look', self::alerts());
    }

    // ---- every order a chargeback touches is guarded ----------------------------------------------------------------------------------

    public function testEveryOrderAChargebackTouchesIsGuardedWhateverItsMoney(): void
    {
        $this->shops();
        // £30 refunded in CW: the chargeback's decision (a look, nothing counted before or after) rests on the order's refunds: its order is held
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 2, 3000);
        $http = new FakePaypalHttp([self::cb('3M00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312002')]);
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '1002')]));
        try {
            self::readPp($http);
            self::fail('the order is being stored (its guard held): the decision waits, never made past it');
        } catch (CwException $e) {
            self::assertSame('busy', $e->errorCode);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->vpg, '1002')]);
        }
        self::assertSame(1, (int) self::row('3M00000000000C01')['due'], 'still due: decided by the next run');
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal'], [self::row('3M00000000000C01')['count_state'], self::row('3M00000000000C01')['look_reason']]);
    }
}
