<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Ops\AlertDispatch;
use CW\Ops\AlertTransport;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalInvariants;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Words;

/**
 * CW-1172 round 6 (the Codex astra final of 9b46fe0, runs/codex_25nov/paypal_r5_final_astra_final.md: NO GO; the lead's 14 decisions of 25 Nov
 * ~22:05; docs/decisions.md PPF70-): MADE-UP rows. A recovery is said only for an episode that was said; a day is complete only when the
 * answer reaches the next day's start; the order queue is taken in bounded batches, each its own commit, within the deadline; older_run is
 * never cleared by a later run; a conflict in one answer settles only on a row given once in a proven window; a denied row with its own invoice
 * is not marked again by the reference rule; a reference shared by rows of two shops is held whichever is read first; an older version that
 * changes the shop is said; a batch recost lets the intake lock go before its PayPal work; the held refund's words say CW could not verify it.
 */
final class PaypalFeesR6Test extends StockTestCase
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
        HookedStatement::$before = [];
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

    /** @param int $takeBatch the queued orders one transaction takes (PaypalFees::TAKE_BATCH, 200; a literal here so the red run fails on behaviour) */
    private static function fees(string $now = self::PP_NOW, ?Db $db = null, int $takeBatch = 200): PaypalFees
    {
        return new PaypalFees($db ?? self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')), null, PaypalFees::CHUNK,
            PaypalFees::DECIDE_RESERVE_SEC, PaypalFees::LOCAL_DECIDE_SEC, $takeBatch);
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

    private static function decideAll(string $now = self::PP_NOW): int
    {
        return self::fees($now)->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
    }

    /** @param list<array<string, mixed>> $more */
    private static function order(int $ch, string $ref, string $no, ?string $paid, int $version = 1, int $refundInc = 0, array $more = []): void
    {
        $lines = [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
            'refund_inc' => 0, 'free' => false]];
        foreach ($more as $i => $l) {
            $lines[] = $l + ['line_id' => (string) (8100000 + (int) $ref * 10 + $i), 'variant_id' => '40124', 'qty' => 1, 'price_inc' => 3000, 'discount_inc' => 0,
                'cancelled' => false, 'refund_inc' => 0, 'free' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-27T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
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

    /** @param list<string> $cols @return list<mixed> */
    private static function of(string $id, array $cols, string $code = ''): array
    {
        $r = (array) self::$db->one('SELECT * FROM paypal_txn WHERE txn_id = ?' . ($code === '' ? '' : ' AND event_code = ?') . ' ORDER BY id LIMIT 1',
            $code === '' ? [$id] : [$id, $code]);
        return array_map(static fn (string $c): mixed => !array_key_exists($c, $r) ? '(no column)' : (in_array($c, ['alloc_pence', 'alloc_channel', 'due', 'pp_conflict',
            'last_run', 'older_run', 'fee_pence'], true) && $r[$c] !== null ? (int) $r[$c] : $r[$c]), $cols);
    }

    private static function cb(string $id, string $at, string $amount, ?string $no, string $status = 'S', array $over = []): array
    {
        return PaypalTx::row($id, 'T1201', $at, $amount, null, $no, $status, $over);
    }

    /** $fn inside a transaction begun outside Db::transaction() (PDO's own): nothing runs after its commit; rolled back when $fn fails. */
    private static function outsideTransaction(\Closure $fn): void
    {
        self::$db->pdo()->beginTransaction();
        try {
            $fn();
            self::$db->pdo()->commit();
        } finally {
            if (self::$db->pdo()->inTransaction()) {
                self::$db->pdo()->rollBack();
            }
        }
    }

    private static function lastRun(): int
    {
        return (int) self::$db->value('SELECT MAX(id) FROM paypal_run');
    }

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

    // ---- 2 (F-M2): a "back to normal" only for an episode whose alert was said ------------------------------------------------------

    public function testARecoveryIsSaidOnlyForAnEpisodeWhoseAlertWasSaid(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        self::readPp(new FakePaypalHttp([self::cb('6A00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001')]));
        $look = array_values(array_filter(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), static fn (array $p): bool => $p['kind'] === 'paypal_needs_look'));
        self::assertCount(1, $look);
        $this->setting('alerts.webhook_url', '"https://hooks.example.com/services/pp"');
        $this->setting('alerts.email_to', '""');
        $this->setting('alerts.repeat_minutes', '60');
        $sent = new class () implements AlertTransport {
            /** @var list<array<string, mixed>> */
            public array $payloads = [];

            public function webhook(string $url, array $payload, int $timeoutSec): void
            {
                $this->payloads[] = $payload;
            }

            public function email(string $to, string $subject, string $body): void
            {
            }
        };
        $t = new \DateTimeImmutable('2026-11-25T06:00:00Z');
        $run = static function (array $problems) use (&$t, $sent): array {
            return (new AlertDispatch(self::$db, $sent, static fn (): \DateTimeImmutable => $t))->run($problems, new Settings(self::$db), 'cw_test');
        };
        $run($look);                                   // 06:00 said
        $t = $t->modify('+1 hour');
        $run([]);                                      // 07:00 cleared: "back to normal" for the episode that was said
        $t = $t->modify('+1 hour');
        self::assertSame([], $run($look)['sent']);     // 08:00 back within the day: not said (PPF58)
        $t = $t->modify('+1 hour');
        $run([]);                                      // 09:00 cleared again: that episode was never said, so no "back to normal" either
        self::assertCount(2, $sent->payloads, 'the alert at 06:00 and its recovery at 07:00, nothing for the episode that was not said');
        self::assertSame(['paypal_needs_look:vapeandgo'], $sent->payloads[1]['cleared']);
    }

    // ---- 3 (H7): a day is complete only when the answer reaches the next day's start --------------------------------------------------

    public function testADayIsCompleteOnlyWhenTheAnswerReachesTheNextDaysStart(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('6B00000000000S01', '2026-11-02T10:00:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::row('6B00000000000F01', 'T0114', '2026-11-04T23:59:59.750Z', '-60.00', null, '1312002')]);
        // the answer to 1-7 Nov ends at 4 Nov 23:59:59.250: the £60 fee at .750 is not in it; 4 Nov is not complete
        $cut = (float) (new \DateTimeImmutable('2026-11-04T23:59:59.250Z'))->format('U.u');
        $http->alter = static function (array $a, array $q) use ($cut): array {
            if (!str_starts_with($q['start_date'], '2026-11-01')) {
                return $a;
            }
            $a['end_date'] = '2026-11-04T23:59:59.250Z';
            $a['transaction_details'] = array_values(array_filter($a['transaction_details'], static fn (array $d): bool
                => (float) (new \DateTimeImmutable((string) $d['transaction_info']['transaction_initiation_date']))->format('U.u') <= $cut));
            $a['total_items'] = count($a['transaction_details']);
            $a['total_pages'] = $a['total_items'] > 0 ? 1 : 0;
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-07');
        $starts = array_map(static fn (array $c): string => substr($c['query']['start_date'], 0, 10),
            array_values(array_filter($http->calls, static fn (array $c): bool => $c['path'] === PaypalApi::SEARCH_PATH && $c['query']['page'] === '1')));
        self::assertSame(['2026-11-01', '2026-11-04'], $starts, 'the 4th read again: its last 0.75 s were not answered for');
        self::assertSame(['counts', '1002', 6000], self::of('6B00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence']), 'the £60 fee read and counted');
        self::assertSame('2026-11-07', self::$db->value('SELECT covered_to FROM paypal_run ORDER BY id DESC LIMIT 1'));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- 4 (M2): the order queue taken in bounded batches, each its own commit, the deadline between them -------------------------------

    public function testTheOrderQueueIsTakenInBoundedBatchesEachItsOwnCommitWithinTheDeadline(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([self::cb('6C00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            self::cb('6C00000000000C02', '2026-11-07T12:10:00Z', '-30.00', '1312002'), self::cb('6C00000000000C03', '2026-11-07T12:20:00Z', '-30.00', '1312003')]));
        // three orders' refunds recorded inside a transaction begun outside Db::transaction(): their snapshots queue them (durable) and no work
        // after the commit runs (the next PayPal run takes the queue)
        self::outsideTransaction(function (): void {
            foreach ([['1001', '1312001', '2026-11-03T10:00:00Z'], ['1002', '1312002', '2026-11-04T09:00:00Z'], ['1003', '1312003', '2026-11-05T09:00:00Z']] as [$ref, $no, $paid]) {
                self::order($this->vpg, $ref, $no, $paid, 2, 3000);
            }
        });
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
        // one queued order a batch, each its own commit; the first batch slowed past the deadline: the next is never started
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        HookedStatement::$before['DELETE FROM paypal_order_due'] = static function (): void {
            usleep(2_600_000);
        };
        try {
            self::fees(self::PP_NOW, $conn, 1)->decideAll(Caller::system('paypal_test'), 'vapeandgo', null, microtime(true) + 2.5);
            self::fail('the deadline ends the decisions');
        } catch (CwException $e) {
            self::assertSame('paypal_deadline', $e->errorCode);
        }
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'), 'the first order taken and committed, the other two still queued');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'), 'the first order\'s chargeback marked due with it');
        // the next run takes the rest and decides every one
        self::decideAll();
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
        foreach (['6C00000000000C01', '6C00000000000C02', '6C00000000000C03'] as $id) {
            self::assertSame(['look', 'refund_not_on_paypal', 0], self::of($id, ['count_state', 'look_reason', 'due']));
        }
        self::assertDaysRebuild($this->vpg);
    }

    public function testTakingTheQueueNeverWaitsForAHeldRowPastTheDeadline(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([self::cb('6C00000000000C04', '2026-11-07T12:00:00Z', '-30.00', '1312001')]));
        self::outsideTransaction(function (): void {
            self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        });
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
        // another session holds the chargeback's row (a PayPal store, a guarded write): the take waits for it no longer than the time left
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT id FROM paypal_txn WHERE txn_id = '6C00000000000C04' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 10');
        $t = microtime(true);
        try {
            self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null, microtime(true) + 2.5);
            self::fail('the held row ends the take');
        } catch (CwException $e) {
            self::assertContains($e->errorCode, ['busy', 'paypal_deadline']);
        } finally {
            $took = microtime(true) - $t;
            $other->pdo()->rollBack();
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertLessThan(4.0, $took, 'the row waited for no longer than the time left (never the session\'s 10 s)');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'), 'still queued');
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal', 0], self::of('6C00000000000C04', ['count_state', 'look_reason', 'due']));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
    }

    // ---- 5 (M3): older_run is never cleared by a later run --------------------------------------------------------------------------

    public function testOlderRunIsNeverClearedByALaterRun(): void
    {
        $this->shops();
        $new = ['transaction_updated_date' => '2026-11-04T12:00:00Z'] + PaypalTx::sale('6D00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        $http->set([['transaction_updated_date' => '2026-11-03T11:00:00Z', 'fee_amount' => ['currency_code' => 'GBP', 'value' => '-0.80']] + $new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        $older = self::lastRun();
        self::assertSame([-120, $older], self::of('6D00000000000S01', ['fee_pence', 'older_run']));
        // a later run gives the kept version: the row still says which run gave the older one (the gate refuses a run after the certified one)
        $http->set([$new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame([-120, self::lastRun(), $older], self::of('6D00000000000S01', ['fee_pence', 'last_run', 'older_run']));
    }

    // ---- 6 (duplicates): a conflict in one answer settles only on a row given ONCE in a window that is proven ---------------------------

    public function testAConflictInOneAnswerSettlesOnlyOnARowGivenOnceInAProvenWindow(): void
    {
        $this->shops();
        $c20 = ['transaction_updated_date' => '2026-11-07T13:00:00Z'] + self::cb('6E00000000000C01', '2026-11-07T12:00:00Z', '-20.00', '1312001');
        $c30 = ['transaction_updated_date' => '2026-11-07T14:00:00Z'] + self::cb('6E00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001');
        self::readPp(new FakePaypalHttp([$c20, $c30]), '2026-11-01', '2026-11-07');
        self::assertSame(['look', 2], self::of('6E00000000000C01', ['count_state', 'pp_conflict']));
        // the £30 version twice in one answer (the same content): no settling (its window proves nothing)
        self::readPp(new FakePaypalHttp([$c30, $c30]), '2026-11-01', '2026-11-07');
        self::assertSame(['look', 2], self::of('6E00000000000C01', ['count_state', 'pp_conflict']));
        // once, but in a window PayPal's answer does not prove (no refresh time): no settling
        self::readPp(new FakePaypalHttp([$c30], '', null, null), '2026-11-01', '2026-11-07');
        self::assertSame(['look', 2], self::of('6E00000000000C01', ['count_state', 'pp_conflict']));
        // once in a proven window: settled, counted
        self::readPp(new FakePaypalHttp([$c30]), '2026-11-01', '2026-11-07');
        self::assertSame(['counts', 0, 3000], self::of('6E00000000000C01', ['count_state', 'pp_conflict', 'alloc_pence']));
        self::assertSame([], PaypalInvariants::check(self::$db));
    }

    // ---- 7 (denied rows): a denied row with its own invoice is not marked again by the reference rule ------------------------------

    public function testADeniedRowWithItsOwnInvoiceIsNotMarkedAgainByTheReferenceRule(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('6F00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001'),
            self::cb('6F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001', 'D', ['paypal_reference_id' => '6F00000000000S01'])]);
        self::readPp($http);
        self::assertSame(['not_counted'], self::of('6F00000000000C01', ['count_state']));
        // a form edited: the sale's capture is of other forms (held), the denied chargeback is decided once under the forms of now
        $this->setting('profit.paypal_form_hayati', '"^faaeeb-([0-9]{1,7})$"');
        self::decideAll('2026-12-15 05:20:00');
        self::assertSame(['look', 'form_unread'], self::of('6F00000000000S01', ['count_state', 'look_reason']));
        self::assertSame(['not_counted', 'invoice'], self::of('6F00000000000C01', ['count_state', 'matched_by']));
        self::assertSame(0, self::fees('2026-12-15 05:20:00')->rematch('vapeandgo'), 'never marked again at every pass');
        // the sale read under the forms of now: it counts again; the chargeback (denied) stays not counted
        self::readPp($http, '2026-11-03', '2026-11-07', '2026-12-15 05:20:00');
        self::assertSame(['counts', '1001'], self::of('6F00000000000S01', ['count_state', 'alloc_order_ref']));
        self::assertSame(0, self::fees('2026-12-15 05:20:00')->rematch('vapeandgo'));
    }

    // ---- 10 (NEW High): a reference shared by rows of two shops: held, whichever is read first --------------------------------------

    public function testAReferenceSharedByRowsOfTwoShopsIsHeldWhicheverIsReadFirst(): void
    {
        $this->shops();
        $cb = static fn (string $id, string $code, string $no): array => PaypalTx::row($id, $code, '2026-11-07T12:00:00Z', '-30.00', null, $no);
        $v = static fn (string $id, string $no = '1312001'): array => $cb($id, 'T1106', $no);
        $e = static fn (string $id): array => $cb($id, 'T1201', '5457');
        $fee = static fn (string $id, string $ref): array => PaypalTx::row($id, 'T0106', '2026-11-07T13:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => $ref]);
        // one PayPal id with two event codes (two chargebacks of one rank), one of Vape and Go's order, one of Electrofag's; a dispute fee refers
        // to that id: read in both orders (X: Vape and Go's first, Y: Electrofag's first); W: two orders of one shop; U: both of one order (no
        // doubt: counted there); Z: Electrofag's read by an earlier run, Vape and Go's and the fee by a later one (the earlier row not in the
        // decision's own rows: loaded all the same)
        $rows = [$v('6G00000000000X01'), $e('6G00000000000X01'), $fee('6G00000000000F01', '6G00000000000X01'),
            $e('6G00000000000Y01'), $v('6G00000000000Y01'), $fee('6G00000000000F02', '6G00000000000Y01'),
            $v('6G00000000000W01'), $cb('6G00000000000W01', 'T1201', '1312002'), $fee('6G00000000000F04', '6G00000000000W01'),
            $v('6G00000000000U01', '1312003'), $cb('6G00000000000U01', 'T1201', '1312003'), $fee('6G00000000000F05', '6G00000000000U01'),
            $e('6G00000000000Z01')];
        $http = new FakePaypalHttp($rows);
        self::readPp($http);
        $http->set([...$rows, $v('6G00000000000Z01'), $fee('6G00000000000F03', '6G00000000000Z01')]);
        self::readPp($http);
        foreach (['6G00000000000F01', '6G00000000000F02', '6G00000000000F03', '6G00000000000F04'] as $id) {
            self::assertSame(['look', 'ref_conflict', null], self::of($id, ['count_state', 'look_reason', 'alloc_dest']), "{$id}: never the first one read");
        }
        self::assertSame(['counts', 'order', '1003', 1400], self::of('6G00000000000F05', ['count_state', 'alloc_dest', 'alloc_order_ref', 'alloc_pence']),
            'two rows of one rank that agree: counted on their order');
        self::assertSame(0, self::fees()->rematch('vapeandgo'));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- 11 (NEW High): an older version that changes the shop is said on the row, the same money or not ---------------------------

    public function testAnOlderVersionThatChangesTheShopIsSaidOnTheRow(): void
    {
        $this->shops();
        // a sale of no invoice and no custom field: Vape and Go's "not on an order" line (the owner's line)
        $s = ['transaction_updated_date' => '2026-11-04T12:00:00Z'] + PaypalTx::sale('6H00000000000S01', '2026-11-03T10:00:00Z', '30.00', '-1.20', null);
        $http = new FakePaypalHttp([$s]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['counts', 'shop'], self::of('6H00000000000S01', ['count_state', 'alloc_dest']));
        // an unrelated form edited; the next run returns an OLDER version naming Electrofag's order (the same money): said on the row
        $this->setting('profit.paypal_form_hayati', '"^faaeeb-([0-9]{1,7})$"');
        $http->set([['transaction_updated_date' => '2026-11-03T11:00:00Z', 'invoice_id' => '5457-1763700000'] + $s]);
        self::readPp($http, '2026-11-01', '2026-11-07', '2026-12-15 05:20:00');
        self::assertSame([self::lastRun(), 'none'], self::of('6H00000000000S01', ['older_run', 'invoice_state']), 'the older version is not taken, but said');
    }

    // ---- 12 (Medium): a batch recost lets the intake lock go before its PayPal work ---------------------------------------------------

    public function testABatchRecostLetsTheIntakeLockGoBeforeItsPaypalWork(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([self::cb('6I00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001')]));
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $held = 'not seen';
        $name = OrderSnapshots::intakeLockName($this->vpg, '1001');
        HookedStatement::$before['SELECT id, channel_id, order_ref, numbers FROM paypal_order_due'] = static function () use (&$held, $name): void {
            $held = self::$db->value('SELECT IS_USED_LOCK(?)', [$name]);
        };
        self::assertTrue((new OrderSnapshots($conn))->recompute($this->vpg, '1001', static fn (Db $db, array $body): array => []));
        self::assertNull($held, 'the order\'s intake lock is let go before the PayPal work after the commit (the API is never kept waiting by it)');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
    }

    // ---- 14 (Low): the held refund's words ---------------------------------------------------------------------------------------------

    public function testTheHeldRefundsWordsSayCwCouldNotVerifyIt(): void
    {
        $words = Words::of('PAYPAL_LOOK', 'refund_not_on_paypal');
        self::assertStringContainsString('CW could not verify this refund against PayPal', $words);
        self::assertStringContainsString('its fee is counted', $words);
        self::assertStringNotContainsString('outside PayPal', $words);
    }
}
