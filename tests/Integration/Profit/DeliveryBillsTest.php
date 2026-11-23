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
        self::order($ch, '1040', '1356110', '2026-11-01T09:00:00Z', [self::parcel('odl:40', '6599195080', '2026-11-01T10:00:00Z'),
            self::parcel('odl:41', '6599195081', '2026-11-01T10:00:00Z'), self::parcel('odl:42', '6599195082', '2026-11-10T10:00:00Z'),
            self::parcel('odl:43', '6599195083', '2026-11-11T10:00:00Z'), self::parcel('odl:44', '15976599195084', '2026-11-05T10:00:00Z')]);
        $bill = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([
            DpdCsv::line('11/11/2026', '6599195080/0', 100), // 10 days after the label: matched
            DpdCsv::line('12/11/2026', '6599195081/0', 100), // 11 days: not
            DpdCsv::line('07/11/2026', '6599195082/0', 100), // 3 days before: matched
            DpdCsv::line('07/11/2026', '6599195083/0', 100), // 4 days before: not
            // The consignment only (the lead's decision 4; Codex r1 MEDIUM 3): DPD's parcel number never matches, nor makes a line ambiguous.
            DpdCsv::line('06/11/2026', '6599195099/0', 100, ['Parcel No' => '15976599195084']),
            DpdCsv::line('06/11/2026', '6599195080/0', 100, ['Parcel No' => '15976599195084']),
        ]));
        self::assertSame(['matched', 'not_matched', 'matched', 'not_matched', 'not_matched', 'matched'], self::states($bill['invoice_id']));
        self::assertSame('odl:40', (string) self::$db->value('SELECT parcel_ref FROM carrier_invoice_line WHERE invoice_id = ? AND line_no = 6', [$bill['invoice_id']]));
        // A parcel of another carrier, or a manual one, with the same number is never DPD's.
        self::order($ch, '1041', '1356111', '2026-11-01T09:00:00Z', [self::parcel('odl:45', '6599195085', '2026-11-05T10:00:00Z', ['carrier' => 'royal_mail'])]);
        $rm = self::bills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('06/11/2026', '6599195085/0', 100)], ['invoice' => '62999002']));
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
            . "'undone', 'system:x', UTC_TIMESTAMP(6), 'system:x', UTC_TIMESTAMP(6))", [$ef, str_repeat('a', 64)]);
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
        self::$db->exec('DELETE FROM carrier_invoice_line WHERE invoice_id = ?', [$w['invoice_id']]); // the nightly DB5 would name the two shops of 4000007
        self::$db->exec('DELETE FROM carrier_invoice WHERE id = ?', [$w['invoice_id']]);
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
     * The Codex check r1, HIGH 3: the parcel a line was put on is removed by its order's snapshot while the bill waits for the days it counts on:
     * the decisions are taken again once the orders are held, the plan is old, the bill is tried again and the line is not matched.
     */
    public function testAParcelRemovedWhileTheBillWaitsIsNotMatched(): void
    {
        $ch = $this->shop();
        self::order($ch, '1060', '1356160', '2026-11-08T09:00:00Z', [self::parcel('odl:60', '6599100060', '2026-11-08T10:00:00Z')]);
        HookedStatement::$before = ['INSERT INTO profit_day (channel_id, day) VALUES' => static function () use ($ch): void {
            self::order($ch, '1060', '1356160', '2026-11-08T09:00:00Z', [], ['version' => 2]);
        }];
        try {
            $r = self::hookedBills()->upload(self::system(), 'dpd', DpdCsv::file([DpdCsv::line('09/11/2026', '6599100060/0', 420)]), $ch);
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
