<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Invariants;
use CW\Profit\DeliveryBills;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Tests\Support\DpdCsv;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The DPD bills in CW (delivery cost in profit, step 2 part 1; docs/decisions.md DL1-DL21), from SYNTHETIC files in DPD's layout: a week
 * stored with each line in one state (matched to a CW parcel in the date window, a collection on an order, before CW start, not matched,
 * ambiguous), the day figures by the order's paid day and the line's date equal to a rebuild from scratch after every write (upload, the
 * same file again, a corrected file, undo, a line matched later, an order paid or moved later, a re-send, a parcel removed); the shop of an
 * account; only the Delivery bills job writes; nothing personal kept, no file kept; the nightly checks and the tools.
 */
final class DeliveryBillsTest extends StockTestCase
{
    private const START = '2026-10-28 10:28:00.000000';

    /** A shop whose first reservation in CW is START (Vape and Go's on live: 28 Oct 2026 10:28 UTC). */
    private function shop(string $code = 'vapeandgo'): int
    {
        $ch = (int) $this->site($code, 'live')->channelId;
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', ?, ?)",
            [$ch, self::START, self::START]);
        return $ch;
    }

    /** @return array<string, mixed> a DPD parcel as the shop sends it */
    private static function parcel(string $ref, ?string $tracking, string $label, array $over = []): array
    {
        return $over + ['parcel_ref' => $ref, 'tracking_no' => $tracking, 'carrier' => 'dpd', 'service_code' => '12', 'service' => 'DPD', 'status' => 'generated',
            'label_at' => $label, 'shipped_at' => $label, 'manual' => false, 'partial' => false];
    }

    /** An order with one goods line and its parcels, stored and worked out now. */
    private static function order(int $ch, string $ref, string $no, ?string $paid, array $parcels, array $over = []): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, $over + ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-11-01T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1699, 'parcels' => $parcels],
            'api');
    }

    private static function bills(): DeliveryBills
    {
        return new DeliveryBills(self::$db);
    }

    private static function system(): Caller
    {
        return Caller::system('delivery_test');
    }

    /** @return array<string, int> "day|field" => pence: the delivery figures kept on the store's days (rows of zeros left out) */
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

    /**
     * Every day of the store from 20 Oct to 20 Nov is what its orders and bill lines add up to (the rebuild check, PR104). A row of zeros a
     * snapshot leaves on the day an order moved away from is the snapshots' own (FinalReviewSnapshotTest; the nightly --apply removes it): not
     * a figure, left out here.
     */
    private static function assertDaysRebuild(int $ch): void
    {
        for ($d = '2026-10-20'; $d <= '2026-11-20'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
            $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
            self::assertSame([], $diff, "day {$d}: the kept figures are what a rebuild works out");
        }
    }

    /** @return list<string> the states of a bill's lines in line order */
    private static function states(int $id): array
    {
        return array_map('strval', self::$db->column('SELECT match_state FROM carrier_invoice_line WHERE invoice_id = ? ORDER BY line_no', [$id]));
    }

    /** @return array{0: int, 1: string} the shop and the bytes of the week of DL7-DL10's shapes */
    private function week(): array
    {
        $ch = $this->shop();
        self::order($ch, '1000', '1356063', '2026-11-04T23:06:47Z', [self::parcel('odl:1', '6599195040', '2026-11-05T10:00:00Z')]);
        self::order($ch, '1001', '1356064', '2026-11-05T08:00:00Z', [self::parcel('odl:2', '6599195041', '2026-11-05T12:00:00Z')]);
        self::order($ch, '1002', '1356070', '2026-11-03T10:00:00Z', []);
        self::order($ch, '1003', '1356071', '2026-11-06T09:00:00Z', [self::parcel('odl:4', '6599195045', '2026-11-06T10:00:00Z')]);
        self::order($ch, '1004', '1356072', '2026-11-06T09:30:00Z', [self::parcel('odl:5', '6599195045', '2026-11-06T11:00:00Z')]);
        self::order($ch, '1005', '1356073', '2026-11-01T09:00:00Z', [self::parcel('odl:6', '6599195046', '2026-10-20T10:00:00Z')]);
        $bytes = DpdCsv::file([
            DpdCsv::line('05/11/2026', '6599195040/0', 420),
            DpdCsv::line('05/11/2026', '6599195041/0#', 897, ['Service Description' => 'SAT', 'Senders Ref' => '1356064']),
            DpdCsv::line('05/11/2026', '6599195040/0', 0, ['money' => [], 'blank_money' => true, 'Parcel No' => '15976599195049']),
            DpdCsv::line('07/11/2026', '6599195040/0*', -230, ['money' => ['Revenue' => -210, 'Fuel and Energy Charge' => -20]]),
            DpdCsv::line('08/11/2026', '6599195099/0', 1550, ['money' => ['Third Party Collection' => 1300, 'Fuel and Energy Charge' => 137, 'Carriage Charge' => 13,
                'Global Energy Charge' => 100], 'Senders Ref' => '1356070']),
            DpdCsv::line('07/11/2026', '6599195045/0', 420),
            DpdCsv::line('08/11/2026', '6599195046/0', 420),
            DpdCsv::line('27/10/2026', '6599100000/0', 420),
            DpdCsv::line('28/10/2026', '6599100001/0', 420),
            DpdCsv::line('08/11/2026', '6599100002/0', 1169, ['VAT Code' => 'Z', 'Senders Ref' => 'man return Test Person']),
        ], ['invoice' => '62368793']);
        return [$ch, $bytes];
    }

    public function testAWeekIsStoredWithEachLineInOneStateAndCountedOnItsDay(): void
    {
        [$ch, $bytes] = $this->week();
        $r = self::bills()->upload(self::system(), 'dpd', $bytes);
        self::assertSame(['stored', '62368793', 0], [$r['result'], $r['invoice_no'], $r['rematched']]);
        $inv = (array) self::$db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]);
        self::assertSame(['dpd', '3012002', '62368793', 'live', (string) $ch, '5486', '10', '2026-10-27', '2026-11-08', hash('sha256', $bytes), 'system:delivery_test'],
            [$inv['carrier'], $inv['account'], $inv['invoice_no'], $inv['status'], (string) $inv['channel_id'], (string) $inv['net_pence'], (string) $inv['line_count'],
                $inv['period_from'], $inv['period_to'], $inv['file_sha256'], $inv['uploaded_actor']]);
        self::assertSame(['matched', 'matched', 'matched', 'matched', 'collection', 'ambiguous', 'not_matched', 'before_start', 'before_start', 'not_matched'],
            self::states($r['invoice_id']));
        self::assertSame([['1000', 'odl:1'], ['1001', 'odl:2'], ['1000', 'odl:1'], ['1000', 'odl:1'], ['1002', null], [null, null], [null, null], [null, null], [null, null],
            [null, null]], array_map(static fn (array $l): array => [$l['order_ref'], $l['parcel_ref']],
                self::$db->all('SELECT order_ref, parcel_ref FROM carrier_invoice_line WHERE invoice_id = ? ORDER BY line_no', [$r['invoice_id']])));
        self::assertSame('2', (string) self::$db->value('SELECT candidates FROM carrier_invoice_line WHERE invoice_id = ? AND line_no = 6', [$r['invoice_id']]),
            'two parcels of that number in the window: a list to check');
        self::assertSame(['', '#', '', '*', '', '', '', '', '', ''], array_map('strval', self::$db->column('SELECT marks FROM carrier_invoice_line WHERE invoice_id = ? ORDER BY line_no',
            [$r['invoice_id']])));
        // The profit: on the order's paid day (A 4 Nov: 420 + 0 - 230; B 5 Nov; the collection on C's day, 3 Nov); not on an order by the line's
        // date (the ambiguous line 7 Nov, the two not matched 8 Nov); before CW start nowhere.
        self::assertSame(['2026-11-03|delivery_cost' => 1550, '2026-11-04|delivery_cost' => 190, '2026-11-05|delivery_cost' => 897, '2026-11-07|delivery_other' => 420,
            '2026-11-08|delivery_other' => 420 + 1169], self::days($ch));
        self::assertDaysRebuild($ch);
        self::assertSame(190, DeliveryBills::orderCost(self::$db, $ch, '1000'));
        // Nothing personal and no file kept (DL4): the lines hold none of the made-up names, addresses or notes; no stored file.
        $kept = json_encode([self::$db->all('SELECT * FROM carrier_invoice_line'), self::$db->all('SELECT * FROM carrier_invoice'),
            self::$db->all("SELECT detail FROM audit_log WHERE action LIKE 'delivery_bill.%'")], JSON_THROW_ON_ERROR);
        foreach ([...array_values(DpdCsv::PERSONAL), 'Test Person', 'man return'] as $text) {
            self::assertStringNotContainsString($text, $kept, "{$text} is never kept");
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'), 'the file itself is not kept: its fingerprint only');
        self::assertSame(['delivery_bill.upload'], array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'delivery_bill.%'")));
    }

    public function testTheSameFileTwiceChangesNothingAndAnotherFileOfTheSameBillIsRefused(): void
    {
        [$ch, $bytes] = $this->week();
        $first = self::bills()->upload(self::system(), 'dpd', $bytes);
        $days = self::days($ch);
        $again = self::bills()->upload(self::system(), 'dpd', $bytes);
        self::assertSame(['same', $first['invoice_id'], $first['uploaded_at']], [$again['result'], $again['invoice_id'], $again['uploaded_at']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame($days, self::days($ch), 'nothing counted twice');
        // The same bill in another file (DPD corrected a line): refused, unless it goes through Replace.
        $other = DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 421)], ['invoice' => '62368793']);
        try {
            self::bills()->upload(self::system(), 'dpd', $other);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame(['already_uploaded', 409, $first['invoice_id']], [$e->errorCode, $e->httpStatus, $e->detail['invoice_id']]);
            self::assertStringContainsString('DPD bill 62368793 was uploaded on ', $e->getMessage());
            self::assertStringContainsString('Replace with a corrected file', $e->getMessage());
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame($days, self::days($ch));
        // A refused file stores nothing at all (its sums do not tie).
        try {
            self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 421)], ['invoice' => '62368800', 'net' => 420, 'vat' => 84,
                'gross' => 504]));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame(10, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice_line'));
    }

    public function testACorrectedFileReplacesTheBillAndUndoTakesItOutOfTheProfit(): void
    {
        [$ch, $bytes] = $this->week();
        $first = self::bills()->upload(self::system(), 'dpd', $bytes);
        // DPD's corrected file: A's parcel 450 (not 420), the credit gone, the ambiguous line gone.
        $corrected = DpdCsv::file([
            DpdCsv::line('05/11/2026', '6599195040/0', 450),
            DpdCsv::line('05/11/2026', '6599195041/0#', 897),
            DpdCsv::line('08/11/2026', '6599195046/0', 420),
        ], ['invoice' => '62368793']);
        $r = self::bills()->replace(self::system(), $first['invoice_id'], $corrected);
        self::assertSame('stored', $r['result']);
        self::assertNotSame($first['invoice_id'], $r['invoice_id']);
        self::assertSame(['superseded', 'live'], array_map('strval', self::$db->column('SELECT status FROM carrier_invoice ORDER BY id')));
        self::assertSame((string) $first['invoice_id'], (string) self::$db->value('SELECT supersedes_id FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        self::assertSame('system:delivery_test', (string) self::$db->value('SELECT ended_actor FROM carrier_invoice WHERE id = ?', [$first['invoice_id']]));
        self::assertSame(13, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice_line'), 'the old lines kept (history)');
        self::assertSame(['2026-11-04|delivery_cost' => 450, '2026-11-05|delivery_cost' => 897, '2026-11-08|delivery_other' => 420], self::days($ch));
        self::assertDaysRebuild($ch);
        // The same corrected file again: nothing; a file of another bill: refused; the superseded bill: refused.
        self::assertSame('same', self::bills()->replace(self::system(), $r['invoice_id'], $corrected)['result']);
        foreach ([[$r['invoice_id'], DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 450)], ['invoice' => '62368794']), 'other_bill'],
            [$first['invoice_id'], $bytes, 'not_live']] as [$id, $file, $code]) {
            try {
                self::bills()->replace(self::system(), $id, $file);
                self::fail("refused: {$code}");
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        // Undo: the bill undone, kept, out of the profit; its day rows with nothing left removed.
        self::bills()->undo(self::system(), $r['invoice_id']);
        self::assertSame(['superseded', 'undone'], array_map('strval', self::$db->column('SELECT status FROM carrier_invoice ORDER BY id')));
        self::assertSame([], self::days($ch));
        self::assertNull(self::$db->value("SELECT day FROM profit_day WHERE channel_id = ? AND day = '2026-11-08'", [$ch]), 'no row of zeros left');
        self::assertDaysRebuild($ch);
        try {
            self::bills()->undo(self::system(), $r['invoice_id']);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('not_live', $e->errorCode);
        }
        // The first file again after the undo: a bill of its own, live.
        $again = self::bills()->upload(self::system(), 'dpd', $bytes);
        self::assertSame('stored', $again['result']);
        self::assertSame(['2026-11-03|delivery_cost' => 1550, '2026-11-04|delivery_cost' => 190, '2026-11-05|delivery_cost' => 897, '2026-11-07|delivery_other' => 420,
            '2026-11-08|delivery_other' => 1589], self::days($ch));
        self::assertDaysRebuild($ch);
        self::assertSame(['delivery_bill.upload', 'delivery_bill.replace', 'delivery_bill.undo', 'delivery_bill.upload'],
            array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'delivery_bill.%' ORDER BY id")));
    }

    public function testALineNotMatchedIsPutOnItsParcelWhenTheParcelArrivesAndNeverMovedAfter(): void
    {
        $ch = $this->shop();
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599195050/0', 420)]), $ch);
        self::assertSame(['not_matched'], self::states($bill['invoice_id']));
        self::assertSame(['2026-11-09|delivery_other' => 420], self::days($ch));
        // Its parcel reaches CW later (a late snapshot): the nightly run puts the line on it, and the cost moves to the order's day.
        self::order($ch, '1010', '1356080', '2026-11-08T10:00:00Z', [self::parcel('odl:10', '6599195050', '2026-11-08T12:00:00Z')]);
        self::assertSame(1, self::bills()->rematch(self::system()));
        self::assertSame(['matched'], self::states($bill['invoice_id']));
        self::assertSame(['2026-11-08|delivery_cost' => 420], self::days($ch));
        self::assertNull(self::$db->value("SELECT day FROM profit_day WHERE channel_id = ? AND day = '2026-11-09'", [$ch]));
        self::assertDaysRebuild($ch);
        // Another parcel of the same number in the window later: the line stays on its parcel (never moved).
        self::order($ch, '1011', '1356081', '2026-11-08T11:00:00Z', [self::parcel('odl:11', '6599195050', '2026-11-08T13:00:00Z')]);
        self::assertSame(0, self::bills()->rematch(self::system()));
        self::assertSame([['1010', 'odl:10']], array_map(static fn (array $l): array => [$l['order_ref'], $l['parcel_ref']],
            self::$db->all('SELECT order_ref, parcel_ref FROM carrier_invoice_line')));
        // A newer snapshot of its order without the parcel (the shop cleared the dispatch): the charge DPD billed stays on the order.
        self::order($ch, '1010', '1356080', '2026-11-08T10:00:00Z', [], ['version' => 2]);
        self::assertNotNull(self::$db->value("SELECT removed_at FROM sale_parcel WHERE channel_id = ? AND parcel_ref = 'odl:10'", [$ch]));
        self::assertSame(['matched'], self::states($bill['invoice_id']));
        self::assertSame(['2026-11-08|delivery_cost' => 420], self::days($ch));
        self::assertDaysRebuild($ch);
        // The nightly tool does the same (and the rebuild check finds nothing to change).
        self::assertSame(0, self::script('match_delivery_bills')['code']);
        $check = self::script('rebuild_profit_days', '--from=2026-11-01', '--to=2026-11-12', '--check');
        self::assertSame(0, $check['code'], $check['out']);
    }

    public function testAnOrderPaidOrMovedLaterTakesItsDeliveryCostAlongAndASnapshotNeverWipesIt(): void
    {
        $ch = $this->shop();
        self::order($ch, '1020', '1356090', null, [self::parcel('odl:20', '6599195060', '2026-11-05T10:00:00Z')]);
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('06/11/2026', '6599195060/0', 500)]));
        self::assertSame(['matched'], self::states($bill['invoice_id']), 'on its parcel, whatever the order\'s payment');
        self::assertSame([], self::days($ch), 'an order not paid counts nothing yet, as its goods');
        self::assertDaysRebuild($ch);
        self::order($ch, '1020', '1356090', '2026-11-06T09:00:00Z', [self::parcel('odl:20', '6599195060', '2026-11-05T10:00:00Z')], ['version' => 2]);
        self::assertSame(['2026-11-06|delivery_cost' => 500], self::days($ch), 'paid: on its paid day');
        self::assertDaysRebuild($ch);
        self::order($ch, '1020', '1356090', '2026-11-07T09:00:00Z', [self::parcel('odl:20', '6599195060', '2026-11-05T10:00:00Z')], ['version' => 3]);
        self::assertSame(['2026-11-07|delivery_cost' => 500], self::days($ch), 'moved with its order');
        self::assertDaysRebuild($ch);
        // A newer snapshot with the same day (a refund, a status) never wipes it.
        self::order($ch, '1020', '1356090', '2026-11-07T09:00:00Z', [self::parcel('odl:20', '6599195060', '2026-11-05T10:00:00Z')],
            ['version' => 4, 'status' => 'refunded', 'order_refund_inc' => 1200]);
        self::assertSame(['2026-11-07|delivery_cost' => 500], self::days($ch));
        self::assertSame(500, DeliveryBills::orderCost(self::$db, $ch, '1020'));
        self::assertDaysRebuild($ch);
    }

    public function testAReSendsParcelCountsOnTheReSendsOwnDay(): void
    {
        $ch = $this->shop();
        self::order($ch, '1000', '1356063', '2026-11-04T10:00:00Z', [self::parcel('odl:1', '6599195040', '2026-11-04T12:00:00Z')]);
        self::order($ch, '1030', '1356100', '2026-11-06T10:00:00Z', [self::parcel('odl:30', '6599195070', '2026-11-06T12:00:00Z')], ['parent_ref' => '1000',
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 0, 'refund_inc' => 0]]);
        self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 420), DpdCsv::line('07/11/2026', '6599195070/0', 430)]));
        // As goods (PR129, PR143): in the days each on its own order's day; on the first order's page with its re-sends (ProfitViews).
        self::assertSame(['2026-11-04|delivery_cost' => 420, '2026-11-06|delivery_cost' => 430], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    public function testTheLineDateWindowIsTenDaysAfterTheLabelAndThreeBefore(): void
    {
        $ch = $this->shop();
        $ref = ['Senders Ref' => '1356110']; // the order's own number: not below the shop's first order in CW (DL30)
        self::order($ch, '1040', '1356110', '2026-11-01T09:00:00Z', [self::parcel('odl:40', '6599195080', '2026-11-01T10:00:00Z'),
            self::parcel('odl:41', '6599195081', '2026-11-01T10:00:00Z'), self::parcel('odl:42', '6599195082', '2026-11-10T10:00:00Z'),
            self::parcel('odl:43', '6599195083', '2026-11-11T10:00:00Z'), self::parcel('odl:44', '15976599195084', '2026-11-05T10:00:00Z')]);
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([
            DpdCsv::line('11/11/2026', '6599195080/0', 100, $ref), // 10 days after the label: matched
            DpdCsv::line('12/11/2026', '6599195081/0', 100, $ref), // 11 days: not
            DpdCsv::line('07/11/2026', '6599195082/0', 100, $ref), // 3 days before: matched
            DpdCsv::line('07/11/2026', '6599195083/0', 100, $ref), // 4 days before: not
            // The consignment only (the lead's decision 4; Codex r1 MEDIUM 3): DPD's parcel number never matches, nor makes a line ambiguous.
            DpdCsv::line('06/11/2026', '6599195099/0', 100, ['Parcel No' => '15976599195084'] + $ref),
            DpdCsv::line('06/11/2026', '6599195080/0', 100, ['Parcel No' => '15976599195084'] + $ref),
        ]));
        self::assertSame(['matched', 'not_matched', 'matched', 'not_matched', 'not_matched', 'matched'], self::states($bill['invoice_id']));
        self::assertSame('odl:40', (string) self::$db->value('SELECT parcel_ref FROM carrier_invoice_line WHERE invoice_id = ? AND line_no = 6', [$bill['invoice_id']]));
        // A parcel of another carrier, or a manual one, with the same number is never DPD's.
        self::order($ch, '1041', '1356111', '2026-11-01T09:00:00Z', [self::parcel('odl:45', '6599195085', '2026-11-05T10:00:00Z', ['carrier' => 'royal_mail'])]);
        $rm = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('06/11/2026', '6599195085/0', 100, $ref)], ['invoice' => '62999002']));
        self::assertSame(['not_matched'], self::states($rm['invoice_id']));
        self::assertDaysRebuild($ch);
    }

    public function testTheShopAnAccountBillsIsItsEarlierBillsOrItsParcelsOrTheOneChosen(): void
    {
        $vpg = $this->shop();
        $ef = $this->shop('electrofag');
        $file = static fn (string $account, string $no, string $cons = '6599100009/0'): string => DpdCsv::file([DpdCsv::line('09/11/2026', $cons, 420)],
            ['account' => $account, 'invoice' => $no]);
        try {
            self::bills()->upload(self::system(), 'dpd', $file('4000001', '1'));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame(['account_shop_unknown', 422], [$e->errorCode, $e->httpStatus]);
            self::assertStringContainsString('DPD account 4000001 is new to CW', $e->getMessage());
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        $a = self::bills()->upload(self::system(), 'dpd', $file('4000001', '1'), $vpg);
        self::assertSame((string) $vpg, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$a['invoice_id']]));
        $b = self::bills()->upload(self::system(), 'dpd', $file('4000001', '2'));
        self::assertSame((string) $vpg, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$b['invoice_id']]), 'its earlier bills say');
        try {
            self::bills()->upload(self::system(), 'dpd', $file('4000001', '3'), $ef);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop', $e->errorCode);
        }
        // An account new to CW whose parcels are Electrofag's: Electrofag (a choice of another shop refused).
        self::order($ef, '2000', '2356063', '2026-11-08T09:00:00Z', [self::parcel('odl:200', '6599100099', '2026-11-08T10:00:00Z')]);
        try {
            self::bills()->upload(self::system(), 'dpd', $file('4000002', '1', '6599100099/0'), $vpg);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop', $e->errorCode);
        }
        $c = self::bills()->upload(self::system(), 'dpd', $file('4000002', '1', '6599100099/0'));
        self::assertSame((string) $ef, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$c['invoice_id']]));
        self::assertSame(['matched'], self::states($c['invoice_id']));
        // A shop with no reservation in CW yet: every line not on a parcel is before its start.
        $vb = (int) $this->site('vapebig', 'live')->channelId;
        $d = self::bills()->upload(self::system(), 'dpd', $file('4000003', '1'), $vb);
        self::assertSame(['before_start'], self::states($d['invoice_id']));
        self::assertDaysRebuild($vpg);
        self::assertDaysRebuild($ef);
    }

    public function testOnlyTheDeliveryBillsJobWritesAndNeverAdmin(): void
    {
        [$ch, $bytes] = $this->week();
        $staff = static function (string ...$roles): Caller {
            $n = bin2hex(random_bytes(4));
            $id = self::$db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES (?, ?, ?, 'x')", ["u{$n}", "u{$n}@test.example", "U {$n}"]);
            foreach ($roles as $r) {
                self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$id, $r]);
            }
            return Caller::staff($id);
        };
        foreach ([[$staff('finance'), 'role_not_allowed'], [$staff('reviewer', 'buyer'), 'role_not_allowed'], [$staff('admin', 'auditor'), 'admin_cannot_post'],
            [Caller::channel($ch, 'vapeandgo'), 'staff_required']] as [$who, $code]) {
            try {
                self::bills()->upload($who, 'dpd', $bytes);
                self::fail("refused: {$code}");
            } catch (CwException $e) {
                self::assertSame([$code, 403], [$e->errorCode, $e->httpStatus]);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        $person = $staff('delivery_bills');
        $r = self::bills()->upload($person, 'dpd', $bytes);
        self::assertSame((string) $person->staffUserId, (string) self::$db->value('SELECT uploaded_by FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        try {
            self::bills()->undo($staff('finance'), $r['invoice_id']);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('role_not_allowed', $e->errorCode);
        }
        try {
            self::bills()->upload($person, 'royal_mail', $bytes);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bad_carrier', $e->errorCode);
        }
        self::bills()->undo($person, $r['invoice_id']);
        self::assertSame((string) $person->staffUserId, (string) self::$db->value('SELECT ended_by FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
    }

    public function testTheNightlyChecksFindABillThatNoLongerAddsUpOrAnAccountOnTwoShops(): void
    {
        [$ch, $bytes] = $this->week();
        $r = self::bills()->upload(self::system(), 'dpd', $bytes);
        self::assertSame([], Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_invoice_line SET cost = cost + 1 WHERE invoice_id = ? AND line_no = 1', [$r['invoice_id']]);
        self::assertContains("DB3 bill {$r['invoice_id']} (62368793): its 10 lines add up to 5487p, it says 10 lines and 5486p net", Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_invoice_line SET cost = cost - 1 WHERE invoice_id = ? AND line_no = 1', [$r['invoice_id']]);
        $ef = (int) $this->site('electrofag', 'live')->channelId;
        self::$db->exec("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, vat_pence, gross_pence, line_count, "
            . "file_sha256, status, uploaded_actor, uploaded_at, ended_actor, ended_at) VALUES ('dpd', '3012002', '1', '2026-11-01', '2026-11-01', ?, 0, 0, 0, 0, ?, "
            . "'superseded', 'system:x', UTC_TIMESTAMP(6), 'system:x', UTC_TIMESTAMP(6))", [$ef, str_repeat('a', 64)]);
        self::assertContains('DB5 dpd account 3012002: its bills name 2 shops', Invariants::check(self::$db));
        self::$db->exec("DELETE FROM carrier_invoice WHERE invoice_no = '1'");
        // A replacement uploaded before the bill it replaces (Codex r1 LOW 2): named.
        $early = self::$db->insert("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, vat_pence, gross_pence, "
            . "line_count, file_sha256, status, uploaded_actor, uploaded_at, ended_actor, ended_at) VALUES ('dpd', '3012002', '7', '2026-11-01', '2026-11-01', ?, 0, 0, 0, 0, ?, "
            . "'undone', 'system:x', UTC_TIMESTAMP(6), 'system:x', UTC_TIMESTAMP(6))", [$ch, str_repeat('d', 64)]);
        $late = self::$db->insert("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, vat_pence, gross_pence, "
            . "line_count, file_sha256, status, uploaded_actor, uploaded_at, ended_actor, ended_at) VALUES ('dpd', '3012002', '7', '2026-11-01', '2026-11-01', ?, 0, 0, 0, 0, ?, "
            . "'superseded', 'system:x', UTC_TIMESTAMP(6), 'system:x', UTC_TIMESTAMP(6))", [$ch, str_repeat('e', 64)]);
        self::$db->exec('UPDATE carrier_invoice SET supersedes_id = ? WHERE id = ?', [$late, $early]);
        self::assertContains("DB6 bill {$late} (superseded): named as replaced by 1 bills, some of another bill or uploaded before it", Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_invoice SET supersedes_id = NULL WHERE id = ?', [$early]);
        self::$db->exec('UPDATE carrier_invoice SET supersedes_id = id WHERE id = ?', [$late]);
        self::assertContains("DB6 bill {$late} (superseded): named as replaced by 1 bills, some of another bill or uploaded before it", Invariants::check(self::$db),
            'a bill naming itself (the Codex check r2, LOW 2)');
        self::$db->exec('UPDATE carrier_invoice SET supersedes_id = NULL WHERE id IN (?, ?)', [$early, $late]);
        self::$db->exec('DELETE FROM carrier_invoice WHERE id IN (?, ?)', [$early, $late]);
        // The database itself refuses the rest: two live bills of one key, a line in two states' shapes, a matched line on no parcel.
        self::assertSame(1062, self::mysqlError(static fn () => self::$db->exec("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, "
            . "channel_id, net_pence, vat_pence, gross_pence, line_count, file_sha256, uploaded_actor, uploaded_at) VALUES ('dpd', '3012002', '62368793', '2026-11-01', "
            . "'2026-11-01', ?, 0, 0, 0, 0, ?, 'system:x', UTC_TIMESTAMP(6))", [$ch, str_repeat('b', 64)])));
        self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec("UPDATE carrier_invoice_line SET match_state = 'not_matched' WHERE invoice_id = ? AND line_no = 1",
            [$r['invoice_id']])));
        self::assertSame(1452, self::mysqlError(static fn () => self::$db->exec("UPDATE carrier_invoice_line SET match_state = 'matched', channel_id = ?, order_ref = '1000', "
            . "parcel_ref = 'odl:999', matched_at = UTC_TIMESTAMP(6) WHERE invoice_id = ? AND line_no = 7", [$ch, $r['invoice_id']])));
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testTheServerToolChecksAFileAndPrintsNoLine(): void
    {
        [$ch, $bytes] = $this->week();
        $dir = sys_get_temp_dir() . '/cw_dpd_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir . '/bill.csv', $bytes);
            $check = self::script('delivery_bill', '--file=' . $dir . '/bill.csv');
            self::assertSame(0, $check['code'], $check['out']);
            self::assertStringContainsString('DPD account 3012002 bill 62368793: 2026-10-27 to 2026-11-08, 10 lines, net £54.86', $check['out']);
            self::assertMatchesRegularExpression('/matched\s+4 lines\s+£10\.87/', $check['out']);
            self::assertMatchesRegularExpression('/before_start\s+2 lines\s+£8\.40/', $check['out']);
            self::assertStringContainsString('checked only: nothing written', $check['out']);
            foreach ([...array_values(DpdCsv::PERSONAL), '6599195040'] as $text) {
                self::assertStringNotContainsString($text, $check['out'], 'no line is printed');
            }
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
            $up = self::script('delivery_bill', '--file=' . $dir . '/bill.csv', '--upload');
            self::assertSame(0, $up['code'], $up['out']);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE uploaded_actor = 'system:delivery_cli'"));
            self::assertStringContainsString('this very file is live already', self::script('delivery_bill', '--file=' . $dir . '/bill.csv')['out']);
            file_put_contents($dir . '/bad.csv', DpdCsv::file([DpdCsv::line('05/11/2026', '6599195040/0', 420)], ['net' => 1, 'vat' => 0, 'gross' => 1]));
            $bad = self::script('delivery_bill', '--file=' . $dir . '/bad.csv', '--upload');
            self::assertSame(1, $bad['code'], $bad['out']);
            self::assertStringContainsString('the lines add up to £4.20', $bad['out']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r1, HIGH 2: only a line put on ONE parcel in the window says which shop a new account bills: a number another shop used
     * months ago says nothing; two shops as many: the person chooses; an undone bill says nothing either.
     */
    public function testAnOldNumberOfAnotherShopOrAnUndoneBillNeverDecidesTheShopOfAnAccount(): void
    {
        $vpg = $this->shop();
        $ef = $this->shop('electrofag');
        self::order($ef, '2000', '2356063', '2026-09-01T09:00:00Z', [self::parcel('odl:200', '6599100099', '2026-09-01T10:00:00Z')]);
        $file = DpdCsv::file([DpdCsv::line('09/11/2026', '6599100099/0', 420)], ['account' => '4000005', 'invoice' => '1']);
        try {
            self::bills()->upload(self::system(), 'dpd', $file);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop_unknown', $e->errorCode, 'Electrofag\'s number of September is no evidence');
        }
        $a = self::bills()->upload(self::system(), 'dpd', $file, $vpg);
        self::assertSame((string) $vpg, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$a['invoice_id']]));
        self::assertSame(['not_matched'], self::states($a['invoice_id']));
        // Two shops as many parcels: the person chooses.
        self::order($vpg, '1050', '1356150', '2026-11-08T09:00:00Z', [self::parcel('odl:50', '6599100050', '2026-11-08T10:00:00Z')]);
        self::order($ef, '2001', '2356064', '2026-11-08T09:00:00Z', [self::parcel('odl:201', '6599100051', '2026-11-08T10:00:00Z')]);
        $tie = DpdCsv::file([DpdCsv::line('09/11/2026', '6599100050/0', 420), DpdCsv::line('09/11/2026', '6599100051/0', 420)], ['account' => '4000006', 'invoice' => '1']);
        try {
            self::bills()->upload(self::system(), 'dpd', $tie);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop_unknown', $e->errorCode);
            self::assertStringContainsString('as many one shop\'s as another\'s', $e->getMessage());
        }
        $t = self::bills()->upload(self::system(), 'dpd', $tie, $ef);
        self::assertSame((string) $ef, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$t['invoice_id']]));
        // A bill uploaded for the wrong shop and undone binds nothing.
        $w = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100009/0', 420)], ['account' => '4000007', 'invoice' => '1']), $ef);
        self::bills()->undo(self::system(), $w['invoice_id']);
        $r = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100009/0', 420)], ['account' => '4000007', 'invoice' => '1']), $vpg);
        self::assertSame((string) $vpg, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        self::assertSame([], Invariants::check(self::$db), 'an undone bill is no evidence of its shop: DB5 says nothing (the Codex check r2, LOW 2)');
        self::assertDaysRebuild($vpg);
        self::assertDaysRebuild($ef);
    }

    /** A service on its own connection whose statements run HookedStatement::$before (another session's write between a read and a write). */
    private static function hookedBills(int $wait = DeliveryBills::LOCK_WAIT_SEC): DeliveryBills
    {
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        return new DeliveryBills($conn, null, null, $wait);
    }

    /**
     * The Codex check r1, HIGH 3: the parcel a line was put on is removed by its order's snapshot after the bill read it and before the bill
     * holds the order: the decisions are taken again once the orders are held, the plan is old, the bill is tried again and the line is not
     * matched.
     */
    public function testAParcelRemovedWhileTheBillWaitsIsNotMatched(): void
    {
        $ch = $this->shop();
        self::order($ch, '1060', '1356160', '2026-11-08T09:00:00Z', [self::parcel('odl:60', '6599100060', '2026-11-08T10:00:00Z')]);
        // Between the plan's read and the bill's hold on the order (its first read of the orders' days): the order's snapshot drops the parcel.
        HookedStatement::$before = ['SELECT order_ref, day FROM sale_order WHERE channel_id = ?' => static function () use ($ch): void {
            self::order($ch, '1060', '1356160', '2026-11-08T09:00:00Z', [], ['version' => 2]);
        }];
        try {
            $r = self::hookedBills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100060/0', 420, ['Senders Ref' => '1356160'])]), $ch);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame(['not_matched'], self::states($r['invoice_id']), 'its parcel was gone before the bill was written');
        self::assertSame(['2026-11-09|delivery_other' => 420], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r1, HIGH 3 / MEDIUM 4: while a bill is written, an order not paid that a line is on is held by its snapshot guard (no row
     * lock): a snapshot that would pay it waits, and here gives up (busy); the bill is written with the order not paid.
     */
    public function testASnapshotOfAnUnpaidOrderWaitsForTheBill(): void
    {
        $ch = $this->shop();
        self::order($ch, '1070', '1356170', null, [self::parcel('odl:70', '6599100070', '2026-11-08T10:00:00Z')]);
        $busy = null;
        HookedStatement::$before = ['INSERT INTO carrier_invoice (' => static function () use ($ch, &$busy): void {
            try {
                (new OrderSnapshots(self::$db, null, null, 1))->store($ch, '1070', ['v' => 1, 'version' => 2, 'order_no' => '1356170', 'status' => 'completed',
                    'placed_at' => '2026-11-01T09:00:00Z', 'paid_at' => '2026-11-08T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
                    'lines' => [['line_id' => '8001070', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                        'refund_inc' => 0, 'free' => false]], 'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 499, 'refund_inc' => 0],
                    'order_refund_inc' => 0, 'grand_inc' => 1699, 'parcels' => [self::parcel('odl:70', '6599100070', '2026-11-08T10:00:00Z')]], 'api');
            } catch (CwException $e) {
                $busy = $e->errorCode;
            }
        }];
        try {
            $r = self::hookedBills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100070/0', 420)]), $ch);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame('busy', $busy, 'the snapshot waited for the bill\'s hold on the order and gave up');
        self::assertSame(['matched'], self::states($r['invoice_id']));
        self::assertSame([], self::days($ch), 'not paid when the bill was written: nothing on a day');
        self::assertDaysRebuild($ch);
        // The snapshot again (the minute job works out the one waiting): the order is paid and its delivery cost comes along.
        (new OrderSnapshots(self::$db))->applyQueued();
        self::assertSame(['2026-11-08|delivery_cost' => 420], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r1, MEDIUM 5: a bill stored is said stored even when the re-match of the other bills after it cannot run (an order of theirs
     * held elsewhere): the nightly run does it.
     */
    public function testABillStoredIsSaidStoredWhenTheReMatchAfterItCannotRun(): void
    {
        $ch = $this->shop();
        $first = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100080/0', 420)]), $ch);
        self::assertSame(['not_matched'], self::states($first['invoice_id']));
        self::order($ch, '1080', '1356180', null, [self::parcel('odl:80', '6599100080', '2026-11-08T10:00:00Z')]);
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 5)', [OrderSnapshots::lockName($ch, '1080')]));
        try {
            $r = (new DeliveryBills(self::$db, null, null, 1))->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100081/0', 420)],
                ['invoice' => '62999009']), $ch);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($ch, '1080')]);
        }
        self::assertSame(['stored', null], [$r['result'], $r['rematched']]);
        self::assertStringContainsString('try again', (string) $r['rematch_error']);
        self::assertSame('live', (string) self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        self::assertSame(1, self::bills()->rematch(self::system()), 'the nightly run puts it on its parcel');
        self::assertSame(['matched'], self::states($first['invoice_id']));
        // A store is no server job: it never re-matches (Codex r1 LOW 1).
        try {
            self::bills()->rematch(Caller::channel($ch, 'vapeandgo'));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('staff_required', $e->errorCode);
        }
        self::assertDaysRebuild($ch);
    }

    /** A re-send's parcels, and its re-send's (PR143), are on the first order's page; each counts on its own day. */
    public function testTheFirstOrdersPageShowsItsReSendsDeliveryCostDownTheChain(): void
    {
        $ch = $this->shop();
        self::order($ch, '1090', '1356190', '2026-11-04T10:00:00Z', [self::parcel('odl:90', '6599100090', '2026-11-04T12:00:00Z')]);
        self::order($ch, '1091', '1356191', '2026-11-05T10:00:00Z', [self::parcel('odl:91', '6599100091', '2026-11-05T12:00:00Z')], ['parent_ref' => '1090']);
        self::order($ch, '1092', '1356192', '2026-11-06T10:00:00Z', [self::parcel('odl:92', '6599100092', '2026-11-06T12:00:00Z')], ['parent_ref' => '1091']);
        self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('05/11/2026', '6599100090/0', 420), DpdCsv::line('06/11/2026', '6599100091/0', 310),
            DpdCsv::line('07/11/2026', '6599100092/0', 290)]), $ch);
        $first = (new ProfitViews(self::$db))->order($ch, '1090');
        self::assertNotNull($first);
        self::assertSame([420, 1, 600, 2, 0], [$first['delivery']['cost'], $first['delivery']['lines'], $first['delivery']['resend_cost'], $first['delivery']['resend_lines'],
            $first['delivery']['not_billed']]);
        $middle = (array) (new ProfitViews(self::$db))->order($ch, '1091');
        self::assertSame([310, 0], [$middle['delivery']['cost'], $middle['delivery']['resend_cost']], 'a re-send holds no re-sends\' cost (PR143)');
        self::assertSame(['2026-11-04|delivery_cost' => 420, '2026-11-05|delivery_cost' => 310, '2026-11-06|delivery_cost' => 290], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r2, MEDIUM 1: the account the lead confirmed (DPD 3012002 = Vape and Go) is that shop's from its first bill, whatever its
     * lines match (DPD's 15-24 Oct bill has none on a CW parcel); a choice of another shop is refused.
     */
    public function testTheConfirmedAccountIsVapeAndGosFromItsFirstBill(): void
    {
        $vpg = $this->shop();
        $ef = $this->shop('electrofag');
        $file = DpdCsv::file([DpdCsv::line('20/10/2026', '6599100800/0', 420)], ['invoice' => '62328011']);
        try {
            self::bills()->upload(self::system(), 'dpd', $file, $ef);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop', $e->errorCode);
        }
        $r = self::bills()->upload(self::system(), 'dpd', $file);
        self::assertSame((string) $vpg, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        self::assertSame(['before_start'], self::states($r['invoice_id']));
        self::assertDaysRebuild($vpg);
    }

    /** The Codex check r2, MEDIUM 4: the very file again changes nothing at all, not even the other bills' lines (the nightly run re-matches). */
    public function testTheSameFileAgainChangesNothingAtAll(): void
    {
        $ch = $this->shop();
        $file = DpdCsv::file([DpdCsv::line('09/11/2026', '6599100810/0', 420)]);
        $r = self::bills()->upload(self::system(), 'dpd', $file);
        self::order($ch, '1081', '1356181', '2026-11-08T09:00:00Z', [self::parcel('odl:81', '6599100810', '2026-11-08T10:00:00Z')]);
        self::assertSame(['same', 0], [($again = self::bills()->upload(self::system(), 'dpd', $file))['result'], $again['rematched']]);
        self::assertSame(['not_matched'], self::states($r['invoice_id']), 'not tried again by the same file');
        self::assertSame(1, self::bills()->rematch(self::system()));
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r2, MEDIUM 2: while a bill is written, EVERY order a line is on is held by its snapshot guard (a paid one too), so no
     * snapshot of it, or of a re-send of it, can hold its rows meanwhile: such a snapshot waits, and here gives up (busy).
     */
    public function testASnapshotOfAPaidOrderOfTheBillWaitsForIt(): void
    {
        $ch = $this->shop();
        self::order($ch, '1082', '1356182', '2026-11-08T09:00:00Z', [self::parcel('odl:82', '6599100820', '2026-11-08T10:00:00Z')]);
        $busy = null;
        HookedStatement::$before = ['INSERT INTO carrier_invoice (' => static function () use ($ch, &$busy): void {
            try {
                (new OrderSnapshots(self::$db, null, null, 1))->store($ch, '1083', ['v' => 1, 'version' => 1, 'order_no' => '1356183', 'status' => 'completed',
                    'placed_at' => '2026-11-01T09:00:00Z', 'paid_at' => '2026-11-09T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => '1082',
                    'lines' => [['line_id' => '8001083', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 0, 'discount_inc' => 0, 'cancelled' => false,
                        'refund_inc' => 0, 'free' => true]], 'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 0, 'refund_inc' => 0],
                    'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []], 'api');
            } catch (CwException $e) {
                $busy = $e->errorCode;
            }
        }];
        try {
            $r = self::hookedBills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100820/0', 420)]));
        } finally {
            HookedStatement::$before = [];
        }
        self::assertSame('busy', $busy, 'a re-send of the bill\'s order waited for the bill and gave up');
        self::assertSame(['matched'], self::states($r['invoice_id']));
        (new OrderSnapshots(self::$db))->applyQueued();
        self::assertSame('1082', (string) self::$db->value("SELECT parent_ref FROM sale_order WHERE channel_id = ? AND order_ref = '1083'", [$ch]));
        self::assertSame(['2026-11-08|delivery_cost' => 420], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /**
     * The Codex check r2, HIGH 3 (DL22): after an undo to the release before, its nightly rebuild leaves the delivery figures at 0 (and a paid day
     * moved under it leaves them on the old day). The rebuild of every day since the shop's start brings them back exactly, and a bill undone
     * after it leaves no day below 0.
     */
    public function testARebuildSinceTheStartBringsTheDeliveryFiguresBackAfterAnUndoToTheReleaseBefore(): void
    {
        [$ch, $bytes] = $this->week();
        $r = self::bills()->upload(self::system(), 'dpd', $bytes);
        $kept = self::days($ch);
        // The release before: its rebuild wrote the days without the two figures; one of its snapshots moved order 1000 from 4 to 6 Nov.
        self::$db->exec('UPDATE profit_day SET delivery_cost = 0, delivery_other = 0 WHERE channel_id = ?', [$ch]);
        self::$db->exec("UPDATE sale_order SET paid_at = '2026-11-06 09:00:00.000000', day = '2026-11-06' WHERE channel_id = ? AND order_ref = '1000'", [$ch]);
        self::$db->exec("UPDATE profit_day SET orders = orders - 1, units = units - 1, sale_ex = sale_ex - 1000, delivery_ex = delivery_ex - 416 WHERE channel_id = ? AND day = '2026-11-04'",
            [$ch]);
        self::$db->exec("UPDATE profit_day SET orders = orders + 1, units = units + 1, sale_ex = sale_ex + 1000, delivery_ex = delivery_ex + 416 WHERE channel_id = ? AND day = '2026-11-06'",
            [$ch]);
        $run = self::script('rebuild_profit_days', '--from=2026-10-28', '--to=2026-11-23', '--apply');
        self::assertSame(0, $run['code'], $run['out']);
        self::assertDaysRebuild($ch);
        self::assertSame(['2026-11-03|delivery_cost' => 1550, '2026-11-05|delivery_cost' => 897, '2026-11-06|delivery_cost' => 190, '2026-11-07|delivery_other' => 420,
            '2026-11-08|delivery_other' => 1589], self::days($ch), 'order 1000\'s 190 on its new day');
        self::assertNotSame($kept, self::days($ch));
        self::bills()->undo(self::system(), $r['invoice_id']);
        self::assertSame([], self::days($ch));
        self::assertSame('0', (string) self::$db->value('SELECT COUNT(*) FROM profit_day WHERE channel_id = ? AND (delivery_cost < 0 OR delivery_other < 0)', [$ch]));
        self::assertDaysRebuild($ch);
    }

    /** The Codex check r3: a bill on hundreds of orders takes their snapshot guards a statement at a time (GUARD_CHUNK), all of them, and lets them go. */
    public function testABillOnHundredsOfOrdersTakesTheirGuardsInChunksAndLetsThemGo(): void
    {
        $ch = $this->shop();
        $n = DeliveryBills::GUARD_CHUNK + 50;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $ref = (string) (3000 + $i);
            self::order($ch, $ref, (string) (1357000 + $i), '2026-11-08T09:00:00Z', [self::parcel('odl:' . $ref, (string) (6599300000 + $i), '2026-11-08T10:00:00Z')]);
            $lines[] = DpdCsv::line('09/11/2026', (6599300000 + $i) . '/0', 420);
        }
        $t = microtime(true);
        $r = self::bills()->upload(self::system(), 'dpd', DpdCsv::file($lines));
        self::assertLessThan(60, microtime(true) - $t);
        self::assertSame(array_fill(0, $n, 'matched'), self::states($r['invoice_id']));
        self::assertSame(['2026-11-08|delivery_cost' => 420 * $n], self::days($ch));
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '3000')]), 'every guard let go');
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, (string) (3000 + $n - 1))]));
        self::assertDaysRebuild($ch);
    }

    /**
     * The owner's rule (23 Nov 2026, "Yes, before CW"; DL30): a line on no parcel whose Senders Ref is an order number BELOW the shop's first
     * order known to CW is before CW start whatever its date; the first order is CW's own (the lowest order number it holds for the shop), so
     * it follows what CW holds (the nightly re-match); a line with no Senders Ref, a text one, or one at or above the first order stays not
     * matched; replace and undo keep it; the days equal a rebuild.
     */
    public function testALineOfAnOrderBeforeTheShopsFirstOrderInCwIsBeforeCwStart(): void
    {
        $ch = $this->shop();
        $lines = [
            DpdCsv::line('30/10/2026', '6599400001/0', 420, ['Senders Ref' => '1356050']),          // an order before CW's first: before CW start
            DpdCsv::line('30/10/2026', '6599400002/0', 310, ['Senders Ref' => '1356150']),          // at or above the first: not matched
            DpdCsv::line('30/10/2026', '6599400003/0', 250, ['Senders Ref' => '']),                 // no Senders Ref: not matched
            DpdCsv::line('30/10/2026', '6599400004/0', 230, ['Senders Ref' => 'man return']),       // text: not matched
            DpdCsv::line('05/11/2026', '6599400005/0', 1550, ['Senders Ref' => '1356040',           // a return of an order before CW's first
                'money' => ['Third Party Collection' => 1300, 'Fuel and Energy Charge' => 250]]),
            DpdCsv::line('30/10/2026', '6599400006/0', 200, ['Senders Ref' => '1356100']),          // exactly the first: not matched
        ];
        // No order in CW yet: no first order known, today's rules.
        $r = self::bills()->upload(self::system(), 'dpd', DpdCsv::file($lines, ['invoice' => '62341597']));
        self::assertSame(['not_matched', 'not_matched', 'not_matched', 'not_matched', 'not_matched', 'not_matched'], self::states($r['invoice_id']));
        // CW holds the shop's orders from 1356100 (its first; a re-send's higher number does not move it): the nightly re-match puts the two
        // lines of older orders before CW start.
        self::order($ch, '1100', '1356100', '2026-10-29T09:00:00Z', []);
        self::order($ch, '1101', '1356200', '2026-10-30T09:00:00Z', []);
        self::assertSame(2, self::bills()->rematch(self::system()));
        self::assertSame(['before_start', 'not_matched', 'not_matched', 'not_matched', 'before_start', 'not_matched'], self::states($r['invoice_id']));
        self::assertSame(['2026-10-30|delivery_other' => 310 + 250 + 230 + 200], self::days($ch), 'before CW start is not in the profit');
        self::assertDaysRebuild($ch);
        // A new upload decides it at once; a corrected file keeps it; undo takes all out.
        $lines[1] = DpdCsv::line('30/10/2026', '6599400002/0', 300, ['Senders Ref' => '1356150']);
        $new = self::bills()->replace(self::system(), $r['invoice_id'], DpdCsv::file($lines, ['invoice' => '62341597']));
        self::assertSame(['before_start', 'not_matched', 'not_matched', 'not_matched', 'before_start', 'not_matched'], self::states($new['invoice_id']));
        self::assertSame(['2026-10-30|delivery_other' => 300 + 250 + 230 + 200], self::days($ch));
        self::assertDaysRebuild($ch);
        self::bills()->undo(self::system(), $new['invoice_id']);
        self::assertSame([], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /**
     * The final review (astra item 1; DL31): the upload is the Delivery bills job's whatever a stored row of the map says (the owner's
     * decision): a Warehouse person given it on the roles table is refused; Finance still looks.
     */
    public function testTheUploadStaysTheDeliveryBillsJobsWhateverTheStoredMapSays(): void
    {
        [, $bytes] = $this->week();
        $saved = (string) self::$db->value("SELECT roles FROM role_permission WHERE permission = 'delivery.upload'");
        self::$db->exec("UPDATE role_permission SET roles = 'warehouse,delivery_bills' WHERE permission = 'delivery.upload'");
        try {
            $id = self::$db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('wh', 'wh@test.example', 'Wh', 'x')");
            self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'warehouse')", [$id]);
            try {
                self::bills()->upload(Caller::staff($id), 'dpd', $bytes);
                self::fail('refused');
            } catch (CwException $e) {
                self::assertSame('role_not_allowed', $e->errorCode);
            }
        } finally {
            self::$db->exec("UPDATE role_permission SET roles = ? WHERE permission = 'delivery.upload'", [$saved]);
            \CW\Auth\RoleMap::load(self::$db);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
    }

    /**
     * The final review (astra item 2, Fable L1; DL32): a line before CW start only by its Senders Ref is tried again (nightly, after each
     * upload): its order, or its parcel, reaching CW later puts it on the order, and a lower first order in CW makes it not matched; the money
     * follows on the days. A Senders Ref of another length than the shop's order numbers is never before CW start by its number.
     */
    public function testALineBeforeCwStartByItsSendersRefIsTriedAgain(): void
    {
        $ch = $this->shop();
        self::order($ch, '1100', '1356100', '2026-10-29T09:00:00Z', []);
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([
            DpdCsv::line('05/11/2026', '6599500001/0', 1550, ['Senders Ref' => '1356050', 'money' => ['Third Party Collection' => 1550]]), // a return of an older order
            DpdCsv::line('05/11/2026', '6599500002/0', 420, ['Senders Ref' => '1356060']),                                                // a parcel CW does not hold yet
            DpdCsv::line('05/11/2026', '6599500003/0', 300, ['Senders Ref' => '1356070']),                                                // an older order
            DpdCsv::line('05/11/2026', '6599500004/0', 200, ['Senders Ref' => '13560']),                                                  // not an order number's length
        ]));
        self::assertSame(['before_start', 'before_start', 'before_start', 'not_matched'], self::states($bill['invoice_id']));
        // A backfill brings orders 1356050 (paid 30 Oct) and 1356060 with its parcel, and a lower order 1356000.
        self::order($ch, '1050', '1356050', '2026-10-30T09:00:00Z', []);
        self::order($ch, '1060', '1356060', '2026-11-04T09:00:00Z', [self::parcel('odl:1060', '6599500002', '2026-11-04T10:00:00Z')]);
        self::order($ch, '1000', '1356000', '2026-10-29T08:00:00Z', []);
        self::assertSame(3, self::bills()->rematch(self::system()));
        self::assertSame(['collection', 'matched', 'not_matched', 'not_matched'], self::states($bill['invoice_id']));
        self::assertSame(['2026-10-30|delivery_cost' => 1550, '2026-11-04|delivery_cost' => 420, '2026-11-05|delivery_other' => 500], self::days($ch));
        self::assertDaysRebuild($ch);
    }

    /** The final review (astra item 6; DL35): a zero-padded Senders Ref finds its order (collection) and is compared as a number. */
    public function testAZeroPaddedSendersRefFindsItsOrder(): void
    {
        $ch = $this->shop();
        self::order($ch, '1100', '1356100', '2026-11-03T09:00:00Z', []);
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([
            DpdCsv::line('05/11/2026', '6599500011/0', 1550, ['Senders Ref' => '001356100', 'money' => ['Third Party Collection' => 1550]]),
            DpdCsv::line('05/11/2026', '6599500012/0', 420, ['Senders Ref' => '01356050']),
        ]));
        self::assertSame(['collection', 'before_start'], self::states($bill['invoice_id']));
        self::assertSame('001356100', (string) self::$db->value('SELECT senders_ref FROM carrier_invoice_line WHERE invoice_id = ? AND line_no = 1', [$bill['invoice_id']]),
            'kept as printed');
        self::assertDaysRebuild($ch);
    }

    /** The final review (astra item 7; DL36): with two shops tied first, a shop chosen with fewer matches is refused; one of the tied is taken. */
    public function testATieIsBrokenOnlyByAShopWithTheMostMatches(): void
    {
        $vpg = $this->shop();
        $ef = $this->shop('electrofag');
        $vb = $this->shop('vapebig');
        $lines = [];
        foreach ([[$vpg, '1'], [$vpg, '2'], [$ef, '3'], [$ef, '4'], [$vb, '5']] as $k => [$ch, $n]) {
            self::order($ch, "70{$k}", "13570{$k}", '2026-11-08T09:00:00Z', [self::parcel("odl:70{$k}", "659960000{$n}", '2026-11-08T10:00:00Z')]);
            $lines[] = DpdCsv::line('09/11/2026', "659960000{$n}/0", 420, ['Senders Ref' => "13570{$k}"]);
        }
        $file = DpdCsv::file($lines, ['account' => '4000009', 'invoice' => '1']);
        try {
            self::bills()->upload(self::system(), 'dpd', $file, $vb);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('account_shop', $e->errorCode);
        }
        $r = self::bills()->upload(self::system(), 'dpd', $file, $ef);
        self::assertSame((string) $ef, (string) self::$db->value('SELECT channel_id FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        foreach ([$vpg, $ef, $vb] as $ch) {
            self::assertDaysRebuild($ch);
        }
    }

    /** The final review (Fable M1; DL38): only a DPD parcel can be "not billed yet"; a Royal Mail or manual one never is (their bills are not read). */
    public function testOnlyADpdParcelIsNotBilledYet(): void
    {
        $ch = $this->shop();
        self::order($ch, '1200', '1356200', '2026-11-08T09:00:00Z', [self::parcel('odl:1200', '6599700001', '2026-11-08T10:00:00Z'),
            self::parcel('odl:1201', 'AB123456785GB', '2026-11-08T10:00:00Z', ['carrier' => 'royal_mail']),
            self::parcel('odl:1202', null, '2026-11-08T10:00:00Z', ['carrier' => 'manual', 'manual' => true])]);
        $o = (array) (new ProfitViews(self::$db))->order($ch, '1200');
        self::assertSame(1, $o['delivery']['not_billed'], 'the DPD parcel only');
        self::assertSame([true, false, false], array_map(static fn (array $p): bool => $p['not_billed'], $o['delivery']['parcels']));
    }

    /**
     * The final review (Fable L3; DL37): a database wait that runs out while a bill is written (a day row held elsewhere) is tried again and then
     * said "busy, try again" (503), never an error page; nothing is written.
     */
    public function testALockWaitThatRunsOutIsSaidBusy(): void
    {
        [$ch, $bytes] = $this->week();
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT * FROM profit_day WHERE channel_id = ? AND day = '2026-11-04' FOR UPDATE", [$ch]);
        $conn = TestDb::connect();
        $conn->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $t = microtime(true);
        try {
            (new DeliveryBills($conn, null, null, 1))->upload(self::system(), 'dpd', $bytes);
            self::fail('busy');
        } catch (CwException $e) {
            self::assertSame(['busy', 503], [$e->errorCode, $e->httpStatus]);
        } finally {
            $other->pdo()->rollBack();
        }
        self::assertLessThan(30, microtime(true) - $t);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
    }

    /**
     * The astra re-check of a33d184 (item 8; DL41): one deadline for the whole guarded write, row waits included. A day row held elsewhere is
     * never waited for while the bill holds order guards longer than a snapshot waits for its guard (OrderSnapshots::QUEUE_LOCK_WAIT_SEC): the
     * bill lets every guard go at once and tries again within GUARD_BUDGET_SEC, then busy; the snapshot writer waiting meanwhile gets its guard
     * within its own wait; nothing is written.
     */
    public function testABlockedDayRowNeverKeepsASnapshotFromItsGuard(): void
    {
        [$ch, $bytes] = $this->week();
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT * FROM profit_day WHERE channel_id = ? AND day = '2026-11-04' FOR UPDATE", [$ch]);
        $name = OrderSnapshots::lockName($ch, '1000');
        $worker = null;
        $pipes = [];
        // Once the bill holds its guards (the statement after it took them), the snapshot writer of one of its orders starts waiting for its guard.
        HookedStatement::$before = ['SELECT GET_LOCK(?, 0) AS g0' => static function () use ($name, &$worker, &$pipes): void {
            HookedStatement::$before[''] = static function () use ($name, &$worker, &$pipes): void {
                $worker = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/snapshot_guard_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
                fwrite($pipes[0], json_encode(['name' => $name, 'wait' => OrderSnapshots::QUEUE_LOCK_WAIT_SEC, 'hold_ms' => 50], JSON_THROW_ON_ERROR) . "\n");
                fclose($pipes[0]);
                fgets($pipes[1]); // "waiting": it is about to ask for the guard
                usleep(200_000);
            };
        }];
        $bills = self::hookedBills();
        $t = microtime(true);
        try {
            $bills->upload(self::system(), 'dpd', $bytes);
            self::fail('busy');
        } catch (CwException $e) {
            self::assertSame(['busy', 503], [$e->errorCode, $e->httpStatus]);
        } finally {
            HookedStatement::$before = [];
            $other->pdo()->rollBack();
        }
        $took = microtime(true) - $t;
        self::assertIsResource($worker, 'the bill held its guards');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($worker), $err);
        $w = json_decode(trim($out), true);
        self::assertIsArray($w, $out . $err);
        self::assertTrue($w['seen'], 'the bill held the guard when the snapshot writer asked');
        self::assertSame(1, $w['got'], 'the snapshot writer gets its guard within its own wait');
        self::assertLessThan(OrderSnapshots::QUEUE_LOCK_WAIT_SEC * 1000, $w['waited_ms']);
        self::assertLessThan(DeliveryBills::GUARD_BUDGET_SEC + 2.5, $took, 'busy within the budget');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice_line'));
    }

    /**
     * The final review (astra item 8; DL37): a bill write never waits holding order guards: when one is held elsewhere it lets every guard go at
     * once and tries again, so the whole wait is bounded (GUARD_BUDGET_SEC), then busy; at a week's volume (2,500 orders) it is written whole.
     */
    public function testAWeeksBillTakesItsGuardsWithoutWaitingWhileHoldingThem(): void
    {
        $ch = $this->shop();
        $n = 2500;
        $lines = [];
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [$ch, (string) (500000 + $i), (string) (1400000 + $i)];
            $lines[] = DpdCsv::line('09/11/2026', (6598000000 + $i) . '/0', 420, ['Senders Ref' => (string) (1400000 + $i)]);
        }
        // The orders and their parcels as the money job leaves them (written straight in: 2,500 snapshots would take minutes).
        foreach (array_chunk($rows, 500) as $chunk) {
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, order_no, paid_at, day, vat_bp, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), "(?, ?, ?, '2026-11-08 09:00:00', '2026-11-08', 2000, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")),
                array_merge(...array_map(static fn (array $r): array => $r, $chunk)));
            self::$db->exec('INSERT INTO sale_parcel (channel_id, order_ref, parcel_ref, tracking_no, carrier, label_at, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), "(?, ?, ?, ?, 'dpd', '2026-11-08 10:00:00', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")),
                array_merge(...array_map(static fn (array $r): array => [$r[0], $r[1], 'odl:' . $r[1], (string) (6598000000 + (int) $r[1] - 500000)], $chunk)));
        }
        $file = DpdCsv::file($lines);
        // One order's guard held elsewhere: the bill gives up within its budget, holding nothing meanwhile.
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '501250')]));
        $t = microtime(true);
        try {
            self::bills()->upload(self::system(), 'dpd', $file);
            self::fail('busy');
        } catch (CwException $e) {
            self::assertSame('busy', $e->errorCode);
        }
        self::assertLessThan(DeliveryBills::GUARD_BUDGET_SEC + 20, microtime(true) - $t);
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '500000')]), 'nothing left held');
        $other->value('SELECT RELEASE_ALL_LOCKS()');
        // Free: written whole.
        $t = microtime(true);
        $r = self::bills()->upload(self::system(), 'dpd', $file);
        self::assertLessThan(120, microtime(true) - $t);
        self::assertSame($n, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice_line WHERE invoice_id = ? AND match_state = 'matched'", [$r['invoice_id']]));
        self::assertSame(['2026-11-08|delivery_cost' => 420 * $n], self::days($ch));
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '502499')]), 'every guard let go');
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
}
