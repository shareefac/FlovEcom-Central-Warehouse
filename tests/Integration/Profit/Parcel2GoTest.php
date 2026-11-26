<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\DeliveryInvariants;
use CW\Profit\OrderSnapshots;
use CW\Profit\P2gApi;
use CW\Profit\P2gInvoice;
use CW\Profit\Parcel2Go;
use CW\Profit\ProfitDays;
use CW\Tests\Support\DpdCsv;
use CW\Tests\Support\FakeP2gHttp;
use CW\Tests\Support\FakePdfText;
use CW\Tests\Support\P2gFixtures;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1160 (0085; docs/decisions.md PGL1-PGL12): Parcel2Go's labels in the delivery cost, end to end with Parcel2Go's answers scripted (never a
 * request to Parcel2Go) and invoices in the real layout (made-up names and prices). Each label of a basket's invoice goes on the CW order its
 * tracking number is on, in THAT order's shop (a Vape Big account label of a Vape and Go order: Vape and Go's), an Extra Payment on the earlier
 * label's order, a label with no CW order on the account's main shop "not on an order", before CW start nowhere; a label waits for its
 * tracking number, then the main shop, then moves to its order when the parcel comes; a parcel never takes two carriers' costs; a top-up is
 * never a cost; the same invoice again changes nothing; an account switched off reads nothing; the undo puts every day back; the export is
 * proved the account's first. After each, every day is what a rebuild from scratch works out and the delivery checks are clean.
 */
final class Parcel2GoTest extends StockTestCase
{
    private int $vpg;
    private int $vb;
    private int $ef;
    private string $clockAt = '2026-11-25 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $this->vb = (int) $this->site('vapebig', 'live')->channelId;
        $this->ef = (int) $this->site('electrofag', 'live')->channelId;
        foreach ([[$this->vpg, '2026-10-28 10:00:00'], [$this->vb, '2026-11-17 09:00:00'], [$this->ef, '2026-11-17 09:00:00']] as [$ch, $at]) {
            self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', ?, ?)", [$ch, $at, $at]);
        }
        P2gFixtures::accounts(self::$db, $this->vpg, $this->vb, $this->ef);
    }

    private static function sys(): Caller
    {
        return Caller::system('p2g_test');
    }

    private function p2g(): Parcel2Go
    {
        $now = $this->clockAt;
        return new Parcel2Go(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now, new \DateTimeZone('UTC')));
    }

    private function on(string $key = 'p2g_vapeandgo', string $from = '2026-11-01'): void
    {
        $this->p2g()->switchOn(self::sys(), $key, $from);
    }

    /** An order of one goods line with its parcels (tracking => label time), stored and worked out now. */
    private static function order(int $ch, string $ref, ?string $paid, array $parcels, string $carrier = 'manual'): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => $label) {
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => (string) $tracking, 'carrier' => $carrier, 'service_code' => '', 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => $carrier === 'manual', 'partial' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $ref, 'status' => 'completed', 'placed_at' => '2026-11-01T09:00:00Z',
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'Parcel2Go', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1599,
            'parcels' => $p], 'api');
    }

    /**
     * One run of the job with Parcel2Go's answers scripted: the recent list, the baskets' invoice texts (by transaction), the tracking numbers
     * Parcel2Go names (by P2G number; none: 404).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, string> $invoices transaction => text
     * @param array<int, list<string>> $tracking
     * @return array{0: array<string, mixed>, 1: FakeP2gHttp}
     */
    private function pull(array $lines, array $invoices = [], array $tracking = [], string $key = 'p2g_vapeandgo'): array
    {
        $pdf = new FakePdfText();
        foreach ($invoices as $tx => $text) {
            $pdf->texts[P2gFixtures::pdf($tx)] = $text;
        }
        $http = new FakeP2gHttp(static function (string $m, string $p) use ($lines, $invoices, $tracking): array {
            if ($p === '/api/me/orders/detail') {
                return [200, ['Orders' => $lines]];
            }
            if (preg_match('#^/api/tracking/([0-9]+)$#', $p, $x) === 1) {
                return isset($tracking[(int) $x[1]]) ? [200, ['Results' => array_map(static fn (string $t): array => ['ParcelNumber' => $t, 'Description' => 'Test Person signed'],
                    $tracking[(int) $x[1]])]] : [404, ''];
            }
            if (preg_match('#^/api/invoices/([0-9]+)\?referenceType=OrderId$#', $p, $x) === 1 && isset($invoices[(int) $x[1]])) {
                return [200, ['OrderId' => (int) $x[1], 'Base64EncodedInvoice' => base64_encode(P2gFixtures::pdf((int) $x[1]))]];
            }
            return [404, ''];
        });
        $api = new P2gApi(P2gFixtures::key($key), $http, static function (int $s): void {
        });
        return [$this->p2g()->run(self::sys(), $key, $api, $pdf), $http];
    }

    /** @return array<string, int> where the live Parcel2Go money is: "dest|shop|how" => pence */
    private function where(): array
    {
        $out = [];
        foreach (self::$db->all("SELECT a.dest, a.channel_id, a.how, SUM(a.pence) AS p FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "AND i.carrier = 'parcel2go' WHERE a.ended_at IS NULL GROUP BY a.dest, a.channel_id, a.how") as $r) {
            $shop = match ((int) ($r['channel_id'] ?? 0)) {
                $this->vpg => 'vpg', $this->vb => 'vb', $this->ef => 'ef', default => '',
            };
            $out[$r['dest'] . '|' . $shop . '|' . $r['how']] = (int) $r['p'];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, int> a shop's delivery figures kept on its days: "day|field" => pence */
    private static function days(int $ch): array
    {
        $out = [];
        foreach (self::$db->all('SELECT day, delivery_cost, delivery_other FROM profit_day WHERE channel_id = ? ORDER BY day', [$ch]) as $r) {
            foreach (['delivery_cost', 'delivery_other'] as $f) {
                if ((int) $r[$f] !== 0) {
                    $out[$r['day'] . '|' . $f] = (int) $r[$f];
                }
            }
        }
        return $out;
    }

    /** Every day of the three shops is what a rebuild works out; the delivery checks (Parcel2Go's too) find nothing. */
    private function assertClean(): void
    {
        foreach ([$this->vpg, $this->vb, $this->ef] as $ch) {
            for ($d = '2026-11-01'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros'))),
                    "shop {$ch} day {$d}: the kept figures are what a rebuild works out");
            }
        }
        self::assertSame([], DeliveryInvariants::check(self::$db), 'the delivery checks');
    }

    private static function p2gLine(int $olid): array
    {
        return self::$db->one('SELECT * FROM p2g_line WHERE order_line_id = ?', [$olid]) ?? [];
    }

    public function testEachLabelGoesOnItsOrderInThatOrdersShop(): void
    {
        $this->on();
        self::order($this->vpg, '1570001', '2026-11-23T09:00:00Z', ['H00TST0000000001' => '2026-11-24T09:30:00Z']);
        self::order($this->vb, '50001', '2026-11-23T08:00:00Z', ['H00TST0000000002' => '2026-11-24T09:40:00Z']);
        $lines = [P2gFixtures::apiLine(150000001, 95000001, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000002, 95000001, '2026-11-24T10:00:00Z'),
            P2gFixtures::apiLine(150000003, 95000001, '2026-11-24T10:00:00Z')];
        $text = P2gFixtures::invoiceText(95000001, '24/11/2026', [[150000001, 'Evri Europe Standard Parcelshop', 1, 1132, 0],
            [150000002, 'Evri Europe Standard Parcelshop', 1, 886, 0], [150000003, 'Evri Europe Standard Parcelshop', 1, 500, 0]]);
        [$r, $http] = $this->pull($lines, [95000001 => $text], [150000001 => ['H00TST0000000001'], 150000002 => ['H00TST0000000002'], 150000003 => ['H00TST0000000003']]);
        self::assertSame(['ok', 3, 3, 3, 1, 0], [$r['result'], $r['listed'], $r['lines_new'], $r['tracking_found'], $r['priced'], $r['refused']]);
        self::assertSame(1, count(array_filter($http->requests, static fn (array $q): bool => str_starts_with($q['path'], '/api/invoices/'))), 'one invoice prices the basket');
        foreach ($http->requests as $q) {
            P2gApi::allowed($q['method'], $q['path']);
        }
        self::assertSame(['order|vb|label' => 886, 'order|vpg|label' => 1132, 'shop|vpg|no_parcel' => 500], $this->where(),
            'the Vape Big order\'s label on Vape Big\'s order (shipped from Vape and Go\'s account); no CW order: Vape and Go\'s line');
        self::assertSame(['2026-11-23|delivery_cost' => 1132, '2026-11-24|delivery_other' => 500], self::days($this->vpg), 'on the order\'s paid day; not on an order on its own day');
        self::assertSame(['2026-11-23|delivery_cost' => 886], self::days($this->vb));
        self::assertSame(['priced', 'priced', 'priced'], [self::p2gLine(150000001)['price_state'], self::p2gLine(150000002)['price_state'], self::p2gLine(150000003)['price_state']]);
        self::assertSame(1132, DeliveryBills::orderCost(self::$db, $this->vpg, '1570001'));
        $bill = self::$db->one("SELECT * FROM carrier_invoice WHERE carrier = 'parcel2go'");
        self::assertSame(['TX-95000001', 'p2g-vapeandgo', 2518, 3, $this->vpg], [$bill['invoice_no'], $bill['account'], (int) $bill['net_pence'], (int) $bill['line_count'],
            (int) $bill['channel_id']]);
        self::assertStringNotContainsString(P2gFixtures::PERSON, (string) json_encode(self::$db->all('SELECT * FROM p2g_line')), 'no name is kept');
        // the same invoice again: nothing changes
        self::assertSame('same', $this->p2g()->store(self::sys(), Parcel2Go::account(self::$db, 'p2g_vapeandgo'), P2gInvoice::read($text)));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE carrier = 'parcel2go'"));
        $this->assertClean();
    }

    public function testAnExtraPaymentGoesOnTheEarlierLabelsOrder(): void
    {
        $this->on();
        self::order($this->vpg, '1570001', '2026-11-23T09:00:00Z', ['H00TST0000000001' => '2026-11-24T09:30:00Z']);
        $this->pull([P2gFixtures::apiLine(150000001, 95000001, '2026-11-24T10:00:00Z')],
            [95000001 => P2gFixtures::invoiceText(95000001, '24/11/2026', [[150000001, 'Evri Europe Standard Parcelshop', 1, 1132, 0]])], [150000001 => ['H00TST0000000001']]);
        $this->clockAt = '2026-11-27 12:00:00';
        [$r] = $this->pull([P2gFixtures::apiLine(150000004, 95000002, '2026-11-26T10:00:00Z'), P2gFixtures::apiLine(150000001, 95000001, '2026-11-24T10:00:00Z')],
            [95000002 => P2gFixtures::invoiceText(95000002, '26/11/2026', [[150000001, 'Extra Payment', 1, 293, 0], [150000004, 'Evri Europe Standard Parcelshop', 1, 700, 0]])],
            [150000004 => ['H00TST0000000004']]);
        self::assertSame(1, $r['priced']);
        self::assertSame(['order|vpg|label' => 1132 + 293, 'shop|vpg|no_parcel' => 700], $this->where(), 'the £2.93 on the earlier label\'s order');
        self::assertSame(1425, DeliveryBills::orderCost(self::$db, $this->vpg, '1570001'));
        self::assertSame(['2026-11-23|delivery_cost' => 1425, '2026-11-26|delivery_other' => 700], self::days($this->vpg));
        self::assertSame((int) self::$db->value("SELECT id FROM carrier_invoice WHERE invoice_no = 'TX-95000001'"), (int) self::p2gLine(150000001)['invoice_id'],
            'the earlier label stays priced by its own basket');
        $this->assertClean();
    }

    public function testALabelWaitsForItsTrackingThenTheMainShopThenItsOrder(): void
    {
        $this->on();
        $line = [P2gFixtures::apiLine(150000005, 95000005, '2026-11-24T10:00:00Z')];
        $inv = [95000005 => P2gFixtures::invoiceText(95000005, '24/11/2026', [[150000005, 'Evri Europe Standard Parcelshop', 1, 650, 0]])];
        $this->clockAt = '2026-11-24 12:00:00';
        $this->pull($line, $inv);
        self::assertSame(['held||no_tracking' => 650], $this->where(), 'no tracking number yet: in no profit');
        self::assertSame([], self::days($this->vpg));
        $this->clockAt = '2026-11-28 12:00:00';
        $this->pull($line, $inv);
        self::assertSame(['shop|vpg|no_tracking' => 650], $this->where(), 'after the wait: the main shop\'s "not on an order"');
        self::assertSame(['2026-11-24|delivery_other' => 650], self::days($this->vpg));
        $this->assertClean();
        self::order($this->vpg, '1570005', '2026-11-23T09:00:00Z', ['H00TST0000000005' => '2026-11-25T09:30:00Z']);
        $this->clockAt = '2026-11-28 13:30:00';
        $this->pull($line, $inv, [150000005 => ['H00TST0000000005']]);
        self::assertSame(['order|vpg|label' => 650], $this->where(), 'its parcel came: on its order, the shop\'s line back to 0');
        self::assertSame(['2026-11-23|delivery_cost' => 650], self::days($this->vpg));
        $this->assertClean();
    }

    public function testBeforeCwStartAndTheVapeBigAccountsVapeAndGoOrders(): void
    {
        $this->on('p2g_vapebig');
        self::order($this->vpg, '1569000', '2026-11-09T09:00:00Z', ['YD000000007GB' => '2026-11-10T09:30:00Z']);
        $lines = [P2gFixtures::apiLine(150000006, 96000006, '2026-11-10T10:00:00Z'), P2gFixtures::apiLine(150000007, 96000006, '2026-11-10T10:00:00Z'),
            P2gFixtures::apiLine(150000008, 96000008, '2026-11-20T10:00:00Z')];
        $this->pull($lines, [96000006 => P2gFixtures::invoiceText(96000006, '10/11/2026', [[150000006, 'Royal Mail Tracked 48 Small Parcel', 1, 319, 64],
            [150000007, 'Royal Mail Tracked 48 Small Parcel', 1, 319, 64]]), 96000008 => P2gFixtures::invoiceText(96000008, '20/11/2026', [[150000008,
            'Royal Mail Tracked 48 Small Parcel', 1, 324, 65]])], [150000006 => ['YD000000006GB'], 150000007 => ['YD000000007GB'], 150000008 => ['YD000000008GB']], 'p2g_vapebig');
        self::assertSame(['before_start|vb|before_start' => 319, 'order|vpg|label' => 319, 'shop|vb|no_parcel' => 324], $this->where(),
            'Vape Big before 17 Nov: not counted; its account\'s Vape and Go order of 10 Nov: Vape and Go\'s; after 17 Nov with no CW order: Vape Big\'s line');
        self::assertSame(['2026-11-09|delivery_cost' => 319], self::days($this->vpg));
        self::assertSame(['2026-11-20|delivery_other' => 324], self::days($this->vb), 'the price without VAT');
        $this->assertClean();
    }

    public function testAParcelNeverTakesTwoCarriersCosts(): void
    {
        $this->on();
        $this->on('p2g_vapebig');
        // a DPD parcel already on DPD's bill; two CW orders with one number; one number on both Parcel2Go accounts
        self::order($this->vpg, '1570010', '2026-11-23T09:00:00Z', ['15501234567890' => '2026-11-24T09:30:00Z'], 'dpd');
        (new DeliveryBills(self::$db))->upload(self::sys(), 'dpd', DpdCsv::file([DpdCsv::line('24/11/2026', '15501234567890', 499)]));
        self::order($this->vpg, '1570011', '2026-11-23T09:00:00Z', ['H00TST0000000012' => '2026-11-24T09:30:00Z']);
        self::order($this->vpg, '1570012', '2026-11-23T09:00:00Z', ['H00TST0000000012' => '2026-11-24T09:35:00Z']);
        self::order($this->vpg, '1570013', '2026-11-23T09:00:00Z', ['H00TST0000000013' => '2026-11-24T09:30:00Z']);
        $this->pull([P2gFixtures::apiLine(150000009, 95000009, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000012, 95000009, '2026-11-24T10:00:00Z'),
            P2gFixtures::apiLine(150000013, 95000009, '2026-11-24T10:00:00Z')], [95000009 => P2gFixtures::invoiceText(95000009, '24/11/2026', [[150000009, 'DPD Next Day', 1, 600, 120],
            [150000012, 'Evri Europe Standard Parcelshop', 1, 700, 0], [150000013, 'Evri Europe Standard Parcelshop', 1, 800, 0]])],
            [150000009 => ['15501234567890'], 150000012 => ['H00TST0000000012'], 150000013 => ['H00TST0000000013']]);
        $this->pull([P2gFixtures::apiLine(150000014, 96000014, '2026-11-24T11:00:00Z')], [96000014 => P2gFixtures::invoiceText(96000014, '24/11/2026',
            [[150000014, 'Evri Europe Standard Parcelshop', 1, 900, 0]])], [150000014 => ['H00TST0000000013']], 'p2g_vapebig');
        self::assertSame(['order|vpg|label' => 800, 'shop|vb|tracking_twice' => 900, 'shop|vpg|costed_twice' => 600, 'shop|vpg|two_parcels' => 700], $this->where(),
            'never a second cost on a parcel: its money on the main shop\'s line, to look at');
        self::assertSame(499, DeliveryBills::orderCost(self::$db, $this->vpg, '1570010'), 'DPD\'s cost only');
        $this->assertClean();
    }

    public function testARefusedInvoiceIsSaidAndTriedAgainAndATopUpIsNeverACost(): void
    {
        $this->on();
        $bad = str_replace('Testtown', 'Testtown          Handling £1.00', P2gFixtures::invoiceText(95000020, '24/11/2026', [[150000020, 'Evri Europe Standard Parcelshop', 1, 500, 0]]));
        $this->clockAt = '2026-11-24 12:00:00';
        [$r, $http] = $this->pull([P2gFixtures::apiLine(150000020, 95000020, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000021, null, '2026-11-24T10:00:00Z', 1, 'AutoTopup')],
            [95000020 => $bad]);
        self::assertSame([1, 'refused', 'unknown_line'], [$r['refused'], self::p2gLine(150000020)['price_state'], self::p2gLine(150000020)['refuse_code']]);
        self::assertSame(['topup', 'not_needed'], [self::p2gLine(150000021)['kind'], self::p2gLine(150000021)['price_state']]);
        self::assertSame([], array_values(array_filter($http->requests, static fn (array $q): bool => str_contains($q['path'], '150000021'))), 'a top-up\'s invoice is never asked for');
        self::assertSame([], $this->where());
        $this->clockAt = '2026-11-24 17:00:00';
        $kinds = array_column(Parcel2Go::health(self::$db, new \DateTimeImmutable('2026-11-24 17:00:00', new \DateTimeZone('UTC'))), 'kind');
        self::assertContains('p2g_waiting', $kinds, 'a paid label without its price for more than 6 hours');
        self::assertSame(1, $this->p2g()->retryRefused(self::sys(), 'p2g_vapeandgo'));
        self::assertSame('due', self::p2gLine(150000020)['price_state']);
        $this->assertClean();
    }

    public function testSwitchedOffReadsNothingAndTheUndoPutsEveryDayBack(): void
    {
        self::order($this->vpg, '1570001', '2026-11-23T09:00:00Z', ['H00TST0000000001' => '2026-11-24T09:30:00Z']);
        $lines = [P2gFixtures::apiLine(150000001, 95000001, '2026-11-24T10:00:00Z'), P2gFixtures::apiLine(150000003, 95000001, '2026-11-24T10:00:00Z')];
        $inv = [95000001 => P2gFixtures::invoiceText(95000001, '24/11/2026', [[150000001, 'Evri Europe Standard Parcelshop', 1, 1132, 0],
            [150000003, 'Evri Europe Standard Parcelshop', 1, 500, 0]])];
        [$r, $http] = $this->pull($lines, $inv, [150000001 => ['H00TST0000000001']]);
        self::assertSame(['off', []], [$r['result'], $http->requests], 'switched off (as installed): not one request');
        $this->on();
        $this->pull($lines, $inv, [150000001 => ['H00TST0000000001'], 150000003 => ['H00TST0000000003']]);
        self::assertNotSame([], self::days($this->vpg));
        try {
            $this->p2g()->undoAll(self::sys(), 'p2g_vapeandgo');
            self::fail('the undo needs the account switched off');
        } catch (CwException $e) {
            self::assertSame('p2g_on', $e->errorCode);
        }
        $this->p2g()->switchOff(self::sys(), 'p2g_vapeandgo');
        self::assertSame(['bills' => 1, 'pence' => 1632], $this->p2g()->undoAll(self::sys(), 'p2g_vapeandgo'));
        self::assertSame([], self::days($this->vpg), 'every day back');
        self::assertSame([], $this->where());
        self::assertSame(['due', null], [self::p2gLine(150000001)['price_state'], self::p2gLine(150000001)['invoice_id']]);
        self::assertSame('undone', (string) self::$db->value("SELECT status FROM carrier_invoice WHERE carrier = 'parcel2go'"));
        $this->assertClean();
    }

    public function testTheExportIsProvedTheAccountsFirstAndAGapIsSaid(): void
    {
        $this->on();
        $this->on('p2g_vapebig');
        $known = [];
        for ($i = 1; $i <= 5; $i++) {
            $known[] = P2gFixtures::apiLine(150000100 + $i, null, null);
        }
        $this->pull($known);
        $this->pull([P2gFixtures::apiLine(150000200, null, null)], [], [], 'p2g_vapebig');
        $dir = sys_get_temp_dir() . '/cw_p2g_imp_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $csv = static function (array $refs) use ($dir): string {
            $f = $dir . '/export.csv';
            $h = fopen($f, 'wb');
            fputcsv($h, ['Reference', 'Created', 'Customer', 'Postcode', 'Country', 'Service', 'TrackingNumber'], ',', '"', '');
            foreach ($refs as $n => $ref) {
                fputcsv($h, ["P2G{$ref}", '24/11/2026 10:0' . ($n % 10), 'Test Person', 'TT1 1TT', 'DEU', 'Evri Europe Standard Parcelshop', 'H00TST' . str_pad((string) $ref, 10, '0', STR_PAD_LEFT)],
                    ',', '"', '');
            }
            fclose($h);
            return $f;
        };
        try {
            $refusal = static function (\Closure $fn): string {
                try {
                    $fn();
                } catch (CwException $e) {
                    return $e->errorCode;
                }
                return 'read';
            };
            self::assertSame('p2g_csv_account', $refusal(fn () => $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $csv([150000101, 150000102, 150000300]))),
                'only 2 of its labels known: not proved the account\'s');
            self::assertSame('p2g_csv_account', $refusal(fn () => $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $csv([150000101, 150000102, 150000103, 150000104, 150000105,
                150000200]))), 'a label of the other account in it');
            $r = $this->p2g()->import(self::sys(), 'p2g_vapeandgo', $csv([150000101, 150000102, 150000103, 150000104, 150000105, 150000301, 150000302]));
            self::assertSame(['rows' => 7, 'lines' => 7, 'lines_new' => 2, 'tracking_filled' => 7], $r);
            self::assertSame(['report', 'due', '2026-11-24'], [self::p2gLine(150000301)['source'], self::p2gLine(150000301)['price_state'], self::p2gLine(150000301)['paid_day']]);
            self::assertStringNotContainsString('Test Person', (string) json_encode(self::$db->all('SELECT * FROM p2g_line')));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        // a full list of 25 new labels when the account had labels: some may be missing (the health alert says so until an export is read)
        $full = [];
        for ($i = 1; $i <= 25; $i++) {
            $full[] = P2gFixtures::apiLine(150000400 + $i, null, null);
        }
        $this->clockAt = '2026-11-25 13:00:00';
        [$r] = $this->pull($full);
        self::assertSame(1, $r['gap_seen']);
        self::assertContains('p2g_gap', array_column(Parcel2Go::health(self::$db, new \DateTimeImmutable('2026-11-25 13:05:00', new \DateTimeZone('UTC'))), 'kind'));
        $this->assertClean();
    }
}
