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
use CW\Tests\Support\PaypalTx;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Words;

/**
 * CW-1172 round 5 (the Codex astra final of 66b62d5, runs/codex_25nov/paypal_r4_final_astra_final.md: NO GO, 3 High + Mediums + Lows; the
 * lead's decisions of runs/paypal_round5_brief.md; docs/decisions.md PPF58-): MADE-UP rows. A shop switched off takes its held chargebacks'
 * fees out of profit; a fee keeps its own invoice number when its sale gives its shop and no number; a form changed and put back never mixes
 * captures of two forms; two versions in one answer are held until a clean read says which; an answer with a repeat and a wrong count is
 * stored, its window unproven; a denied row decided under the forms of now is not marked again; a page's interval is compared to the fraction
 * of a second; what a run stored but could not decide by its deadline is decided with a budget of its own; a snapshot never waits for a PayPal
 * row and its chargebacks are decided once the OUTERMOST transaction commits; an older version with other money is marked for the gate; the
 * looks alert keeps its last send across episodes and says its reasons in staff words; work after a commit runs once, never on a rollback.
 */
final class PaypalFeesR5Test extends StockTestCase
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

    /** @return array<string, mixed> the row of a PayPal id */
    private static function row(string $id): array
    {
        return (array) self::$db->one('SELECT * FROM paypal_txn WHERE txn_id = ? ORDER BY id LIMIT 1', [$id]);
    }

    /** @param list<string> $cols @return list<mixed> */
    private static function of(string $id, array $cols): array
    {
        $r = self::row($id);
        // a column the schema does not have reads '(no column)' (the red run on 66b62d5); a NULL reads null
        return array_map(static fn (string $c): mixed => !array_key_exists($c, $r) ? '(no column)' : (in_array($c, ['alloc_pence', 'refund_seen_pence', 'candidates',
            'alloc_channel', 'due', 'pp_conflict', 'last_run', 'older_run', 'fee_pence'], true) && $r[$c] !== null ? (int) $r[$c] : $r[$c]), $cols);
    }

    private static function cb(string $id, string $at, string $amount, ?string $no, string $status = 'S', array $over = []): array
    {
        return PaypalTx::row($id, 'T1201', $at, $amount, null, $no, $status, $over);
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

    // ---- HIGH (switch-off): a shop's first fee day set, moved or cleared re-decides its held chargebacks: their fees leave profit with it ----

    public function testAShopSwitchedOffTakesItsHeldChargebacksFeesOutOfProfitAndDecideAgreesWithARebuild(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000); // £30 refunded in CW, not on PayPal
        self::order($this->vpg, '1004', '1312009', '2026-11-03T11:00:00Z');
        self::order($this->vpg, '1005', '1312009', '2026-11-03T12:00:00Z');           // two orders of one number
        self::readPp(new FakePaypalHttp([PaypalTx::row('5A00000000000C01', 'T1201', '2026-11-07T12:00:00Z', '-30.00', '-14.00', '1312001'),
            PaypalTx::row('5A00000000000C02', 'T1201', '2026-11-07T13:00:00Z', '-30.00', '-14.00', '1312009')]));
        $held = static fn (): array => [self::of('5A00000000000C01', ['count_state', 'look_reason', 'alloc_dest', 'alloc_pence']),
            self::of('5A00000000000C02', ['count_state', 'look_reason', 'alloc_dest', 'alloc_pence'])];
        self::assertSame([['look', 'refund_not_on_paypal', 'order', 1400], ['look', 'order_ambiguous', 'shop', 1400]], $held());
        self::assertSame(['2026-11-07|fee_cost' => 1400, '2026-11-07|fee_other' => 1400], self::days($this->vpg));
        $out = [['before_start', null, null, 0], ['before_start', null, null, 0]];
        // Vape and Go's first fee day moved past their day: both are before it now, their £14 fees leave its profit
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-08"');
        self::decideAll();
        self::assertSame($out, $held());
        self::assertSame([], self::days($this->vpg));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
        // put back: held again, their fees counted again
        $this->setting('profit.paypal_from_vapeandgo', '"2026-11-01"');
        self::decideAll();
        self::assertSame([['look', 'refund_not_on_paypal', 'order', 1400], ['look', 'order_ambiguous', 'shop', 1400]], $held());
        // the quick stop (every shop of the shared account cleared): --decide takes every held fee out, and a rebuild of the days agrees
        $this->setting('profit.paypal_from_vapeandgo', '""');
        $this->setting('profit.paypal_from_electrofag', '""');
        self::decideAll();
        self::assertSame($out, $held());
        self::assertSame([], self::days($this->vpg));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H2: a fee's own invoice number stands when its sale gives its shop and no number ------------------------------------------------

    public function testAFeeKeepsItsOwnInvoiceNumberWhenItsSaleGivesItsShopAndNoNumber(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([
            // a sale whose shop is known by its custom field alone (no invoice): Vape and Go's "not on an order" line
            PaypalTx::row('5B00000000000S01', 'T0006', '2026-11-03T10:00:00Z', '30.00', '-0.90', null, 'S', ['custom_field' => '1500380-1763700000']),
            // its £14 dispute fee carries Vape and Go's invoice of order 1312001: the same shop, and the only number there is: order 1001
            PaypalTx::row('5B00000000000F01', 'T0114', '2026-11-08T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '5B00000000000S01',
                'invoice_id' => '1312001-1763799999']),
            // a fee whose own number is another than its sale's (both known): a conflict, as before
            PaypalTx::sale('5B00000000000S02', '2026-11-04T10:00:00Z', '50.00', '-1.20', '1312002'),
            PaypalTx::row('5B00000000000F02', 'T0114', '2026-11-09T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '5B00000000000S02',
                'invoice_id' => '1312003-1763799999']),
        ]));
        self::assertSame(['counts', 'shop', 'no_number', 'vapeandgo', null], self::of('5B00000000000S01', ['count_state', 'alloc_dest', 'match_state', 'form_shop', 'order_no']));
        self::assertSame(['counts', 'order', '1001', 1400, '2026-11-08'], self::of('5B00000000000F01', ['count_state', 'alloc_dest', 'alloc_order_ref', 'alloc_pence', 'alloc_day']));
        self::assertSame(['look', 'ref_conflict'], self::of('5B00000000000F02', ['count_state', 'look_reason']));
        self::assertSame(['2026-11-03|fee_other' => 90, '2026-11-04|fee_cost' => 120, '2026-11-08|fee_cost' => 1400], self::days($this->vpg));
        // converged: deciding again changes nothing (the fee is not marked again and again)
        self::assertSame(0, self::fees()->rematch('vapeandgo'));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H3: a form changed and put back never mixes captures of two forms (Codex's 15... / 13... / 15... sequence) ----------------------

    public function testAFormChangedAndPutBackNeverMixesCapturesMadeByTwoForms(): void
    {
        $this->shops();
        // Vape and Go's form takes numbers starting 15: this sale's custom field (15...) is Vape and Go's, its invoice (13...) no shop's
        $this->setting('profit.paypal_form_vapeandgo', '"^(15[0-9]{5})-[0-9]{10}$"');
        $http = new FakePaypalHttp([PaypalTx::row('5C00000000000S01', 'T0006', '2026-11-05T10:00:00Z', '40.00', '-1.20', null, 'S',
            ['invoice_id' => '1312001-1763700000', 'custom_field' => '1500380-1763700000'])]);
        self::readPp($http);
        self::assertSame(['dropped', 'order', 'counts', 'shop', 'no_number', 120], self::of('5C00000000000S01',
            ['invoice_state', 'custom_state', 'count_state', 'alloc_dest', 'match_state', 'alloc_pence']));
        // the form changed to take numbers starting 13 and the SAME version read again: under it the invoice is Vape and Go's and the custom
        // field no shop's; keeping the custom field's capture beside the invoice's would mix two forms: the row keeps the earlier form's
        // captures WHOLE, with that form, and is held (form_unread) until one read under the forms of now gives all of them
        $this->setting('profit.paypal_form_vapeandgo', '"^(13[0-9]{5})-[0-9]{10}$"');
        self::readPp($http);
        self::assertSame(['look', 'form_unread', null, 'dropped', 'order'], self::of('5C00000000000S01',
            ['count_state', 'look_reason', 'alloc_dest', 'invoice_state', 'custom_state']));
        // the first form put back, nothing read: decided by what that form captured on one read: its "not on an order" line, never order 1312001
        $this->setting('profit.paypal_form_vapeandgo', '"^(15[0-9]{5})-[0-9]{10}$"');
        self::decideAll();
        self::assertSame(['counts', 'shop', 'no_number', null, 120], self::of('5C00000000000S01',
            ['count_state', 'alloc_dest', 'match_state', 'alloc_order_ref', 'alloc_pence']));
        self::assertSame(['2026-11-05|fee_other' => 120], self::days($this->vpg));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- Medium: two versions of a row in ONE answer are held until a clean read says which -------------------------------------------

    public function testTwoVersionsInOneAnswerAreHeldUntilACleanReadSaysWhich(): void
    {
        $this->shops();
        $c20 = ['transaction_updated_date' => '2026-11-07T13:00:00Z'] + self::cb('5D00000000000C01', '2026-11-07T12:00:00Z', '-20.00', '1312001');
        $c30 = ['transaction_updated_date' => '2026-11-07T14:00:00Z'] + self::cb('5D00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001');
        $http = new FakePaypalHttp([$c20, $c30]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['look', 'pp_conflict', null, 2], self::of('5D00000000000C01', ['count_state', 'look_reason', 'alloc_dest', 'pp_conflict']));
        // the next answer gives that £30 version alone (the same update time): it says which: counted
        $http->set([$c30]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['counts', 'order', '1001', 3000, 0], self::of('5D00000000000C01', ['count_state', 'alloc_dest', 'alloc_order_ref', 'alloc_pence', 'pp_conflict']));
        // two versions of the SAME update time in one answer: held; a clean read of one of them says which
        $d20 = ['transaction_updated_date' => '2026-11-07T15:00:00Z'] + self::cb('5D00000000000C02', '2026-11-07T12:30:00Z', '-20.00', '1312002');
        $d30 = ['transaction_amount' => ['currency_code' => 'GBP', 'value' => '-30.00']] + $d20;
        $http->set([$c30, $d20, $d30]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['look', 'pp_conflict', 2], self::of('5D00000000000C02', ['count_state', 'look_reason', 'pp_conflict']));
        // the next answer gives both again, the other one first: still two contents in one answer (never a contradiction between reads)
        $http->set([$c30, $d30, $d20]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['look', 'pp_conflict', 2], self::of('5D00000000000C02', ['count_state', 'look_reason', 'pp_conflict']));
        $http->set([$c30, $d30]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['counts', '1002', 3000, 0], self::of('5D00000000000C02', ['count_state', 'alloc_order_ref', 'alloc_pence', 'pp_conflict']));
        // two clean reads that disagree on one version: a contradiction between reads, held until a newer version (round 3's rule)
        $http->set([$c30, ['transaction_amount' => ['currency_code' => 'GBP', 'value' => '-25.00']] + $d30]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(['look', 'pp_conflict', 1], self::of('5D00000000000C02', ['count_state', 'look_reason', 'pp_conflict']));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- Medium: an answer with a repeat and a wrong count is stored, its window unproven -----------------------------------------------

    public function testAnAnswerWithARepeatAndAWrongCountIsStoredItsWindowUnproven(): void
    {
        $this->shops();
        $a = PaypalTx::sale('5E00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$a, $a, PaypalTx::row('5E00000000000F01', 'T0114', '2026-11-04T12:00:00Z', '-60.00', null, '1312002')]);
        // PayPal declares two transactions and gives three (A twice): never the whole window refused night after night
        $http->alter = static function (array $ans, array $q): array {
            $ans['total_items'] = 2;
            return $ans;
        };
        $r = self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame([1, ['a row given twice' => 1], null], [$r['repeated'], $r['unproven'], $r['covered']]);
        self::assertSame(PaypalApi::READS, count(array_filter($http->calls, static fn (array $c): bool => $c['path'] === PaypalApi::SEARCH_PATH)), 'read again first');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE txn_id = '5E00000000000S01'"));
        self::assertSame(['counts', 120], self::of('5E00000000000S01', ['count_state', 'alloc_pence']));
        self::assertSame(['counts', '1002', 6000], self::of('5E00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence']), 'the £60 fee is stored and counted');
        self::assertSame(['ok', null], array_values((array) self::$db->one('SELECT state, covered_to FROM paypal_run ORDER BY id DESC LIMIT 1')));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- Medium: a denied row decided under the forms of now is not marked again; its fee still follows it ---------------------------

    public function testADeniedRowDecidedUnderTheFormsOfNowIsNotMarkedAgainAndItsFeeStillFollowsIt(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([self::cb('5F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001', 'D'),
            PaypalTx::row('5F00000000000F01', 'T0114', '2026-11-07T13:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '5F00000000000C01'])]);
        self::readPp($http);
        self::assertSame(['not_counted'], self::of('5F00000000000C01', ['count_state']));
        self::assertSame(['counts', '1001', 1400], self::of('5F00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence']));
        // a form edited: the denied row's capture is of other forms now; decided once under the forms of now (still not counted), never again
        // and again at every rematch (beyond the days read again it would never be refreshed: a backlog repeated at every pass)
        $this->setting('profit.paypal_form_hayati', '"^faaeeb-([0-9]{1,7})$"');
        self::decideAll('2026-12-15 05:20:00');
        self::assertSame(['not_counted'], self::of('5F00000000000C01', ['count_state']));
        self::assertSame(['look', 'ref_unresolved', null], self::of('5F00000000000F01', ['count_state', 'look_reason', 'alloc_dest']), 'its parent read under other forms');
        self::assertSame(0, self::fees('2026-12-15 05:20:00')->rematch('vapeandgo'), 'a terminal row decided under the forms of now is not marked again');
        // read under the forms of now: its fee follows it again (its children are still due again when it is read)
        self::readPp($http, '2026-11-07', '2026-11-07', '2026-12-15 05:20:00');
        self::assertSame(['counts', '1001', 1400], self::of('5F00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence']));
        self::assertSame(0, self::fees('2026-12-15 05:20:00')->rematch('vapeandgo'));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H7: a page's start and end compared at the precision PayPal gives; the real shapes prove as before ----------------------------

    public function testAPagesIntervalIsComparedToTheFractionOfASecond(): void
    {
        $this->shops();
        $covered = static fn (): ?string => self::$db->value('SELECT covered_to FROM paypal_run ORDER BY id DESC LIMIT 1');
        $rows = [PaypalTx::sale('5G00000000000S01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')];
        $http = new FakePaypalHttp($rows);
        // a page starting 0.9 s after the start asked for (a £60 fee at 00:00:00.1 may be left out): nothing of its window proven
        $http->alter = static function (array $a, array $q): array {
            $a['start_date'] = gmdate('Y-m-d\TH:i:s', (new \DateTimeImmutable($q['start_date']))->getTimestamp()) . '.900Z';
            return $a;
        };
        $r = self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertNull($covered(), 'a start 0.9 s late proves nothing');
        self::assertSame(['a page starting later than asked' => 1], $r['unproven']);
        // an end 0.1 s short of the end asked for: its last day is not proven
        $http->alter = static function (array $a, array $q): array {
            $a['end_date'] = gmdate('Y-m-d\TH:i:s', (new \DateTimeImmutable($q['end_date']))->getTimestamp() - 1) . '.900Z';
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame('2026-11-06', $covered());
        // PayPal's real shapes (the lead's probe of 25 Nov, production's account): a past window answered with exactly the interval asked for
        // (2026-11-23T00:00:00Z .. 2026-11-24T23:59:59Z) and its data complete to 13:59:59Z the next day: proven whole
        $real = new FakePaypalHttp($rows, '', null, '2026-11-25T13:59:59Z');
        $real->alter = static function (array $a, array $q): array {
            $a['start_date'] = gmdate('Y-m-d\TH:i:s\Z', (new \DateTimeImmutable($q['start_date']))->getTimestamp());
            $a['end_date'] = gmdate('Y-m-d\TH:i:s\Z', min((new \DateTimeImmutable($q['end_date']))->getTimestamp(), (new \DateTimeImmutable('2026-11-25T13:59:59Z'))->getTimestamp()));
            return $a;
        };
        self::readPp($real, '2026-11-23', '2026-11-24', '2026-11-25 16:28:00');
        self::assertSame('2026-11-24', $covered());
        // a window ending now: PayPal ends it at its refresh time (2.5 hours behind): the 24th proven, today not
        self::readPp($real, '2026-11-24', '2026-11-25', '2026-11-25 16:28:00');
        self::assertSame('2026-11-24', $covered());
    }

    // ---- M2: what a run stored but could not decide by its deadline is decided at once with a budget of its own ----------------------

    public function testRowsStoredButNotDecidedByTheDeadlineAreDecidedWithABudgetOfTheirOwn(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([PaypalTx::sale('5K00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001'),
            PaypalTx::sale('5K00000000000S02', '2026-11-04T10:00:00Z', '50.00', '-1.20', '1312002'),
            PaypalTx::row('5K00000000000F03', 'T0114', '2026-11-05T12:00:00Z', '-60.00', null, '1312003')]);
        // each guarded write slow (0.7 s, one row each): the run's deadline (1.2 s away, 0.5 s of it kept for decisions) comes during the
        // decisions of the window it stored; what is left is decided at once with a budget of its own (5 s here), no PayPal call, never left
        // for the next night
        $fees = new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::PP_NOW, new \DateTimeZone('UTC')),
            static function (int $n, float $s): void {
                usleep(700_000);
            }, 1, 0.5, 5.0);
        try {
            $fees->run(Caller::system('paypal_test'), self::api($http), '2026-11-01', '2026-11-07', 'backfill', microtime(true) + 1.2);
            self::fail('the deadline ends the run');
        } catch (CwException $e) {
            self::assertSame('paypal_deadline', $e->errorCode);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'), 'every stored row decided');
        self::assertSame(['counts', 6000], self::of('5K00000000000F03', ['count_state', 'alloc_pence']), 'the £60 fee counts tonight');
        self::assertSame(['failed', '2026-11-07'], array_values((array) self::$db->one('SELECT state, covered_to FROM paypal_run ORDER BY id DESC LIMIT 1')));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- M1 + the row lock: a snapshot never waits for a PayPal row; its chargebacks are decided once the OUTERMOST transaction commits ----

    public function testASnapshotNeverWaitsForAPaypalRowAndItsChargebacksAreDecidedOnceTheOutermostTransactionCommits(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([self::cb('5H00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001')]));
        self::assertSame(['counts', 3000], self::of('5H00000000000C01', ['count_state', 'alloc_pence']));
        $queued = fn (): int => (int) self::$db->value("SELECT COUNT(*) FROM paypal_order_due WHERE channel_id = ? AND order_ref = '1001'", [$this->vpg]);
        // a PayPal store holds the chargeback's row while the shop records a £30 refund of 1001: the snapshot writes no PayPal row (its order is
        // queued in paypal_order_due), so it commits at once, holding no order or day guard while it waits for nothing
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT id FROM paypal_txn WHERE txn_id = '5H00000000000C01' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 3');
        $t = microtime(true);
        try {
            self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
            $took = microtime(true) - $t;
            self::assertSame(1, $queued(), 'its order queued, durable with the snapshot');
            self::assertSame(['counts', 3000, 0], self::of('5H00000000000C01', ['count_state', 'alloc_pence', 'due']), 'the row held: not decided yet');
        } finally {
            $other->pdo()->rollBack();
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        // a wait for the held row would last the 3 s set above and fail the snapshot (busy): well under that, it waited for none
        self::assertLessThan(2.5, $took, 'the snapshot waited for no PayPal row');
        self::assertSame(3000, (int) self::$db->value("SELECT order_refund_inc FROM sale_order WHERE channel_id = ? AND order_ref = '1001'", [$this->vpg]));
        // the next PayPal run (or --decide) takes the queue first
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal', 0, 0], self::of('5H00000000000C01', ['count_state', 'look_reason', 'alloc_pence', 'due']));
        self::assertSame(0, $queued());
        // a snapshot inside an enclosing transaction: nothing decided before the OUTERMOST commit, and decided right after it (never dropped)
        self::$db->transaction(function (): void {
            self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 3, 0);
            self::assertSame(['look', 0], self::of('5H00000000000C01', ['count_state', 'due']), 'not before the commit');
        });
        self::assertSame(['counts', 3000, 0], self::of('5H00000000000C01', ['count_state', 'alloc_pence', 'due']));
        self::assertSame(0, $queued());
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- M3: an older version with other money read by a run is marked on its row (the go-live gate STOPs on it) ---------------------

    public function testAnOlderVersionWithOtherMoneyIsMarkedOnItsRowForTheGate(): void
    {
        $this->shops();
        $new = ['transaction_updated_date' => '2026-11-04T12:00:00Z'] + PaypalTx::sale('5L00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $http = new FakePaypalHttp([$new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame([-120, null], self::of('5L00000000000S01', ['fee_pence', 'older_run']));
        // the next run returns an OLDER version with a £0.80 fee: CW keeps the newer £1.20, and the row says that run returned other money
        $http->set([['transaction_updated_date' => '2026-11-03T11:00:00Z', 'fee_amount' => ['currency_code' => 'GBP', 'value' => '-0.80']] + $new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        $run = self::lastRun();
        self::assertSame([-120, $run, $run], self::of('5L00000000000S01', ['fee_pence', 'last_run', 'older_run']));
        // an older version with the same money (only its update time older): nothing new to mark; the mark of the run that returned other
        // money stays (round 6, the lead's decision 5, PPF72: never cleared by a later run before the gate reads it)
        $http->set([['transaction_updated_date' => '2026-11-03T11:30:00Z'] + $new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame([-120, self::lastRun(), $run], self::of('5L00000000000S01', ['fee_pence', 'last_run', 'older_run']));
        // the older one again: the row says the newest run that returned it; then the kept version: the mark stays (round 6)
        $http->set([['transaction_updated_date' => '2026-11-03T11:00:00Z', 'fee_amount' => ['currency_code' => 'GBP', 'value' => '-0.80']] + $new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        $again = self::lastRun();
        self::assertSame($again, self::of('5L00000000000S01', ['older_run'])[0]);
        $http->set([$new]);
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame([-120, $again], self::of('5L00000000000S01', ['fee_pence', 'older_run']));
    }

    // ---- F-M2 again: the looks alert keeps its last send across episodes, its reasons in staff words ------------------------------------

    public function testTheLooksAlertKeepsItsLastSendAcrossEpisodesAndSaysItsReasonsInStaffWords(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        self::readPp(new FakePaypalHttp([self::cb('5M00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            PaypalTx::row('5M00000000000S02', 'T0006', '2026-11-04T10:00:00Z', '50.00', '-1.20', '1312002', 'S', ['custom_field' => 'faaeeb-3090'])]));
        $look = array_values(array_filter(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), static fn (array $p): bool => $p['kind'] === 'paypal_needs_look'));
        self::assertCount(1, $look);
        $line = AlertDispatch::line($look[0]);
        self::assertStringContainsString('2 PayPal rows that need a look', $line);
        self::assertStringContainsString(Words::of('PAYPAL_LOOK', 'refund_not_on_paypal'), $line);
        self::assertStringContainsString(Words::of('PAYPAL_LOOK', 'field_conflict'), $line);
        foreach (PaypalFees::LOOK_REASONS as $code) {
            self::assertStringNotContainsString($code, $line, 'staff never see a code');
            self::assertTrue(Words::has('PAYPAL_LOOK', $code), "a word for {$code}");
        }
        $this->setting('alerts.webhook_url', '"https://hooks.example.com/services/pp"');
        $this->setting('alerts.email_to', '""');
        $this->setting('alerts.repeat_minutes', '60');
        $sent = new class () implements AlertTransport {
            public function webhook(string $url, array $payload, int $timeoutSec): void
            {
            }

            public function email(string $to, string $subject, string $body): void
            {
            }
        };
        $t = new \DateTimeImmutable('2026-11-25T06:00:00Z');
        $run = static function (array $problems) use (&$t, $sent): array {
            return (new AlertDispatch(self::$db, $sent, static fn (): \DateTimeImmutable => $t))->run($problems, new Settings(self::$db), 'cw_test');
        };
        self::assertSame(['paypal_needs_look:vapeandgo'], $run($look)['sent']);
        // the look cleared at 07:00 and back at 08:00: a new episode, but said at most once a day: not again before 06:00 tomorrow
        $t = $t->modify('+1 hour');
        self::assertSame(['paypal_needs_look:vapeandgo'], $run([])['cleared']);
        $t = $t->modify('+1 hour');
        self::assertSame([], $run($look)['sent'], 'back within the day: not said again');
        $t = new \DateTimeImmutable('2026-11-26T05:59:00Z');
        self::assertSame([], $run($look)['sent']);
        $t = new \DateTimeImmutable('2026-11-26T06:00:00Z');
        self::assertSame(['paypal_needs_look:vapeandgo'], $run($look)['sent'], 'a day after it was said: again');
    }

    // ---- M1's mechanism: work after a commit runs once the OUTERMOST transaction commits, never on a rollback --------------------------

    public function testWorkAfterACommitRunsOnceTheOutermostTransactionCommitsNeverOnARollback(): void
    {
        $ran = [];
        self::assertTrue(self::$db->afterCommit(static function () use (&$ran): void {
            $ran[] = 'no transaction: at once';
        }));
        self::$db->transaction(static function (Db $db) use (&$ran): void {
            $db->transaction(static function (Db $db) use (&$ran): void {
                $db->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'after the outermost commit';
                });
            });
            self::assertSame(['no transaction: at once'], $ran, 'not when the inner one ends');
        });
        self::assertSame(['no transaction: at once', 'after the outermost commit'], $ran);
        try {
            self::$db->transaction(static function (Db $db) use (&$ran): void {
                $db->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'rolled back';
                });
                throw new \RuntimeException('the transaction fails');
            });
        } catch (\RuntimeException) {
        }
        // a failing piece of work never turns a committed transaction into a failed one
        self::assertSame(7, self::$db->transaction(static function (Db $db): int {
            $db->afterCommit(static function (): void {
                throw new \RuntimeException('the work after it fails');
            });
            return 7;
        }));
        // a transaction begun outside transaction() (raw PDO): its commit is not seen here, so nothing is queued (the work's durable record
        // stands for it: the queue of orders, taken by the next PayPal run)
        self::$db->pdo()->beginTransaction();
        self::assertFalse(self::$db->afterCommit(static function () use (&$ran): void {
            $ran[] = 'raw';
        }));
        self::$db->pdo()->commit();
        self::assertSame(['no transaction: at once', 'after the outermost commit'], $ran);
    }
}
