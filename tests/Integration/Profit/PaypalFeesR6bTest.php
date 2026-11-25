<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Profit\OrderSnapshots;
use CW\Profit\PaypalApi;
use CW\Profit\PaypalFees;
use CW\Profit\PaypalInvariants;
use CW\Profit\PaypalKey;
use CW\Profit\ProfitDays;
use CW\Tests\Support\FakePaypalHttp;
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1172 round 6b (the Codex astra review of 5784c00, runs/codex_25nov/paypal_r6_astra_final.md; the lead's decisions of 25 Nov ~22:55;
 * docs/decisions.md PPF79-): MADE-UP rows. Rows of one rank behind a reference are compared by their KNOWN shops and KNOWN order numbers
 * apart (one with no number agrees with one that has it; the fee's own invoice number is taken when every known value agrees); a new row of
 * a referenced PayPal id re-decides the rows that refer to it; a row one answer gave two contents of is settled only by a row whose day is
 * within the window's proven days at both ends; rematch's marks wait for a row no longer than the run's time.
 */
final class PaypalFeesR6bTest extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];
    private const PP_NOW = '2026-11-25 05:20:00';
    /** Vape and Go's custom field (its own other id): its shop, no order number. */
    private const VPG_CUSTOM = '1500380-1763700000';

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

    private static function fees(string $now = self::PP_NOW, ?Db $db = null): PaypalFees
    {
        return new PaypalFees($db ?? self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')));
    }

    private static function readPp(FakePaypalHttp $http, string $from = '2026-10-31', string $to = '2026-11-24', string $now = self::PP_NOW): array
    {
        return self::fees($now)->run(Caller::system('paypal_test'), new PaypalApi(PaypalKey::fromArray(self::KEY, 'vapeandgo'), $http, static function (int $s): void {
        }), $from, $to, 'backfill');
    }

    private static function order(int $ch, string $ref, string $no, ?string $paid): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-27T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 6499,
            'parcels' => []], 'api');
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
        return array_map(static fn (string $c): mixed => !array_key_exists($c, $r) ? '(no column)' : (in_array($c, ['alloc_pence', 'alloc_channel', 'due', 'pp_conflict'], true)
            && $r[$c] !== null ? (int) $r[$c] : $r[$c]), $cols);
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
        self::order($this->ef, '2001', '5457', '2026-11-06T08:00:00Z');
    }

    /** A chargeback of a PayPal id: its own invoice (an order number), or Vape and Go's custom field alone (its shop, no number). */
    private static function cb(string $id, string $code, ?string $no, bool $customOnly = false): array
    {
        return PaypalTx::row($id, $code, '2026-11-07T12:00:00Z', '-30.00', null, $no, 'S', $customOnly ? ['custom_field' => self::VPG_CUSTOM] : []);
    }

    /** A £14 dispute fee referring to a PayPal id, with its own invoice or none. */
    private static function fee(string $id, string $ref, ?string $no): array
    {
        return PaypalTx::row($id, 'T0106', '2026-11-07T13:00:00Z', '-14.00', null, $no, 'S', ['paypal_reference_id' => $ref]);
    }

    // ---- HIGH: rows of one rank compared by their known shops and known numbers apart -----------------------------------------------------

    public function testRowsOfOneRankAgreeWhenEveryKnownShopAndNumberAgrees(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([
            // Codex's case, both orders: Vape and Go's order 1312001, and Vape and Go with no number; the fee's own invoice 1312001
            self::cb('6P00000000000A01', 'T1106', '1312001'), self::cb('6P00000000000A01', 'T1201', null, true), self::fee('6P00000000000F01', '6P00000000000A01', '1312001'),
            self::cb('6P00000000000B01', 'T1201', null, true), self::cb('6P00000000000B01', 'T1106', '1312001'), self::fee('6P00000000000F02', '6P00000000000B01', '1312001'),
            // the same, the fee with no invoice of its own: the one known number
            self::cb('6P00000000000C01', 'T1201', null, true), self::cb('6P00000000000C01', 'T1106', '1312001'), self::fee('6P00000000000F03', '6P00000000000C01', null),
            // two known numbers differ (the fee's own invoice agreeing with one changes nothing): held
            self::cb('6P00000000000D01', 'T1106', '1312001'), self::cb('6P00000000000D01', 'T1201', '1312002'), self::fee('6P00000000000F04', '6P00000000000D01', '1312001'),
            // two known shops differ: held
            self::cb('6P00000000000E01', 'T1201', '5457'), self::cb('6P00000000000E01', 'T1106', null, true), self::fee('6P00000000000F05', '6P00000000000E01', null),
        ]));
        foreach (['6P00000000000F01', '6P00000000000F02', '6P00000000000F03'] as $id) {
            self::assertSame(['counts', 'order', '1001', 1400], self::of($id, ['count_state', 'alloc_dest', 'alloc_order_ref', 'alloc_pence']), "{$id}: every known value agrees");
        }
        foreach (['6P00000000000F04', '6P00000000000F05'] as $id) {
            self::assertSame(['look', 'ref_conflict', null], self::of($id, ['count_state', 'look_reason', 'alloc_dest']), "{$id}: two known values differ");
        }
        self::assertSame(0, self::fees()->rematch('vapeandgo'));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- MEDIUM: a new row of a referenced PayPal id re-decides the rows that refer to it ----------------------------------------------

    public function testANewRowOfAReferencedIdReDecidesTheRowsThatReferToIt(): void
    {
        $this->shops();
        // Hayati Vapes' form takes Vape and Go's seven-digit numbers too (set before anything is read): such an invoice is two shops' (overlap)
        $this->setting('profit.paypal_form_hayati', '"^([0-9]{7})-[0-9]{10}$"');
        $rows = [self::cb('6Q00000000000X01', 'T1201', '5457'), self::fee('6Q00000000000F01', '6Q00000000000X01', null)];
        $http = new FakePaypalHttp($rows);
        self::readPp($http);
        self::assertSame(['counts', 'order', '2001', $this->ef], self::of('6Q00000000000F01', ['count_state', 'alloc_dest', 'alloc_order_ref', 'alloc_channel']));
        // a later read gives a second row of that PayPal id: a DENIED chargeback whose invoice two shops' forms take; its own first decision
        // (not counted, no shop) moves none of its own fields, yet the fee decided before is decided again with it (round 6b: a new row of a
        // referenced id marks the rows that refer to it): which shop it belongs to is not clear now: held
        $http->set([...$rows, PaypalTx::row('6Q00000000000X01', 'T1106', '2026-11-07T12:00:00Z', '-30.00', null, '1312001', 'D')]);
        self::readPp($http);
        self::assertSame(['not_counted', null, 'overlap'], self::of('6Q00000000000X01', ['count_state', 'form_shop', 'invoice_state'], 'T1106'));
        self::assertSame(['look', 'ref_unresolved', null], self::of('6Q00000000000F01', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame(0, self::fees()->rematch('vapeandgo'));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- MEDIUM: a conflict of one answer settled only by a row whose day is within the window's proven days at BOTH ends -----------------

    public function testAConflictIsSettledOnlyByARowWithinTheProvenDaysAtBothEnds(): void
    {
        $this->shops();
        $c20 = ['transaction_updated_date' => '2026-11-07T23:10:00Z'] + PaypalTx::row('6R00000000000C01', 'T1201', '2026-11-07T23:00:00Z', '-20.00', null, '1312001');
        $c30 = ['transaction_updated_date' => '2026-11-07T23:20:00Z'] + PaypalTx::row('6R00000000000C01', 'T1201', '2026-11-07T23:00:00Z', '-30.00', null, '1312001');
        self::readPp(new FakePaypalHttp([$c20, $c30]), '2026-11-01', '2026-11-07');
        self::assertSame(['look', 2], self::of('6R00000000000C01', ['count_state', 'pp_conflict']));
        // an 8-14 Nov answer that starts an hour early and gives the 7th's row once: it proves 8-14 Nov, not the 7th: no settling
        $http = new FakePaypalHttp([]);
        $http->alter = static function (array $a, array $q) use ($c30): array {
            $a['start_date'] = '2026-11-07T22:00:00Z';
            $a['transaction_details'][] = ['transaction_info' => $c30];
            $a['total_items'] = count($a['transaction_details']);
            $a['total_pages'] = 1;
            return $a;
        };
        self::readPp($http, '2026-11-08', '2026-11-14');
        self::assertSame(['look', 2], self::of('6R00000000000C01', ['count_state', 'pp_conflict']));
        // once in a window that proves the 7th: settled, counted
        self::readPp(new FakePaypalHttp([$c30]), '2026-11-01', '2026-11-07');
        self::assertSame(['counts', 0, 3000], self::of('6R00000000000C01', ['count_state', 'pp_conflict', 'alloc_pence']));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- MEDIUM: rematch's marks wait for a row no longer than the run's time ------------------------------------------------------------

    public function testRematchsMarksNeverWaitForARowPastTheDeadline(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([PaypalTx::sale('6S00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001')]));
        // a form edited: the sale is decided again (rematch marks it); another session holds its row (a PayPal store, a guarded write)
        $this->setting('profit.paypal_form_hayati', '"^faaeeb-([0-9]{1,7})$"');
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT id FROM paypal_txn WHERE txn_id = '6S00000000000S01' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 10');
        $t = microtime(true);
        try {
            self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null, microtime(true) + 2.5);
            self::fail('the held row ends the decisions');
        } catch (CwException $e) {
            self::assertContains($e->errorCode, ['busy', 'paypal_deadline']);
        } finally {
            $took = microtime(true) - $t;
            $other->pdo()->rollBack();
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertLessThan(4.0, $took, 'the mark waited for no longer than the time left (never the session\'s 10 s)');
        // the next run decides it
        self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
        self::assertSame(['look', 'form_unread', 0], self::of('6S00000000000S01', ['count_state', 'look_reason', 'due']));
    }
}
