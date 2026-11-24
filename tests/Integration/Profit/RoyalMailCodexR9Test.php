<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex check r9 of e9d3bb1 against DL84 (cw_work/runs/rmbills_codex_r9.txt: NO GO, 15 HIGH, 3 MEDIUM), SYNTHETIC labels and bills, each
 * shown failing first (runs/rmbills_r10_red.log). Since DL86 (the owner's "yes no hold", 24 Nov 2026) only a day covered by no report waits:
 * the tests of the evidence holds (H5, H6-H9, H11, H12, H15) changed with it, each says how.
 */
final class RoyalMailCodexR9Test extends StockTestCase
{
    private const START = '2026-10-28 10:28:00.000000';

    private int $vpg;
    private int $ef;
    /** @var array{account: int, vpg_party: int, ef_party: ?int, hayati: int} */
    private array $acct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        $this->ef = (int) $this->site('electrofag', 'live')->channelId;
        foreach ([$this->vpg, $this->ef] as $ch) {
            self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', ?, ?)",
                [$ch, self::START, self::START]);
        }
        $this->acct = RmFixtures::account(self::$db, $this->vpg, $this->ef, 'active', RmFixtures::ACCOUNT, false);
    }

    private static function system(): Caller
    {
        return Caller::system('rm_test');
    }

    private static function rm(): RoyalMailBills
    {
        return new RoyalMailBills(self::$db);
    }

    /** An order with one goods line and its parcels (tracking => [label time, service, carrier]). */
    private static function order(int $ch, string $ref, string $no, array $parcels): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => $x) {
            [$label, $service, $carrier] = $x + [2 => 'royal_mail'];
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => $tracking, 'carrier' => $carrier, 'service_code' => $service, 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => $carrier === 'manual', 'partial' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed', 'placed_at' => '2026-10-20T09:00:00Z',
            'paid_at' => '2026-10-20T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'Royal Mail', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1599,
            'parcels' => $p], 'api');
    }

    /** @return array<string, int> where a bill's live money went: "dest|shop or party|reason" => pence */
    private static function where(int $invoiceId): array
    {
        $out = [];
        foreach (self::$db->all('SELECT dest, channel_id, party_id, held_reason, SUM(pence) AS p FROM carrier_allocation WHERE invoice_id = ? AND ended_at IS NULL '
            . 'GROUP BY dest, channel_id, party_id, held_reason', [$invoiceId]) as $r) {
            $out[$r['dest'] . '|' . ($r['channel_id'] ?? $r['party_id'] ?? '') . '|' . ($r['held_reason'] ?? '')] = (int) $r['p'];
        }
        ksort($out);
        return $out;
    }

    private function report(array $labels, string $from, string $to): array
    {
        return RmFixtures::report(self::rm(), self::system(), $this->acct['account'], $labels, $from, $to);
    }

    private function bill(array $lines, string $invoice = '9070000001', string $date = 'Nov 16, 2026'): int
    {
        return (int) self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => $invoice, 'date' => $date]))['invoice_id'];
    }

    private static function sd(string $t, string $day, string $ref, int $manifest, ?string $so, array $over = []): array
    {
        return RmFixtures::label($t, 'SD1', $day, $ref, $over + ['manifest_no' => $manifest, 'sales_order' => $so, 'weight_g' => 80]);
    }

    private function assertRebuilds(): void
    {
        foreach ([$this->vpg, $this->ef] as $ch) {
            for ($d = '2026-10-20'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
                self::assertSame([], $diff, "shop {$ch} day {$d}");
            }
        }
        self::assertSame([], RoyalMailBills::drift(self::$db, $this->acct['account']));
    }

    /** H1: only a Manifested Orders report covers days: a Despatched Orders report (no Company, no Department column) is refused whole. */
    public function testH1ADespatchedOrdersReportIsRefused(): void
    {
        self::order($this->vpg, '2001', '1392001', ['JV000002001GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $file = RmFixtures::reportFile([RmFixtures::label('JV000002001GB', 'TPL48HV', '2026-11-12', '1392001')], ['Company', 'Department']);
        try {
            self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-12', '2026-11-12');
            self::fail('a Despatched Orders report');
        } catch (CwException $e) {
            self::assertSame('not_manifested', $e->errorCode);
        }
        self::assertSame([0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM carrier_label'), (int) self::$db->value('SELECT COUNT(*) FROM carrier_day_cover')]);
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(['held||not_covered' => 254], self::where($id));
    }

    /** H2: labels and cover come only from a read report: the array writers are not public. */
    public function testH2TheLabelWritersAreNotPublic(): void
    {
        foreach (['importLabels', 'importReportLabels'] as $m) {
            self::assertFalse((new \ReflectionMethod(RoyalMailBills::class, $m))->isPublic(), $m);
        }
    }

    /** H3: every row of the report is inside From..To, a row skipped for its tracking number too: else the report is refused whole. */
    public function testH3ARowSkippedForItsTrackingStillCountsForFromTo(): void
    {
        self::order($this->vpg, '2031', '1392031', ['JV000002031GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $file = RmFixtures::reportFile([RmFixtures::label('NOT A NUMBER', 'TPL48HV', '2026-11-10', '1392030'),
            RmFixtures::label('JV000002031GB', 'TPL48HV', '2026-11-12', '1392031')]);
        try {
            self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-11', '2026-11-12');
            self::fail('a row of the 10th in a report said to be of 11-12 Nov');
        } catch (CwException $e) {
            self::assertSame('bad_report_days', $e->errorCode);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_day_cover'));
        // the same report given its real From..To: read (its bad row skipped)
        $r = self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-10', '2026-11-12');
        self::assertSame([1, 1], [$r['labels'], $r['skipped']]);
    }

    /** H4: a surcharge or a return row of a day not covered waits too (no shop's "not on an order" before its day is covered). */
    public function testH4SurchargesAndReturnsWaitForTheirDay(): void
    {
        $id = $this->bill([RmBill::line('2026-11-12', 'RW4', 3, 315, 'Rectification Week 46'), RmBill::line('2026-11-12', 'TSS', 2, 540, 'Returns')]);
        self::assertSame(['held||not_covered' => 855], self::where($id));
        $this->report([], '2026-11-12', '2026-11-12');
        self::assertSame(["shop|{$this->vpg}|" => 855], self::where($id), 'covered: Vape and Go\'s not on an order');
        $this->assertRebuilds();
    }

    /** H5, changed by DL86 5: Special Delivery is priced by manifest: one manifest billed over its labels never moves another of the same day. */
    public function testH5OverbillingADaysSpecialDeliveryWithdrawsEveryManifestOfIt(): void
    {
        self::order($this->vpg, '2051', '1392051', ['AS000002051GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->ef, '2052', '9052', ['AS000002052GB' => ['2026-11-12T11:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002051GB', '2026-11-12', '1392051', 2201, '5155502051'), self::sd('AS000002052GB', '2026-11-12', '9052', 2202, '5155502052')],
            '2026-11-12', '2026-11-12');
        $a = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2201', '5155502051')], '9070000051');
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($a));
        $b = $this->bill([RmBill::line('2026-11-12', 'SD1', 2, 2008, 'CLICK AND DROP M2202', '5155502052')], '9070000052', 'Nov 23, 2026');
        self::assertSame(["order|{$this->ef}|" => 2008], self::where($b), 'M2202: 2 billed for its 1 label, the average (red on "needs a look")');
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($a), 'M2201 as it was');
        self::assertSame(930, DeliveryBills::orderCost(self::$db, $this->vpg, '2051'));
        self::assertSame([['manifest', 2202]], array_map(static fn (array $i): array => [$i['kind'], $i['manifest']], array_values(array_filter(
            \CW\Profit\RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']), static fn (array $i): bool => $i['red']))));
        $this->assertRebuilds();
    }

    /** H6, changed by DL86 5: two manifests' rows printing one sales order are each their manifest's: one waiting never holds the other. */
    public function testH6ManifestRowsNamingOneSalesOrderAreOneGroup(): void
    {
        self::order($this->vpg, '2061', '1392061', ['AS000002061GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002061GB', '2026-11-12', '1392061', 2211, '5155502069')], '2026-11-12', '2026-11-12');
        // a row of M2212 (13 Nov, not covered) printing sales order X, live first
        $a = $this->bill([RmBill::line('2026-11-13', 'SD1', 1, 1004, 'CLICK AND DROP M2212', '5155502061')], '9070000061');
        self::assertSame(['held||not_covered' => 1004], self::where($a));
        // a row of M2211 (12 Nov, covered, its label of sales order Y) printing the same X: priced on M2211's label
        $b = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2211', '5155502061')], '9070000062', 'Nov 23, 2026');
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($b));
        $this->assertRebuilds();
    }

    /** H7, changed by DL86 5: a Special Delivery label naming no sales order is priced by its manifest all the same (no group). */
    public function testH7AManifestWithNoSalesOrderOnItsLabelWaits(): void
    {
        self::order($this->vpg, '2071', '1392071', ['AS000002071GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002071GB', '2026-11-12', '1392071', 2221, null)], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2221')]);
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($id));
    }

    /** H8, changed by DL86 4 and 5: a label of no service on another manifest of the sales order holds nothing; it is on "needs a look". */
    public function testH8AGroupLabelWithNoServiceIsAMember(): void
    {
        self::order($this->vpg, '2081', '1392081', ['AS000002081GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->ef, '2082', '9082', ['AS000002082GB' => ['2026-11-13T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002081GB', '2026-11-12', '1392081', 2231, '5155502081')], '2026-11-12', '2026-11-12');
        $this->report([self::sd('AS000002082GB', '2026-11-13', '9082', 2232, '5155502081', ['service_code' => null])], '2026-11-13', '2026-11-13');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2231', '5155502081')]);
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($id), 'M2231 on its label, whatever M2232\'s label of the same sales order says');
        self::assertSame([['AS000002082GB', 'no_service']], array_map(static fn (array $i): array => [$i['tracking_no'], $i['why']], array_values(array_filter(
            \CW\Profit\RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-13']), static fn (array $i): bool => $i['kind'] === 'odd_service'))));
    }

    /** H9, changed by DL86 6: a wrong-weight sales order with no label of its own waits only for its day's report, then is the main shop's. */
    public function testH9AWrongWeightSalesOrderWithNoLabelOfItsOwnWaits(): void
    {
        self::order($this->vpg, '2091', '1392091', ['AS000002091GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002091GB', '2026-11-12', '1392091', 2241, '5155502099')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2241', '5155502091'),
            RmBill::line('2026-11-14', 'RX1', 1, 275, 'ADJ5155502091RCN Surcharge')]);
        self::assertSame(['held||not_covered' => 275, "order|{$this->vpg}|" => 930], self::where($id), 'M2241 on its label; the wrong weight\'s 14th covered by no report');
        $this->report([], '2026-11-14', '2026-11-14');
        self::assertSame(["order|{$this->vpg}|" => 930, "shop|{$this->vpg}|" => 275], self::where($id), 'sales order X has no label: Vape and Go\'s not on an order (DL86 6)');
    }

    /** H10, changed by DL86 5: a manifest is priced on its own (no units check by day); a row dated on or before the start is before CW start whole. */
    public function testH10AGroupCrossingTheStartChecksItsOldDaysAndKeepsOldRowsOut(): void
    {
        self::order($this->vpg, '2101', '1392101', ['AS000002101GB' => ['2026-10-28T12:00:00Z', 'SD1']]);
        self::order($this->vpg, '2102', '1392102', ['AS000002102GB' => ['2026-10-29T12:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002101GB', '2026-10-28', '1392101', 2251, '5155502101'), self::sd('AS000002102GB', '2026-10-29', '1392102', 2252, '5155502101')],
            '2026-10-28', '2026-10-29');
        $id = $this->bill([RmBill::line('2026-10-28', 'SD1', 2, 1860, 'CLICK AND DROP M2251', '5155502101'),
            RmBill::line('2026-10-29', 'SD1', 1, 930, 'CLICK AND DROP M2252', '5155502101')], '9070000101', 'Nov 2, 2026');
        self::assertSame(["before_start|{$this->vpg}|" => 1860, "order|{$this->vpg}|" => 930], self::where($id),
            'M2251 (the start day) before CW start, its 2 units for 1 label averaged; M2252 on its order');
        // a row dated the start day whose only label is of the 29th: before CW start, never on the 29th's order
        self::order($this->vpg, '2103', '1392103', ['AS000002103GB' => ['2026-10-29T13:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000002103GB', '2026-10-29', '1392103', 2253, '5155502103')], '2026-10-29', '2026-10-29');
        $b = $this->bill([RmBill::line('2026-10-28', 'SD1', 1, 930, 'CLICK AND DROP M2253', '5155502103')], '9070000102', 'Nov 3, 2026');
        self::assertSame(["before_start|{$this->vpg}|" => 930], self::where($b));
        self::assertSame(0, DeliveryBills::orderCost(self::$db, $this->vpg, '2103'));
    }

    /** H11, changed by DL86 3 and 4: every outbound Royal Mail parcel in no report is listed (holding nothing), and a manual parcel's service compared. */
    public function testH11EveryOutboundParcelIsCheckedAndAManualParcelsServiceCompared(): void
    {
        self::order($this->vpg, '2111', '1392111', ['JV000002111GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '2112', '9112', ['JV000002112GB' => ['2026-11-12T11:00:00Z', 'OTC']]);
        self::order($this->ef, '2113', '9113', ['JV000002113GB' => ['2026-11-12T12:00:00Z', 'TSS']]); // a return: no label of the account
        $this->report([RmFixtures::label('JV000002111GB', 'TPL48HV', '2026-11-12', '1392111')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id), 'Electrofag\'s OTC parcel of the 12th is in no report: nothing waits');
        self::assertSame(['JV000002112GB'], array_column(array_values(array_filter(\CW\Profit\RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']),
            static fn (array $i): bool => $i['kind'] === 'parcel_missing')), 'tracking_no'), 'listed; the return is not');
        // a manual parcel whose service differs from the report's
        self::order($this->vpg, '2114', '1392114', ['JV000002114GB' => ['2026-11-13T10:00:00Z', 'TPM24HV', 'manual']]);
        $this->report([RmFixtures::label('JV000002114GB', 'TPL48HV', '2026-11-13', '1392114')], '2026-11-13', '2026-11-13');
        $b = $this->bill([RmBill::line('2026-11-13', 'TPL', 1, 254, '943')], '9070000112', 'Nov 23, 2026');
        self::assertSame(["shop|{$this->vpg}|" => 254], self::where($b), 'its service differs: no price, the unit on Vape and Go\'s line');
    }

    /** H12, as DL86 keeps it: the plan's evidence is read again under the write's guards: a CW parcel's service changed meanwhile, the plan is made again. */
    public function testH12TheDaysEvidenceIsCheckedUnderTheGuards(): void
    {
        self::order($this->vpg, '2121', '1392121', ['JV000002121GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000002121GB', 'TPL48HV', '2026-11-12', '1392121')], '2026-11-12', '2026-11-12');
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        $done = false;
        \CW\Tests\Support\HookedStatement::$before = ['SELECT GET_LOCK(?, 0) AS g0' => static function () use (&$done): void {
            if (!$done) {
                $done = true; // the parcel's service changed in CW between the plan and its guards
                self::$db->exec("UPDATE sale_parcel SET service_code = 'TPM24HV' WHERE tracking_no = 'JV000002121GB'");
            }
        }];
        try {
            $id = (int) (new RoyalMailBills($conn))->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 264, '943')]))['invoice_id'];
        } finally {
            \CW\Tests\Support\HookedStatement::$before = [];
        }
        self::assertTrue($done);
        self::assertSame(["shop|{$this->vpg}|" => 264], self::where($id), 'its service differs now: no price, the unit on Vape and Go\'s line (DL86 4)');
        self::assertSame(0, DeliveryBills::orderCost(self::$db, $this->vpg, '2121'));
    }

    /** H14: an account with live bills cannot be switched off (its money would stay with nothing planning it): undo its bills first. */
    public function testH14AnAccountWithLiveBillsIsNotSwitchedOff(): void
    {
        self::order($this->vpg, '2141', '1392141', ['JV000002141GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000002141GB', 'TPL48HV', '2026-11-12', '1392141')], '2026-11-12', '2026-11-12');
        $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . '/bin/carrier_account.php', '--db=' . TestDb::name(), '--admin',
            '--account=' . RmFixtures::ACCOUNT, '--switch-off'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($p), $out);
        self::assertSame('active', (string) self::$db->value('SELECT state FROM carrier_account WHERE id = ?', [$this->acct['account']]));
    }

    /**
     * H15, changed by DL86 3: a CW parcel that reaches CW later, in no report, withdraws nothing (so nothing can age out of the nightly's days):
     * the money stays, nothing is kept due, the parcel is on "needs a look".
     */
    public function testH15AGroupTheNightlyCannotPlanIsKeptDue(): void
    {
        self::order($this->vpg, '2151', '1392151', ['JV000002151GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000002151GB', 'TPL48HV', '2026-11-12', '1392151')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id));
        // a second parcel of the 12th reaches CW, in no report; the nightly sees it while the order is held elsewhere
        self::order($this->vpg, '2152', '1392152', ['JV000002152GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '2151')]));
        try {
            try {
                self::rm()->replan(self::system(), null, 3650);
            } catch (CwException $e) {
                self::assertSame('partial', $e->errorCode);
            }
        } finally {
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->vpg, '2151')]);
        }
        self::assertSame([], array_map('strval', self::$db->column('SELECT day FROM carrier_replan_due WHERE account_id = ?', [$this->acct['account']])),
            'nothing to withdraw: nothing due');
        self::rm()->replan(self::system(), null, 0);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id));
        self::assertSame(['JV000002152GB'], array_column(array_values(array_filter(\CW\Profit\RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']),
            static fn (array $i): bool => $i['kind'] === 'parcel_missing')), 'tracking_no'));
        $this->assertRebuilds();
    }

    /** M18: the check lists every waiting day with its reason, a Special Delivery one too. */
    public function testM18TheCheckListsEveryWaitingDay(): void
    {
        $page = RmBill::page([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2281', '5155502181')]);
        $p = self::rm()->preview($this->acct['account'], $page);
        self::assertSame([['day' => '2026-11-12', 'reason' => 'not_covered', 'pence' => 930]], $p['waiting']);
    }
}
