<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * Phase 1 is REPORT-ONLY (the coordinator, 24 Nov 2026; docs/decisions.md DL84), SYNTHETIC labels and bills: the Click & Drop API pull is
 * phase 2; a day is covered only by a report import (its From..To, in the same transaction as its labels). Since DL86 (the owner's "yes no
 * hold", 24 Nov 2026) a covered day moves money whatever the evidence says: a CW parcel in no report, units billed above the labels and an
 * unmapped or conflicting service hold nothing (the tests of DL84 3-5 changed with it, each says so); a Special Delivery manifest and a
 * wrong-weight sales order are each priced on their own; the main shop's start day and every day before it are wholly before CW start; due
 * days of an account switched off wait for it, one failing group never blocks the others; the health alert says a week with no report and
 * days waiting long.
 */
final class RoyalMailReportOnlyTest extends StockTestCase
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
        // no day covered: each test covers its days by a report import
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

    /** An order with one goods line and its Royal Mail parcels (tracking => [label time, service]). */
    private static function order(int $ch, string $ref, string $no, array $parcels): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => [$label, $service]) {
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => $tracking, 'carrier' => 'royal_mail', 'service_code' => $service, 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => false, 'partial' => false];
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

    /** A report import of these labels for its From..To (as the screen's upload, after its file is read). @return array<string, mixed> */
    private function report(array $labels, string $from, string $to): array
    {
        return RmFixtures::report(self::rm(), self::system(), $this->acct['account'], $labels, $from, $to);
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

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    /** DL84 1: the Click & Drop API pull is phase 2; nothing but a report brings labels. */
    public function testThePullIsPhaseTwo(): void
    {
        $r = self::script('rm_labels', '--pull');
        self::assertSame(1, $r['code'], $r['out']);
        self::assertStringContainsString('phase 2', $r['out']);
        try {
            RmFixtures::labels(self::rm(), self::system(), $this->acct['account'], [RmFixtures::label('JV000000901GB', 'TPL48HV', '2026-11-12', '1369901')], 'api');
            self::fail('labels of the API');
        } catch (CwException $e) {
            self::assertSame('phase_2', $e->errorCode);
        }
    }

    /** DL84 2: a report covers its From..To, refused with a row outside them or a To not over; cover and labels commit together. */
    public function testAReportCoversItsFromToOnlyAndWithItsLabels(): void
    {
        $l = static fn (string $t, string $day): array => RmFixtures::label($t, 'TPL48HV', $day, '1369' . substr($t, 7, 3));
        foreach ([['a row after its To', [$l('JV000000911GB', '2026-11-13')], '2026-11-10', '2026-11-12'],
            ['a row before its From', [$l('JV000000912GB', '2026-11-09')], '2026-11-10', '2026-11-12'],
            ['a To not over yet', [$l('JV000000913GB', '2026-11-12')], '2026-11-10', (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d')]]
            as [$why, $labels, $from, $to]) {
            try {
                $this->report($labels, $from, $to);
                self::fail($why);
            } catch (CwException $e) {
                self::assertSame('bad_report_days', $e->errorCode, $why);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_label'));
        $r = $this->report([$l('JV000000914GB', '2026-11-10'), $l('JV000000915GB', '2026-11-12')], '2026-11-10', '2026-11-12');
        self::assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], array_map('strval', self::$db->column('SELECT day FROM carrier_day_cover WHERE import_id = ? ORDER BY day',
            [$r['import_id']])));
        // the cover cannot be written: nothing of the report is (its labels go with it)
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['INSERT INTO carrier_day_cover' => static fn () => throw new \PDOException('MySQL server has gone away')];
        $threw = false;
        try {
            RmFixtures::report(new RoyalMailBills($conn), self::system(), $this->acct['account'], [$l('JV000000916GB', '2026-11-14')], '2026-11-14', '2026-11-14',
                str_repeat('a', 64), 1);
        } catch (\Throwable) {
            $threw = true;
        } finally {
            \CW\Tests\Support\HookedStatement::$before = [];
        }
        self::assertTrue($threw);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM carrier_label WHERE tracking_no = 'JV000000916GB'"));
    }

    /** DL84 3a, changed by DL86 3: a CW Royal Mail parcel of a covered day in no report holds nothing; a report bringing it gives it the day's price. */
    public function testACoveredDayWaitsWhileACwParcelOfItIsInNoReport(): void
    {
        $day = '2026-11-12';
        self::order($this->vpg, '1921', '1369921', ['JV000000921GB' => [$day . 'T10:00:00Z', 'TPL48HV'], 'JV000000922GB' => [$day . 'T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000000921GB', 'TPL48HV', $day, '1369921')], $day, $day);
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 1, 254, '943')]))['invoice_id'];
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id), 'JV...922, labelled that day in CW, is in no report: nothing waits (DL86 3)');
        $this->report([RmFixtures::label('JV000000922GB', 'TPL48HV', $day, '1369921')], $day, $day);
        self::assertSame(["order|{$this->vpg}|" => 508, "shop|{$this->vpg}|" => -254], self::where($id), 'both in: the day prices (2 labels at 254, 1 billed: 1 not charged)');
        $this->assertRebuilds();
    }

    /** DL84 3b, changed by DL86 2: a day billed more than its labels waits no more: the extra units are the main shop's "not on an order". */
    public function testADayBilledMoreThanItsLabelsWaits(): void
    {
        $day = '2026-11-12';
        self::order($this->vpg, '1931', '1369931', ['JV000000931GB' => [$day . 'T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000000931GB', 'TPL48HV', $day, '1369931')], $day, $day);
        $a = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 1, 254, '943')], ['invoice' => '9070000931']))['invoice_id'];
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($a));
        $b = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 1, 254, '943')], ['invoice' => '9070000932',
            'date' => 'Nov 23, 2026']))['invoice_id'];
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($a), 'billed 2 for 1 label: the first bill\'s price stays on its order');
        self::assertSame(["shop|{$this->vpg}|" => 254], self::where($b), 'the second unit on Vape and Go\'s not on an order');
        self::assertSame(254, \CW\Profit\DeliveryBills::orderCost(self::$db, $this->vpg, '1931'));
        $this->assertRebuilds();
    }

    /**
     * DL84 4, changed by DL86 5 and 6: a manifest and a wrong-weight sales order are each priced on their own (no group); a key waits only while
     * a day of it (its rows', its labels') is covered by no report.
     */
    public function testAGroupWaitsWholeWhenAMemberIsNotCoveredOrUndated(): void
    {
        $sd = static fn (int $m): array => ['manifest_no' => $m, 'sales_order' => '5155500901', 'weight_g' => 80];
        self::order($this->vpg, '1941', '1369941', ['AS000000941GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->vpg, '1942', '1369942', ['AS000000942GB' => ['2026-11-12T11:00:00Z', 'SD1']]);
        self::order($this->vpg, '1944', '1369944', ['AS000000944GB' => ['2026-11-13T10:00:00Z', 'SD1']]);
        $this->report([RmFixtures::label('AS000000941GB', 'SD1', '2026-11-12', '1369941', $sd(2901)),
            RmFixtures::label('AS000000942GB', 'SD1', '2026-11-12', '1369942', $sd(2901))], '2026-11-12', '2026-11-12');
        // a label of the same sales order on another manifest, on the 13th, not covered (labels in CW with no report import covering their day)
        RmFixtures::labels(self::rm(), self::system(), $this->acct['account'], [RmFixtures::label('AS000000944GB', 'SD1', '2026-11-13', '1369944', $sd(2902))], 'report');
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-12', 'SD1', 2, 2008, 'CLICK AND DROP M2901', '5155500901'),
            RmBill::line('2026-11-14', 'RX1', 1, 275, 'ADJ5155500901RCN Surcharge')]))['invoice_id'];
        self::assertSame(['held||not_covered' => 275, "order|{$this->vpg}|" => 2008], self::where($id),
            'M2901 on its labels (2 x 1004 by weight); its sales order\'s wrong weight waits: a label of it on the 13th and the row\'s 14th covered by no report');
        $this->report([], '2026-11-13', '2026-11-13');
        self::assertSame(['held||not_covered' => 275, "order|{$this->vpg}|" => 2008], self::where($id), 'the 14th still covered by no report');
        $this->report([], '2026-11-14', '2026-11-14');
        self::assertSame(["order|{$this->vpg}|" => 2281, "shop|{$this->vpg}|" => 2], self::where($id), 'covered: the wrong weight 3 x 91 (2p left)');
        // an undated label of the sales order: no wait, it takes its share (on no CW parcel and of no day: held, not served)
        RmFixtures::labels(self::rm(), self::system(), $this->acct['account'], [RmFixtures::label('AS000000943GB', 'SD1', '2026-11-13', '1369943',
            ['manifest_no' => null, 'manifest_day' => null, 'manifested_at' => null, 'sales_order' => '5155500901', 'weight_g' => 80])], 'report');
        self::rm()->replan(self::system(), null, 3650);
        self::assertSame(['held||not_served' => 68, "order|{$this->vpg}|" => 2008 + 3 * 68, "shop|{$this->vpg}|" => 3], self::where($id));
        $this->assertRebuilds();
    }

    /** DL84 5, changed by DL86 4: a service code the plan does not map, or one other than the CW parcel's, holds nothing: no price, listed. */
    public function testAnUnmappedOrConflictingServiceHoldsItsDay(): void
    {
        self::order($this->vpg, '1951', '1369951', ['JV000000951GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '1952', '1369952', ['JV000000952GB' => ['2026-11-13T10:00:00Z', 'TPM24HV']]);
        $this->report([RmFixtures::label('JV000000951GB', 'TPL48HV', '2026-11-12', '1369951'), RmFixtures::label('JV000000953GB', 'TPL49HV', '2026-11-12', '1369953'),
            RmFixtures::label('JV000000952GB', 'TPL48HV', '2026-11-13', '1369952')], '2026-11-12', '2026-11-13');
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 254, '943'),
            RmBill::line('2026-11-13', 'TPL', 1, 254, '943')]))['invoice_id'];
        self::assertSame(["order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 254], self::where($id),
            'TPL49HV maps to nothing (12th: the good label priced); JV...952 is TPM24HV in CW, TPL48HV in the report (13th: no price, the unit on the line)');
        self::assertSame([['JV000000953GB', 'unknown_service'], ['JV000000952GB', 'service_differs']], array_map(static fn (array $i): array => [$i['tracking_no'], $i['why']],
            array_values(array_filter(\CW\Profit\RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12', '2026-11-13']),
                static fn (array $i): bool => $i['kind'] === 'odd_service'))), 'both on "needs a look"');
    }

    /** DL84 6: the main shop's start day and every day before it are wholly before CW start, a label on an order included. */
    public function testTheStartDayIsWhollyBeforeCwStart(): void
    {
        $day = '2026-10-28';
        self::order($this->vpg, '1961', '1369961', ['JV000000961GB' => [$day . 'T12:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000000961GB', 'TPL48HV', $day, '1369961')], $day, $day);
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 1, 254, '943')]))['invoice_id'];
        self::assertSame(["before_start|{$this->vpg}|" => 254], self::where($id), 'the start day: before CW start, never the order');
        self::assertSame(0, \CW\Profit\DeliveryBills::orderCost(self::$db, $this->vpg, '1961'));
    }

    /** DL84 7: an account's due days wait while it is off; one group that cannot be planned never stops the others. */
    public function testDueDaysWaitForAnOffAccountAndOneFailingGroupNeverBlocksTheOthers(): void
    {
        self::order($this->vpg, '1971', '1369971', ['JV000000971GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '1972', '1369972', ['JV000000972GB' => ['2026-11-13T10:00:00Z', 'TPL48HV']]);
        $a = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 254, '943'),
            RmBill::line('2026-11-13', 'TPL', 1, 254, '943')]))['invoice_id'];
        self::assertSame(['held||not_covered' => 508], self::where($a));
        self::$db->exec("UPDATE carrier_account SET state = 'off' WHERE id = ?", [$this->acct['account']]);
        $this->report([RmFixtures::label('JV000000971GB', 'TPL48HV', '2026-11-12', '1369971'), RmFixtures::label('JV000000972GB', 'TPL48HV', '2026-11-13', '1369972')],
            '2026-11-12', '2026-11-13');
        self::rm()->replan(self::system(), null, 1);
        self::assertSame(['held||not_covered' => 508], self::where($a), 'off: nothing planned, the days stay due');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM carrier_replan_due WHERE account_id = ?', [$this->acct['account']]));
        self::$db->exec("UPDATE carrier_account SET state = 'active' WHERE id = ?", [$this->acct['account']]);
        // the 12th's order held elsewhere: the 12th cannot be planned now; the 13th is all the same
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '1971')]));
        try {
            try {
                self::rm()->replan(self::system(), null, 1);
            } catch (CwException $e) {
                self::assertSame('partial', $e->errorCode, 'said, after the others were planned');
            }
        } finally {
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->vpg, '1971')]);
        }
        self::assertSame(['held||not_covered' => 254, "order|{$this->vpg}|" => 254], self::where($a));
        self::assertSame(['2026-11-12'], array_map('strval', self::$db->column('SELECT day FROM carrier_replan_due WHERE account_id = ?', [$this->acct['account']])));
        self::rm()->replan(self::system(), null, 1);
        self::assertSame(["order|{$this->vpg}|" => 508], self::where($a));
    }

    /** DL84 7: the check's take-out table keeps charges and credits apart. */
    public function testTheTakeOutKeepsChargesAndCreditsApart(): void
    {
        $a = ['invoice_id' => 7, 'row_no' => 1, 'kind' => 'label', 'how' => 'flat', 'label_id' => 1, 'dest' => 'order', 'channel_id' => 5, 'order_ref' => 'A',
            'parcel_ref' => 'odl:1', 'party_id' => null, 'held_reason' => null, 'day' => '2026-11-12', 'pence' => 1000];
        $b = ['label_id' => 2, 'order_ref' => 'B', 'parcel_ref' => 'odl:2', 'pence' => -1000] + $a;
        $split = new \ReflectionMethod(RoyalMailBills::class, 'split');
        $s = $split->invoke(null, ['desired' => [], 'add' => [], 'end' => [], 'existing' => [$a, $b]], 0, 7);
        self::assertSame(['2026-11-12|order|5||||+' => 1000, '2026-11-12|order|5||||-' => -1000], $s['gone']);
    }

    /** DL84 8: the health alert says a week covered by no report by Tuesday 12:00, and days waiting more than 14 days. */
    public function testTheHealthAlertSaysAWeekWithNoReportAndDaysWaitingLong(): void
    {
        $tuesday = new \DateTimeImmutable('2026-11-24 12:30:00', new \DateTimeZone('Europe/London'));
        $this->report([], '2026-11-09', '2026-11-15');
        self::assertSame([], RoyalMailBills::health(self::$db, $tuesday->modify('-1 hour')), 'before Tuesday 12:00 the week due is 9-15 Nov: covered');
        self::assertSame(['rm_report_missing'], array_column(RoyalMailBills::health(self::$db, $tuesday), 'kind'), 'Mon 16 - Sun 22 Nov not covered by Tuesday 12:00');
        $this->report([], '2026-11-16', '2026-11-22');
        self::assertSame([], RoyalMailBills::health(self::$db, $tuesday));
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-05', 'TPL', 1, 254, '943')]))['invoice_id'];
        self::assertSame(['held||not_covered' => 254], self::where($id));
        $p = RoyalMailBills::health(self::$db, $tuesday);
        self::assertSame(['rm_days_waiting'], array_column($p, 'kind'), 'the 5th has waited 19 days');
        self::assertSame(1, $p[0]['data']['days']);
    }
}
