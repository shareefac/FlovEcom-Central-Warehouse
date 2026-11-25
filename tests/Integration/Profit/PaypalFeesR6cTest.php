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
 * CW-1172 round 6c (the Codex astra narrow review of 39cdba0, runs/codex_25nov/paypal_r6b_astra_final.md; the lead's decisions of 25 Nov
 * ~23:07; docs/decisions.md PPF81): MADE-UP rows. A row behind a reference counts as "no shop" or "no order number" only when it NEVER had one:
 * one that lost its shop or its number (denied and its invoice gone, its invoice dropped) holds the rows that refer to it, never lets them
 * go to another candidate's shop or order; a row of a queued order held elsewhere is the retryable "busy" also with no deadline.
 */
final class PaypalFeesR6cTest extends StockTestCase
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

    // ---- HIGH: a row that LOST its shop or its number is never taken for one that never had one -------------------------------------

    public function testARowThatLostItsShopOrNumberHoldsTheRowsThatReferToIt(): void
    {
        $this->shops();
        $mk = static fn (string $id, string $code, string $upd, ?string $no, string $status = 'S', array $over = []): array => ['transaction_updated_date' => $upd,
            'paypal_reference_id' => null] + PaypalTx::row($id, $code, '2026-11-07T12:00:00Z', '-30.00', null, $no, $status, $over);
        $first = [
            // Codex's case: a chargeback of Vape and Go's order 1312001, the £14 fee referring to it (no invoice of its own): on 1312001
            $mk('6T00000000000X01', 'T1201', '2026-11-07T12:00:00Z', '1312001'), self::fee('6T00000000000F01', '6T00000000000X01', null),
            // its number lost: the same chargeback later with Vape and Go's custom field only
            $mk('6T00000000000Y01', 'T1201', '2026-11-07T12:00:00Z', '1312001'), self::fee('6T00000000000F02', '6T00000000000Y01', null),
            // one alone, later denied and its invoice gone
            $mk('6T00000000000W01', 'T1201', '2026-11-07T12:00:00Z', '1312001'), self::fee('6T00000000000F04', '6T00000000000W01', null),
        ];
        $http = new FakePaypalHttp($first);
        self::readPp($http);
        foreach (['6T00000000000F01', '6T00000000000F02', '6T00000000000F04'] as $id) {
            self::assertSame(['counts', 'order', '1001'], self::of($id, ['count_state', 'alloc_dest', 'alloc_order_ref']), "{$id} on 1312001 first");
        }
        // the later read: X01 denied, its invoice gone, and a second chargeback of that id names Electrofag's 5457; Y01's invoice dropped (Vape
        // and Go's custom field only), and a second chargeback of that id names Vape and Go's 1312002; W01 denied, its invoice gone
        $http->set([
            $mk('6T00000000000X01', 'T1201', '2026-11-08T12:00:00Z', null, 'D'), self::fee('6T00000000000F01', '6T00000000000X01', null),
            $mk('6T00000000000X01', 'T1106', '2026-11-08T12:00:00Z', '5457'),
            $mk('6T00000000000Y01', 'T1201', '2026-11-08T12:00:00Z', null, 'S', ['custom_field' => self::VPG_CUSTOM]), self::fee('6T00000000000F02', '6T00000000000Y01', null),
            $mk('6T00000000000Y01', 'T1106', '2026-11-08T12:00:00Z', '1312002'),
            $mk('6T00000000000W01', 'T1201', '2026-11-08T12:00:00Z', null, 'D'), self::fee('6T00000000000F04', '6T00000000000W01', null),
        ]);
        self::readPp($http);
        self::assertSame(['not_counted', null], self::of('6T00000000000X01', ['count_state', 'form_shop'], 'T1201'), 'the denied chargeback keeps no shop of its own now');
        foreach (['6T00000000000F01' => '5457', '6T00000000000F02' => '1312002'] as $id => $never) {
            self::assertSame(['look', 'ref_unresolved', null], self::of($id, ['count_state', 'look_reason', 'alloc_dest']), "{$id}: held, never booked to {$never}");
        }
        self::assertSame(['look', null], self::of('6T00000000000F04', ['count_state', 'alloc_dest']), 'the one alone: held');
        self::assertSame(0, self::fees()->rematch('vapeandgo'));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- LOW: a row of a queued order held elsewhere is "busy" also with no deadline --------------------------------------------------

    public function testARowHeldElsewhereIsBusyAlsoWithNoDeadline(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([PaypalTx::row('6U00000000000C01', 'T1201', '2026-11-07T12:00:00Z', '-30.00', null, '1312001')]));
        // a £30 refund of its order recorded inside a transaction begun outside Db::transaction(): the order queued, nothing done after it
        self::$db->pdo()->beginTransaction();
        try {
            (new OrderSnapshots(self::$db))->store($this->vpg, '1001', ['v' => 1, 'version' => 2, 'order_no' => '1312001', 'status' => 'completed',
                'placed_at' => '2026-10-27T09:00:00Z', 'paid_at' => '2026-11-03T10:00:00Z', 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
                'lines' => [['line_id' => '8001001', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 6000, 'discount_inc' => 0, 'cancelled' => false,
                    'refund_inc' => 0, 'free' => false]],
                'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => 3000, 'grand_inc' => 6499,
                'parcels' => []], 'api');
            self::$db->pdo()->commit();
        } finally {
            if (self::$db->pdo()->inTransaction()) {
                self::$db->pdo()->rollBack();
            }
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'));
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT id FROM paypal_txn WHERE txn_id = '6U00000000000C01' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 2');
        try {
            self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
            self::fail('the held row ends the decisions');
        } catch (CwException $e) {
            self::assertSame('busy', $e->errorCode, 'the retryable busy, never MySQL\'s own error');
        } finally {
            $other->pdo()->rollBack();
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM paypal_order_due'), 'still queued');
        self::fees()->decideAll(Caller::system('paypal_test'), 'vapeandgo', null);
        self::assertSame(['look', 'refund_not_on_paypal', 0], self::of('6U00000000000C01', ['count_state', 'look_reason', 'due']));
    }
}
