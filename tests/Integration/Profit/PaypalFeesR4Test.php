<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
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

/**
 * CW-1172 round 4, the last fix round (the Codex astra final of df9b8d3, runs/codex_25nov/paypal_final2_astra_final.md: NO GO, 8 High + 5
 * Medium; the Fable final review; the lead's decisions; docs/decisions.md PPF47-PPF56): MADE-UP rows. A form change holds every row read under
 * other forms whatever its age, and its children with it; an invoice and a custom field of two shops, or a row's own overlap, are a look, never
 * "the first field wins" nor its reference; a chargeback whose money is held still counts its fee; an order number of two orders holds a
 * chargeback's money; an order paid before the first day with ANY refund recorded in CW makes its chargeback a look; a day is proven only by
 * pages that say their whole interval; a catch-up resumes on the first day not proven; a deadline after a window is stored still decides it;
 * an order snapshot re-decides its chargebacks at once (marked due in its own transaction); a row twice in an answer is kept once or held,
 * never a failed window; the looks are said once a day in plain words; a backfill running is not "never read", a dead run is failed within
 * hours; no refund offset can stand.
 */
final class PaypalFeesR4Test extends StockTestCase
{
    private const KEY = ['client_id' => 'AclientIDmadeUp0123456789', 'client_secret' => 'EsecretMadeUp0123456789xyz', 'api_base' => 'https://api-m.paypal.com',
        'shop' => 'vapeandgo', 'account' => 'vapeandgo', 'merchant_id' => 'TESTACCOUNT'];
    private const PP_NOW = '2026-11-25 05:20:00';
    private const HAYATI = '"^faaeeb-([0-9]{1,6})$"';

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
        return array_map(static fn (string $c): mixed => in_array($c, ['alloc_pence', 'refund_seen_pence', 'candidates', 'alloc_channel', 'due'], true) && $r[$c] !== null
            ? (int) $r[$c] : $r[$c], $cols);
    }

    /** @return list<string> the health alert's kinds at the time given */
    private static function alerts(\DateTimeImmutable $now): array
    {
        return array_column(PaypalFees::health(self::$db, $now), 'kind');
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

    // ---- H3 (and H1's form-change case): a form change holds what was read under other forms, whatever its age, and its children -------

    public function testAFormChangeHoldsEveryRowReadUnderOtherFormsWhateverItsAgeAndItsChildrenWithIt(): void
    {
        $this->shops();
        $s = PaypalTx::sale('4A00000000000S01', '2026-11-05T10:00:00Z', '50.00', '-1.20', '1312003');
        $f = PaypalTx::row('4A00000000000F01', 'T0114', '2026-11-10T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '4A00000000000S01']);
        $http = new FakePaypalHttp([$s, $f]);
        self::readPp($http);
        self::assertSame([['counts', '1003', 120], ['counts', '1003', 1400]], [self::of('4A00000000000S01', ['count_state', 'alloc_order_ref', 'alloc_pence']),
            self::of('4A00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence'])]);
        // Hayati Vapes' form revised so that it also takes Vape and Go's numbers; decided on 15 Dec, past the days the nightly reads again: CW
        // holds no text to try the new form on, so the sale is held until PayPal gives it again, and its invoice-less fee is held with it
        $this->setting('profit.paypal_form_hayati', '"^([0-9]{7})-[0-9]{10}$"');
        self::decideAll('2026-12-15 05:20:00');
        self::assertSame(['look', 'form_unread', null], self::of('4A00000000000S01', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame(['look', 'ref_unresolved', null], self::of('4A00000000000F01', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame([], self::days($this->vpg), 'nothing of them stays on Vape and Go');
        // read again under the forms of now: two shops' forms take its invoice: still a look, its fee with it
        self::readPp($http, '2026-11-05', '2026-11-10', '2026-12-15 05:20:00');
        self::assertSame(['look', 'form_overlap'], self::of('4A00000000000S01', ['count_state', 'look_reason']));
        self::assertSame(['look', 'ref_unresolved'], self::of('4A00000000000F01', ['count_state', 'look_reason']));
        // the form put back: read under other forms again (held), then read under the forms of now: both count on 1003 again
        $this->setting('profit.paypal_form_hayati', self::HAYATI);
        self::decideAll('2026-12-15 05:20:00');
        self::assertSame(['look', 'form_unread'], self::of('4A00000000000S01', ['count_state', 'look_reason']));
        self::readPp($http, '2026-11-05', '2026-11-10', '2026-12-15 05:20:00');
        self::assertSame([['counts', '1003', 120], ['counts', '1003', 1400]], [self::of('4A00000000000S01', ['count_state', 'alloc_order_ref', 'alloc_pence']),
            self::of('4A00000000000F01', ['count_state', 'alloc_order_ref', 'alloc_pence'])]);
        self::assertDaysRebuild($this->vpg);
    }

    public function testARowReadWhileAFormWasNarrowedIsHeldOnceTheFormIsPutBackNeverLeftOnVapeAndGo(): void
    {
        $this->shops();
        $e2 = PaypalTx::sale('4B00000000000E02', '2026-11-20T09:30:00Z', '20.00', '-0.70', '5458');
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{5})-[0-9]{10}$"');
        $http = new FakePaypalHttp([$e2]);
        self::readPp($http);
        // under the forms of then no shop's form takes it: Vape and Go's "not on an order" line (the owner's line for money no form takes)
        self::assertSame(['dropped', 'shop', $this->vpg], self::of('4B00000000000E02', ['invoice_state', 'alloc_dest', 'alloc_channel']));
        // the form put back: read under other forms, so held at once (never left on Vape and Go's line until it is read again)
        $this->setting('profit.paypal_form_electrofag', '"^([0-9]{4,6})-[0-9]{10}$"');
        self::decideAll();
        self::assertSame(['look', 'form_unread', null], self::of('4B00000000000E02', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame([], self::days($this->vpg));
        // the nightly reads its day under the forms of now: Electrofag's order
        self::nightly($http);
        self::assertSame(['counts', '2002', $this->ef], self::of('4B00000000000E02', ['count_state', 'alloc_order_ref', 'alloc_channel']));
        self::assertDaysRebuild($this->vpg);
        self::assertDaysRebuild($this->ef);
    }

    // ---- H2: fields that name different shops, a row's own overlap: a look, never the first field nor the reference -------------------

    public function testFieldsNamingDifferentShopsOrARowsOwnOverlapAreALookNeverTheFirstFieldOrItsReference(): void
    {
        $this->shops();
        // a dispute fee's own invoice two shops' forms take (Hayati Vapes' form also takes this one tail), its sale Vape and Go's alone
        $this->setting('profit.paypal_form_hayati', '"^([0-9]{7})-1763799999$"');
        $http = new FakePaypalHttp([
            // invoice Vape and Go's 1312001, custom field Electrofag's order 5457: two shops: a look, its fee too
            PaypalTx::row('4C00000000000S01', 'T0006', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001', 'S', ['custom_field' => '5457-1763700000']),
            PaypalTx::row('4C00000000000F01', 'T0114', '2026-11-08T12:00:00Z', '-14.00', null, null, 'S', ['paypal_reference_id' => '4C00000000000S01']),
            // invoice 1312002, custom field another number of the same shop (as on every real row): Vape and Go's order 1002, never a look
            PaypalTx::row('4C00000000000S02', 'T0006', '2026-11-04T10:00:00Z', '50.00', '-1.20', '1312002', 'S', ['custom_field' => '1500002-1763700000']),
            PaypalTx::row('4C00000000000F02', 'T0114', '2026-11-09T12:00:00Z', '-14.00', null, null, 'S',
                ['paypal_reference_id' => '4C00000000000S02', 'invoice_id' => '1312002-1763799999', 'custom_field' => '1312002-1763799999']),
            // no invoice, a custom field of Vape and Go's form whose number is order 1003's: Vape and Go's, but the custom field's number is no
            // order number (the real rows: it is another number of the shop's own): its "not on an order" line, never order 1003
            PaypalTx::row('4C00000000000S03', 'T0006', '2026-11-03T11:00:00Z', '30.00', '-0.90', null, 'S', ['custom_field' => '1312003-1763700000']),
        ]);
        self::readPp($http);
        self::assertSame(['look', 'field_conflict', null], self::of('4C00000000000S01', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame(['look', 'ref_unresolved', null], self::of('4C00000000000F01', ['count_state', 'look_reason', 'alloc_dest']));
        self::assertSame(['counts', '1002', 120], self::of('4C00000000000S02', ['count_state', 'alloc_order_ref', 'alloc_pence']));
        self::assertSame(['look', 'form_overlap', null], self::of('4C00000000000F02', ['count_state', 'look_reason', 'alloc_dest']), 'its own overlap: never its sale\'s shop');
        self::assertSame(['counts', 'shop', 'no_number', 'vapeandgo', null], self::of('4C00000000000S03', ['count_state', 'alloc_dest', 'match_state', 'form_shop', 'order_no']));
        self::assertSame(['2026-11-03|fee_other' => 90, '2026-11-04|fee_cost' => 120], self::days($this->vpg));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H4: a chargeback's money held, its fee on the same row counts ----------------------------------------------------------------

    public function testAChargebackWhoseMoneyIsHeldStillCountsTheFeeOnItsOwnRow(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000); // £30 refunded in CW, not on PayPal
        $cb = PaypalTx::row('4D00000000000C01', 'T1201', '2026-11-07T12:00:00Z', '-30.00', '-14.00', '1312001');
        $http = new FakePaypalHttp([$cb]);
        self::readPp($http);
        self::assertSame(['look', 'refund_not_on_paypal', 'order', '1001', 1400, '2026-11-07', 3000], self::of('4D00000000000C01',
            ['count_state', 'look_reason', 'alloc_dest', 'alloc_order_ref', 'alloc_pence', 'alloc_day', 'refund_seen_pence']));
        self::assertSame(['2026-11-07|fee_cost' => 1400], self::days($this->vpg), 'the £14 fee on the chargeback\'s day, its £30 held');
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
        // PayPal's own £30 refund of 1001 read: the money is certain now, the whole row counts
        $http->set([$cb, PaypalTx::refund('4D00000000000R01', '2026-11-06T12:00:00Z', '-30.00', null, '1312001')]);
        self::readPp($http);
        self::assertSame(['counts', 4400], self::of('4D00000000000C01', ['count_state', 'alloc_pence']));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H5: a chargeback on a number of two orders: its money held until the order is known ----------------------------------------

    public function testAChargebackOnANumberOfTwoOrdersHoldsItsMoneyUntilTheOrderIsKnown(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000); // £30 refunded in CW
        self::order($this->vpg, '1009', '1312001', '2026-11-03T11:00:00Z');           // another order of the same number
        $http = new FakePaypalHttp([PaypalTx::row('4E00000000000C01', 'T1201', '2026-11-07T12:00:00Z', '-30.00', '-5.00', '1312001')]);
        self::readPp($http);
        self::assertSame(['look', 'order_ambiguous', 'ambiguous', 2, 'shop', 500, '2026-11-07'], self::of('4E00000000000C01',
            ['count_state', 'look_reason', 'match_state', 'candidates', 'alloc_dest', 'alloc_pence', 'alloc_day']));
        self::assertSame(['2026-11-07|fee_other' => 500], self::days($this->vpg), 'its fee on the "not on an order" line; its £30 never counted beside 1001\'s refund');
        // 1009 renumbered by its shop: the order is known, decided with 1001's refunds: its money a look for them, its fee on 1001
        self::order($this->vpg, '1009', '1312099', '2026-11-03T11:00:00Z', 2);
        self::decideAll();
        self::assertSame(['look', 'refund_not_on_paypal', 'order', '1001', 500], self::of('4E00000000000C01',
            ['count_state', 'look_reason', 'alloc_dest', 'alloc_order_ref', 'alloc_pence']));
        self::assertSame([], PaypalInvariants::check(self::$db));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- H6: an order paid before the first day: ANY refund recorded in CW (a cancelled line's too) makes its chargeback a look ----------

    public function testAnyRefundRecordedOnAnOrderPaidBeforeTheFirstDayMakesItsChargebackALook(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-10-28T10:00:00Z', 2, 0, [['cancelled' => true, 'refund_inc' => 1500]]);
        self::order($this->vpg, '1002', '1312002', '2026-11-04T09:00:00Z', 2, 0, [['cancelled' => true, 'refund_inc' => 1500]]);
        $http = new FakePaypalHttp([self::cb('4F00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            self::cb('4F00000000000C02', '2026-11-07T13:00:00Z', '-30.00', '1312002')]);
        self::readPp($http);
        self::assertSame(['look', 'refund_not_on_paypal', 0], self::of('4F00000000000C01', ['count_state', 'look_reason', 'alloc_pence']),
            'paid 28 Oct: a refund recorded on a cancelled line (it counts nothing) is still a refund CW cannot tie to PayPal');
        self::assertSame(['counts', 3000], self::of('4F00000000000C02', ['count_state', 'alloc_pence']), 'paid in November: a cancelled line\'s refund counts nothing (a4)');
        self::assertDaysRebuild($this->vpg);
    }

    private static function cb(string $id, string $at, string $amount, ?string $no, string $status = 'S', array $over = []): array
    {
        return PaypalTx::row($id, 'T1201', $at, $amount, null, $no, $status, $over);
    }

    // ---- H7: a day is proven only by pages that say their whole interval ---------------------------------------------------------------

    public function testADayIsProvenOnlyByPagesThatSayTheirWholeInterval(): void
    {
        $this->shops();
        $covered = static fn (): ?string => self::$db->value('SELECT covered_to FROM paypal_run ORDER BY id DESC LIMIT 1');
        $http = new FakePaypalHttp([PaypalTx::sale('4G00000000000S01', '2026-11-03T10:01:00Z', '50.00', '-1.20', '1312001')]);
        // every answer ends 59 seconds before the end asked for: no day is proven whose last minute is missing (no slack below the end)
        $http->alter = static function (array $a, array $q): array {
            $a['end_date'] = gmdate('Y-m-d\TH:i:s\Z', (new \DateTimeImmutable($q['end_date']))->getTimestamp() - 59);
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-14');
        self::assertSame('2026-11-13', $covered(), 'the 14th\'s last minute was not answered for');
        // a page that does not say where it ends, or where it starts: nothing of its window proven
        foreach (['end_date', 'start_date'] as $f) {
            $http->alter = static function (array $a, array $q) use ($f): array {
                unset($a[$f]);
                return $a;
            };
            self::readPp($http, '2026-11-01', '2026-11-07');
            self::assertNull($covered(), "a page without its {$f}");
        }
        // a page starting 30 seconds after the start asked for: nothing proven
        $http->alter = static function (array $a, array $q): array {
            $a['start_date'] = gmdate('Y-m-d\TH:i:s\Z', (new \DateTimeImmutable($q['start_date']))->getTimestamp() + 30);
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertNull($covered());
        // PayPal printing a LATER end than asked: proven
        $http->alter = static function (array $a, array $q): array {
            $a['end_date'] = gmdate('Y-m-d\TH:i:s\Z', (new \DateTimeImmutable($q['end_date']))->getTimestamp() + 1);
            return $a;
        };
        self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame('2026-11-07', $covered());
    }

    // ---- H8: a catch-up resumes on the first day not proven, night by night ------------------------------------------------------------

    public function testACatchUpResumesOnTheFirstDayNotProvenNightByNight(): void
    {
        $this->shops();
        $rows = [PaypalTx::sale('4H00000000000S01', '2026-11-01T10:00:00Z', '10.00', '-0.30', null),
            PaypalTx::row('4H00000000000F04', 'T0114', '2026-11-04T12:00:00Z', '-60.00', null, '1312002')];
        // every answer covers two days from its start at most (its end and its rows cut there); two windows a night (the third is refused)
        $short = static function (array $a, array $q): array {
            $cut = (new \DateTimeImmutable($q['start_date']))->modify('+2 days')->getTimestamp() - 1;
            if ((new \DateTimeImmutable($q['end_date']))->getTimestamp() > $cut) {
                $a['end_date'] = gmdate('Y-m-d\TH:i:s\Z', $cut);
                $a['transaction_details'] = array_values(array_filter($a['transaction_details'],
                    static fn (array $d): bool => (new \DateTimeImmutable((string) $d['transaction_info']['transaction_initiation_date']))->getTimestamp() <= $cut));
                $a['total_items'] = count($a['transaction_details']);
                $a['total_pages'] = $a['total_items'] > 0 ? 1 : 0;
            }
            return $a;
        };
        $night = static function (string $day, bool $nightly) use ($rows, $short): array {
            $n = 0;
            $http = new FakePaypalHttp($rows, '', static function (int $i, string $p, array $q) use (&$n): ?array {
                if ($p === PaypalApi::SEARCH_PATH && $q['page'] === '1') {
                    $n++;
                }
                return $n > 2 ? ['status' => 503, 'body' => 'down'] : null;
            }, $day . 'T02:00:00Z');
            $http->alter = $short;
            try {
                $nightly ? self::nightly($http, $day . ' 05:20:00') : self::readPp($http, '2026-11-01', '2026-11-24', $day . ' 05:20:00');
            } catch (CwException $e) {
                self::assertSame('paypal_unreachable', $e->errorCode);
            }
            return array_map(static fn (array $c): string => substr($c['query']['start_date'], 0, 10),
                array_values(array_filter($http->calls, static fn (array $c): bool => $c['path'] === PaypalApi::SEARCH_PATH && $c['query']['page'] === '1')));
        };
        $starts = $night('2026-11-25', false);
        self::assertSame(['2026-11-01', '2026-11-03', '2026-11-05'], array_slice($starts, 0, 3), 'each window starts on the first day the one before did not prove');
        self::assertSame('2026-11-04', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertSame(['counts', '1002', 6000], self::of('4H00000000000F04', ['count_state', 'alloc_order_ref', 'alloc_pence']), 'the 4th\'s £60 fee read the first night');
        $starts = $night('2026-11-26', true);
        self::assertSame('2026-11-05', $starts[0], 'the next night resumes on the 5th, never on the 1st again');
        self::assertSame('2026-11-08', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        $night('2026-11-27', true);
        self::assertSame('2026-11-12', PaypalFees::coveredUntil(self::$db, 'vapeandgo', '2026-11-01'));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- M2: a deadline after a window is stored still decides it --------------------------------------------------------------------

    public function testTheRunsDeadlineAfterAWindowIsStoredStillDecidesIt(): void
    {
        $this->shops();
        $rows = [PaypalTx::row('4I00000000000F01', 'T0114', '2026-11-03T12:00:00Z', '-60.00', null, '1312001'),
            PaypalTx::sale('4I00000000000S09', '2026-11-09T10:00:00Z', '10.00', '-0.30', null)];
        // PayPal slow: the first window's answer takes 0.8 s, the second's 1.3 s; the run's deadline is 2 s away, 1.5 s of it kept for decisions
        $n = 0;
        $http = new FakePaypalHttp($rows, '', static function (int $i, string $p, array $q) use (&$n): ?array {
            if ($p === PaypalApi::SEARCH_PATH && $q['page'] === '1') {
                $n++;
                usleep($n === 1 ? 800_000 : 1_300_000);
            }
            return null;
        });
        $fees = new PaypalFees(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable(self::PP_NOW, new \DateTimeZone('UTC')), null, PaypalFees::CHUNK, 1.5);
        try {
            $fees->run(Caller::system('paypal_test'), self::api($http), '2026-11-01', '2026-11-14', 'backfill', microtime(true) + 2.0);
            self::fail('the deadline ends the run');
        } catch (CwException $e) {
            self::assertSame('paypal_deadline', $e->errorCode);
        }
        self::assertSame(['counts', 6000, 0], self::of('4I00000000000F01', ['count_state', 'alloc_pence', 'due']), 'stored before the deadline: decided');
        self::assertSame(['failed', '2026-11-07'], array_values((array) self::$db->one('SELECT state, covered_to FROM paypal_run ORDER BY id DESC LIMIT 1')));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM paypal_txn WHERE due = 1'));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- M1: an order snapshot re-decides its chargebacks at once, marked due in its own transaction ----------------------------------

    public function testAnOrderSnapshotRedecidesItsChargebacksAtOnceMarkedDueInItsOwnTransaction(): void
    {
        $this->shops();
        $http = new FakePaypalHttp([self::cb('4J00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001')]);
        self::readPp($http);
        self::assertSame(['counts', 3000], self::of('4J00000000000C01', ['count_state', 'alloc_pence']));
        // the shop records a £30 refund of 1001: the snapshot's own work decides its chargeback again (no PayPal run, no nightly)
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        self::assertSame(['look', 'refund_not_on_paypal', 0, 0], self::of('4J00000000000C01', ['count_state', 'look_reason', 'alloc_pence', 'due']));
        self::assertSame([], self::days($this->vpg), 'never the refund AND the chargeback\'s money');
        self::assertDaysRebuild($this->vpg);
        // the refund taken back while the PayPal decision cannot be made (the account's settings wrong for a moment): the snapshot is stored and
        // its chargeback is due (marked in the snapshot's own transaction), decided by the next run
        $this->setting('profit.paypal_main_vapeandgo', '"electrofag"');
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 3, 0);
        self::assertSame(0, (int) self::$db->value("SELECT order_refund_inc FROM sale_order WHERE channel_id = ? AND order_ref = '1001'", [$this->vpg]));
        self::assertSame(['look', 1], self::of('4J00000000000C01', ['count_state', 'due']));
        $this->setting('profit.paypal_main_vapeandgo', '"vapeandgo"');
        self::decideAll();
        self::assertSame(['counts', 3000, 0], self::of('4J00000000000C01', ['count_state', 'alloc_pence', 'due']));
        self::assertDaysRebuild($this->vpg);
    }

    // ---- F-L5: a row twice in one answer: kept once, or held; never a failed window ---------------------------------------------------

    public function testARowTwiceInOneAnswerIsKeptOnceOrHeldNeverAFailedWindow(): void
    {
        $this->shops();
        $s = PaypalTx::sale('4K00000000000S01', '2026-11-03T10:00:00Z', '50.00', '-1.20', '1312001');
        $c = self::cb('4K00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312002');
        $http = new FakePaypalHttp([$s, $s, $c, ['transaction_amount' => ['currency_code' => 'GBP', 'value' => '-20.00']] + $c]);
        $r = self::readPp($http, '2026-11-01', '2026-11-07');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM paypal_txn WHERE txn_id = '4K00000000000S01'"));
        self::assertSame(['counts', 120], self::of('4K00000000000S01', ['count_state', 'alloc_pence']), 'the same content twice: kept once');
        self::assertSame(['look', 'pp_conflict', null], self::of('4K00000000000C01', ['count_state', 'look_reason', 'alloc_dest']), 'two contents of one row: a look');
        self::assertSame(2, $r['repeated'], 'said in the run\'s summary');
        // a row repeated may stand for a row PayPal's moving pages left out: the window is stored and decided, its days not proven
        self::assertSame(['ok', null], array_values((array) self::$db->one('SELECT state, covered_to FROM paypal_run ORDER BY id DESC LIMIT 1')));
    }

    // ---- F-M2, F-L7: the alerts --------------------------------------------------------------------------------------------------------

    public function testTheRowsThatNeedALookAreSaidOnceADayAndTheRunsAlertsComeInTime(): void
    {
        $this->shops();
        self::order($this->vpg, '1001', '1312001', '2026-11-03T10:00:00Z', 2, 3000);
        self::readPp(new FakePaypalHttp([self::cb('4L00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001'),
            PaypalTx::row('4L00000000000S02', 'T0006', '2026-11-04T10:00:00Z', '50.00', '-1.20', '1312002', 'S', ['custom_field' => 'faaeeb-3090'])]));
        $look = array_values(array_filter(PaypalFees::health(self::$db, new \DateTimeImmutable(self::PP_NOW . 'Z')), static fn (array $p): bool => $p['kind'] === 'paypal_needs_look'));
        self::assertCount(1, $look);
        $line = AlertDispatch::line($look[0]);
        self::assertStringContainsString('2 PayPal rows that need a look', $line);
        self::assertStringContainsString('field_conflict 1', $line);
        self::assertStringContainsString('refund_not_on_paypal 1', $line);
        self::assertStringNotContainsString('understand', $line);
        // sent at most once a day, whatever the alerts' repeat window (an hour here)
        $this->setting('alerts.webhook_url', '"https://hooks.example.com/services/pp"');
        $this->setting('alerts.email_to', '""');
        $this->setting('alerts.repeat_minutes', '60');
        $sent = new class () implements AlertTransport {
            public int $n = 0;

            public function webhook(string $url, array $payload, int $timeoutSec): void
            {
                $this->n++;
            }

            public function email(string $to, string $subject, string $body): void
            {
            }
        };
        $t = new \DateTimeImmutable('2026-11-25T06:00:00Z');
        $run = function () use (&$t, $sent, $look): array {
            return (new AlertDispatch(self::$db, $sent, static fn (): \DateTimeImmutable => $t))->run($look, new Settings(self::$db), 'cw_test');
        };
        self::assertSame(['paypal_needs_look:vapeandgo'], $run()['sent']);
        $t = $t->modify('+2 hours');
        self::assertSame([], $run()['sent'], 'two hours later: not again');
        $t = $t->modify('+20 hours');
        self::assertSame([], $run()['sent']);
        $t = $t->modify('+3 hours');
        self::assertSame(['paypal_needs_look:vapeandgo'], $run()['sent'], 'a day later: again');
        self::assertSame(2, $sent->n);
        // a backfill running now (the first read, right after the switch-on): no "never read" alert while it is within its time
        self::$db->exec('DELETE FROM paypal_txn_history');
        self::$db->exec('DELETE FROM paypal_txn');
        self::$db->exec('DELETE FROM paypal_run');
        $now = Clock::now();
        self::$db->exec("INSERT INTO paypal_run (account, channel_id, kind, from_day, to_day, actor, started_at) VALUES ('vapeandgo', ?, 'backfill', '2026-11-01', '2026-11-24', "
            . "'system:paypal_fees', ?)", [$this->vpg, Clock::db($now->modify('-2 minutes'))]);
        self::assertNotContains('paypal_not_read', self::alerts($now));
        // the same run still "running" 40 minutes later (it died: past its 20 minutes and the margin): failed, said at once
        self::assertContains('paypal_failed', self::alerts($now->modify('+40 minutes')));
        self::$db->exec("UPDATE paypal_run SET state = 'failed', error = 'the test', finished_at = ?", [Clock::db($now)]);
    }

    // ---- M4: no refund offset can stand ----------------------------------------------------------------------------------------------

    public function testNoRefundOffsetCanStand(): void
    {
        $this->shops();
        self::readPp(new FakePaypalHttp([self::cb('4M00000000000C01', '2026-11-07T12:00:00Z', '-30.00', '1312001')]));
        self::assertSame(['counts', 3000], self::of('4M00000000000C01', ['count_state', 'alloc_pence']));
        $code = self::mysqlError(static fn () => self::$db->exec("UPDATE paypal_txn SET refund_offset_pence = 500, alloc_pence = alloc_pence - 500 WHERE txn_id = '4M00000000000C01'"));
        self::assertSame(3819, $code, 'the database refuses any refund offset (PPF43: none is ever made)');
    }
}
