<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Invariants;
use CW\Profit\DeliveryBills;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Profit\ClickAndDropClient;
use CW\Profit\HttpGet;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use CW\Tests\Support\XlsWriter;

/**
 * Royal Mail's bills in CW (delivery cost phase 1; docs/decisions.md DL43-DL70), from SYNTHETIC bills and labels: a week tied to the penny
 * with every label where it belongs (Vape and Go's and Electrofag's orders, a shop's "not on an order", Hayati Vapes on its own line, held:
 * shop not known or a conflict), the day book across bills (a later bill's tail takes back from the day's line; undo / replace plan the days
 * again), a late label and a late CW parcel, the profit days equal to a rebuild from scratch and the allocations to a plan from scratch after
 * every write; only the Delivery bills job writes; no personal data kept.
 */
final class RoyalMailBillsTest extends StockTestCase
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
        $this->acct = RmFixtures::account(self::$db, $this->vpg, $this->ef);
    }

    private static function system(): Caller
    {
        return Caller::system('rm_test');
    }

    private static function rm(): RoyalMailBills
    {
        return new RoyalMailBills(self::$db);
    }

    /** An order with one goods line and its Royal Mail parcels (tracking => label time), stored and worked out now. */
    private static function order(int $ch, string $ref, string $no, ?string $paid, array $parcels): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => $label) {
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => $tracking, 'carrier' => 'royal_mail', 'service_code' => '', 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => false, 'partial' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed', 'placed_at' => '2026-11-01T09:00:00Z',
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => (string) (8000000 + (int) $ref), 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false,
                'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'Royal Mail', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1599,
            'parcels' => $p], 'api');
    }

    /** @return array<string, int> "day|field" => pence: the delivery figures kept on a shop's days */
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

    /** Every day of both shops is what a rebuild from scratch works out, and the allocations are a plan from scratch. */
    private function assertRebuilds(): void
    {
        foreach ([$this->vpg, $this->ef] as $ch) {
            for ($d = '2026-10-20'; $d <= '2026-11-30'; $d = (new \DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d')) {
                $diff = array_values(array_filter(ProfitDays::differences(self::$db, $ch, $d), static fn (string $l): bool => !str_contains($l, 'row of zeros')));
                self::assertSame([], $diff, "shop {$ch} day {$d}: the kept figures are what a rebuild works out");
            }
        }
        self::assertSame([], RoyalMailBills::drift(self::$db, $this->acct['account']), 'the allocations are a plan from scratch');
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

    /** @return list<array<string, mixed>> a week of every kind of line (bill 9070000001) */
    private function week(): array
    {
        $day = '2026-11-09';
        $at = $day . 'T10:00:00Z';
        self::order($this->vpg, '1001', '1360001', '2026-11-09T09:00:00Z', ['JV000000001GB' => $at]);
        self::order($this->vpg, '1002', '1360002', '2026-11-08T21:00:00Z', ['JV000000002GB' => $at, 'JV000000009GB' => $at]);
        self::order($this->vpg, '1003', '1360003', '2026-11-09T09:00:00Z', ['AS000000003GB' => $at]);
        self::order($this->vpg, '1004', '1360004', '2026-11-09T09:00:00Z', ['AS000000004GB' => $at]);
        self::order($this->ef, '2001', '2833', '2026-11-09T09:00:00Z', ['JV000000005GB' => $at]);
        $sd = ['service_code' => 'SD1', 'manifest_no' => 2288, 'sales_order' => '5155514809'];
        $r = self::rm()->importLabels(self::system(), $this->acct['account'], [
            RmFixtures::label('JV000000001GB', 'TPL48HV', $day, '1360001'),
            RmFixtures::label('JV000000002GB', 'TPL48HV', $day, '1360002'),
            RmFixtures::label('JV000000005GB', 'TPL48HV', $day, '2833'),
            RmFixtures::label('JV000000006GB', 'TPL48HV', $day, '#2833', ['channel_code' => 'woocommerce']),
            RmFixtures::label('JV000000007GB', 'TPL48HV', $day, '2900', ['channel_code' => 'manual']),
            RmFixtures::label('JV000000008GB', 'TPL48HV', $day, '1360099'),
            RmFixtures::label('JV000000009GB', 'TPL48HV', $day, '1360001'),
            RmFixtures::label('AS000000003GB', 'SD1', $day, '1360003', ['weight_g' => 80] + $sd),
            RmFixtures::label('AS000000004GB', 'SD1', $day, '1360004', ['weight_g' => 300] + $sd),
        ], 'report');
        self::assertSame([9, 9, 0, 0], [$r['labels'], $r['added'], $r['filled'], $r['replanned']], 'no bill yet: nothing planned');
        return [
            RmBill::line('2026-11-09', 'TPL', 6, 1526, '943'),
            RmBill::line('2026-11-09', 'SD1', 1, 1004, 'CLICK AND DROP M2288', '5155514809'),
            RmBill::line('2026-11-09', 'SD1', 1, 1062, 'CLICK AND DROP M2288', '5155514809'),
            RmBill::line('2026-11-10', 'RX1', 1, 275, 'ADJ5155514809RCN Surcharge'),
            RmBill::line('2026-11-10', 'RXA', 1, 125, 'ADJ5155514809RCN Surcharge'),
            RmBill::line('2026-11-08', 'RW4', 2, 210, 'Rectification Week 32'),
            RmBill::line('2026-11-04', 'TSS', 1, 254, ''),
        ];
    }

    public function testAWeekTiesToThePennyWithEveryLabelWhereItBelongs(): void
    {
        $lines = $this->week();
        $r = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => '9070000001']));
        self::assertSame(['stored', '9070000001'], [$r['result'], $r['invoice_no']]);
        $id = $r['invoice_id'];
        $inv = (array) self::$db->one('SELECT * FROM carrier_invoice WHERE id = ?', [$id]);
        self::assertSame(['royal_mail', '0706703000', '2026-11-16', '2026-11-04', '2026-11-10', (string) $this->vpg, '4456', '7', 'live'],
            [$inv['carrier'], $inv['account'], $inv['invoice_date'], $inv['period_from'], $inv['period_to'], (string) $inv['channel_id'], (string) $inv['net_pence'],
                (string) $inv['line_count'], $inv['status']]);
        // TPL: 1526p / 6 = 254p on each of the 7 labels; the day's line -252p (6 billed, 7 labels) split 3 / 1 / 1 / 1 / 1 (VPG takes the pence left)
        $want = [
            'held||ref_differs' => 254 - 36, 'held||shop_unknown' => 254 - 36,
            'order|' . $this->vpg . '|' => 254 + 254 + 1004 + 1062 + 137 * 2 + 62 * 2, 'order|' . $this->ef . '|' => 254,
            'party|' . $this->acct['hayati'] . '|' => 254 - 36,
            'shop|' . $this->ef . '|' => -36, 'shop|' . $this->vpg . '|' => 254 - 108 + 1 + 1 + 210 + 254,
        ];
        ksort($want);
        self::assertSame($want, self::where($id));
        self::assertSame(4456, array_sum(self::where($id)), 'every penny of the bill');
        self::assertSame([254, 254, 1004 + 137 + 62, 1062 + 137 + 62, 254], [DeliveryBills::orderCost(self::$db, $this->vpg, '1001'),
            DeliveryBills::orderCost(self::$db, $this->vpg, '1002'), DeliveryBills::orderCost(self::$db, $this->vpg, '1003'), DeliveryBills::orderCost(self::$db, $this->vpg, '1004'),
            DeliveryBills::orderCost(self::$db, $this->ef, '2001')]);
        self::assertSame(['2026-11-04|delivery_other' => 254, '2026-11-08|delivery_cost' => 254, '2026-11-08|delivery_other' => 210,
            '2026-11-09|delivery_cost' => 254 + 1203 + 1261, '2026-11-09|delivery_other' => 254 - 108, '2026-11-10|delivery_other' => 2], self::days($this->vpg),
            'Vape and Go: its orders on their paid days, its "not on an order" on the service days');
        self::assertSame(['2026-11-09|delivery_cost' => 254, '2026-11-09|delivery_other' => -36], self::days($this->ef), 'Electrofag\'s in Electrofag\'s profit');
        self::assertSame(218 + 436, 4456 - array_sum(self::days($this->vpg)) - array_sum(self::days($this->ef)), 'Hayati Vapes and held: in no shop\'s profit');
        // the Special Delivery labels by band (80g: the 1004p row; 300g: the 1062p one), the wrong weight shared over the sales order's two
        $sd = self::$db->all("SELECT l.tracking_no, a.kind, a.how, a.pence FROM carrier_allocation a JOIN carrier_label l ON l.id = a.label_id WHERE a.invoice_id = ? "
            . "AND l.service_code = 'SD1' ORDER BY l.tracking_no, a.row_no", [$id]);
        self::assertSame([['AS000000003GB', 'label', 'band', '1004'], ['AS000000003GB', 'wrong_weight', 'shared', '137'], ['AS000000003GB', 'wrong_weight', 'shared', '62'],
            ['AS000000004GB', 'label', 'band', '1062'], ['AS000000004GB', 'wrong_weight', 'shared', '137'], ['AS000000004GB', 'wrong_weight', 'shared', '62']],
            array_map(static fn (array $x): array => array_map('strval', array_values($x)), $sd));
        $this->assertRebuilds();
        // the very file again: nothing changes; another file of the live bill: refused
        $kept = json_encode([self::$db->all('SELECT * FROM carrier_allocation ORDER BY id'), self::$db->all('SELECT * FROM profit_day ORDER BY channel_id, day')]);
        self::assertSame('same', self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => '9070000001']))['result']);
        try {
            self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([...$lines, RmBill::line('2026-11-09', 'TPL', 1, 254, '943')], ['invoice' => '9070000001']));
            self::fail('another file of a live bill');
        } catch (CwException $e) {
            self::assertSame('already_uploaded', $e->errorCode);
        }
        self::assertSame($kept, json_encode([self::$db->all('SELECT * FROM carrier_allocation ORDER BY id'), self::$db->all('SELECT * FROM profit_day ORDER BY channel_id, day')]));
        // DPD's undo never takes a Royal Mail bill (it has no lines there: its allocations would stay)
        try {
            (new DeliveryBills(self::$db))->undo(self::system(), $id);
            self::fail('a pooled bill is undone with its allocations');
        } catch (CwException $e) {
            self::assertSame('other_carrier', $e->errorCode);
        }
    }

    public function testALaterBillsTailTakesBackFromTheDaysLineAndUndoOrReplacePlansTheDayAgain(): void
    {
        $day = '2026-11-10';
        $labels = [];
        foreach ([1011, 1012, 1013, 1014, 1015] as $i => $ref) {
            $t = sprintf('JV0000010%02dGB', $i);
            self::order($this->vpg, (string) $ref, (string) (1360000 + $ref), '2026-11-10T09:00:00Z', [$t => '2026-11-10T10:00:00Z']);
            $labels[] = RmFixtures::label($t, 'TPL48HV', $day, (string) (1360000 + $ref), ['manifest_no' => 2290]);
        }
        self::rm()->importLabels(self::system(), $this->acct['account'], $labels, 'report');
        $a = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 3, 763, '9815')], ['invoice' => '9070000010',
            'date' => 'Nov 16, 2026']))['invoice_id'];
        $cost = static fn (): array => array_map(static fn (int $r): int => DeliveryBills::orderCost(self::$db, (int) self::$db->value('SELECT id FROM channel WHERE code = ?',
            ['vapeandgo']), (string) $r), [1011, 1012, 1013, 1014, 1015]);
        self::assertSame([254, 254, 254, 254, 254], $cost(), 'every label of the day the first bill\'s price at once (763p / 3 = 254p)');
        self::assertSame(['2026-11-10|delivery_cost' => 1270, '2026-11-10|delivery_other' => -507], self::days($this->vpg), '"2 labels not charged" (open day)');
        $this->assertRebuilds();
        // the next bill brings the tail of 10 Nov: the line takes it back, no order's amount moves
        $b = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 2, 509, '9815')], ['invoice' => '9070000011',
            'date' => 'Nov 23, 2026']))['invoice_id'];
        self::assertSame([254, 254, 254, 254, 254], $cost());
        self::assertSame(['2026-11-10|delivery_cost' => 1270, '2026-11-10|delivery_other' => 2], self::days($this->vpg));
        self::assertSame(['shop|' . $this->vpg . '|' => 509], self::where($b), 'the tail is the day\'s line');
        $this->assertRebuilds();
        // a corrected file of the later bill: replaced in one write
        $r = self::rm()->replace(self::system(), $b, RmBill::page([RmBill::line($day, 'TPL', 2, 508, '9815')], ['invoice' => '9070000011', 'date' => 'Nov 23, 2026']));
        self::assertSame(['superseded', 'live'], array_map('strval', self::$db->column('SELECT status FROM carrier_invoice WHERE id IN (?, ?) ORDER BY id', [$b, $r['invoice_id']])));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_allocation WHERE invoice_id = ? AND ended_at IS NULL', [$b]), 'the old one\'s allocations ended');
        self::assertSame(['2026-11-10|delivery_cost' => 1270, '2026-11-10|delivery_other' => 1], self::days($this->vpg));
        $this->assertRebuilds();
        // the first bill undone: the later one is the day's first now, its price on every label (508p / 2 = 254p), the line all of its rest
        self::rm()->undo(self::system(), $a);
        self::assertSame('undone', (string) self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$a]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_allocation WHERE invoice_id = ? AND ended_at IS NULL', [$a]));
        self::assertSame([254, 254, 254, 254, 254], $cost());
        self::assertSame(['2026-11-10|delivery_cost' => 1270, '2026-11-10|delivery_other' => -762], self::days($this->vpg));
        $this->assertRebuilds();
        self::rm()->undo(self::system(), $r['invoice_id']);
        self::assertSame([0, 0, 0, 0, 0], $cost());
        self::assertSame([], self::days($this->vpg));
        $this->assertRebuilds();
    }

    public function testALateLabelTakesTheDaysPriceAndALateCwParcelMovesItsLabelOntoTheOrder(): void
    {
        $day = '2026-11-09';
        self::order($this->vpg, '1021', '1360021', '2026-11-09T09:00:00Z', ['JV000000021GB' => '2026-11-09T10:00:00Z']);
        self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000021GB', 'TPL48HV', $day, '1360021'),
            RmFixtures::label('JV000000022GB', 'TPL48HV', $day, '1360022')], 'api');
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 2, 508, '943')]))['invoice_id'];
        self::assertSame(['order|' . $this->vpg . '|' => 254, 'shop|' . $this->vpg . '|' => 254], self::where($id), 'a label made on Click & Drop\'s own site: not on an order');
        // a label read after the bill (a late pull): its day's price at once, the day's line by the same amount
        $r = self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000023GB', 'TPL48HV', $day, '1360023')], 'api');
        self::assertSame([1, null], [$r['added'], $r['replan_error']]);
        self::assertGreaterThan(0, $r['replanned']);
        self::assertSame(['2026-11-09|delivery_cost' => 254, '2026-11-09|delivery_other' => 254 + 254 - 254], self::days($this->vpg), '"1 label not charged"');
        $this->assertRebuilds();
        // the order of a label reaches CW after the bill: the nightly moves its price onto it
        self::order($this->vpg, '1022', '1360022', '2026-11-09T11:00:00Z', ['JV000000022GB' => '2026-11-09T12:00:00Z']);
        self::assertGreaterThan(0, self::rm()->replan(self::system(), null, 3650));
        self::assertSame(254, DeliveryBills::orderCost(self::$db, $this->vpg, '1022'));
        self::assertSame(['2026-11-09|delivery_cost' => 508], self::days($this->vpg), 'its price moved from "not on an order" onto the order');
        $this->assertRebuilds();
        // its parcel taken off the order by a later snapshot: the price stays on the order it was put on (never moved to another)
        (new OrderSnapshots(self::$db))->store($this->vpg, '1022', ['v' => 1, 'version' => 2, 'order_no' => '1360022', 'status' => 'completed',
            'placed_at' => '2026-11-01T09:00:00Z', 'paid_at' => '2026-11-12T11:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8001022', 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]], 'delivery' => ['method_id' => '5', 'method' => 'Royal Mail', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1599, 'parcels' => []], 'api');
        self::rm()->replan(self::system(), null, 3650);
        self::assertSame(254, DeliveryBills::orderCost(self::$db, $this->vpg, '1022'));
        self::assertSame(254, self::days($this->vpg)['2026-11-12|delivery_cost'] ?? 0, 'the order paid on another day took its cost along (its snapshot)');
        $this->assertRebuilds();
    }

    public function testTheNightlyChecksFindAllocationsThatNoLongerAddUp(): void
    {
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($this->week(), ['invoice' => '9070000001']))['invoice_id'];
        self::assertSame([], Invariants::check(self::$db), 'a Royal Mail bill has rows, not DPD lines: DB3 is DPD\'s');
        $a = (int) self::$db->value("SELECT id FROM carrier_allocation WHERE invoice_id = ? AND kind = 'label' ORDER BY id LIMIT 1", [$id]);
        self::$db->exec('UPDATE carrier_allocation SET pence = pence + 1 WHERE id = ?', [$a]);
        self::assertContains("DB8 bill {$id} row 1: its live allocations add up to 1527p, the row's net is 1526p", Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_allocation SET pence = pence - 1 WHERE id = ?', [$a]);
        self::$db->exec('UPDATE carrier_bill_row SET net_pence = net_pence + 1 WHERE invoice_id = ? AND row_no = 7', [$id]);
        self::assertContains("DB7 bill {$id} (9070000001): its 7 rows add up to 4457p, it says 7 rows and 4456p net", Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_bill_row SET net_pence = net_pence - 1 WHERE invoice_id = ? AND row_no = 7', [$id]);
        self::$db->exec("UPDATE carrier_invoice SET status = 'undone', ended_actor = 'system:x', ended_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
        self::assertContains("DB9 bill {$id} (undone): 22 allocations still live", Invariants::check(self::$db));
        self::$db->exec("UPDATE carrier_invoice SET status = 'live', ended_actor = NULL, ended_at = NULL WHERE id = ?", [$id]);
        $other = RmFixtures::account(self::$db, $this->vpg, null, 'off', 'vapebig-pending')['account'];
        $l = (int) self::$db->value("SELECT label_id FROM carrier_allocation WHERE id = ?", [$a]);
        self::$db->exec('UPDATE carrier_label SET account_id = ? WHERE id = ?', [$other, $l]);
        self::assertContains("DB10 allocation {$a}: its label is of another carrier account than its bill", Invariants::check(self::$db));
        self::$db->exec('UPDATE carrier_label SET account_id = ? WHERE id = ?', [$this->acct['account'], $l]);
        // the database refuses the rest: two live prices of one label on one row, an allocation pointing at what its destination does not
        self::assertSame(1062, self::mysqlError(static fn () => self::$db->exec('INSERT INTO carrier_allocation (invoice_id, row_no, kind, how, label_id, dest, channel_id, '
            . "order_ref, parcel_ref, day, pence, made_at) SELECT invoice_id, row_no, kind, how, label_id, dest, channel_id, order_ref, parcel_ref, day, 0, made_at "
            . 'FROM carrier_allocation WHERE id = ?', [$a])));
        self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec("UPDATE carrier_allocation SET dest = 'party' WHERE id = ?", [$a])));
        self::assertSame([], Invariants::check(self::$db));
    }

    /**
     * A fake Click & Drop (no call leaves the machine): GET /orders answers by the day asked, GET /orders/{ids} with each order's tracking
     * number and manifest time; it records every URL; status 401 when told.
     *
     * @param array<string, list<array<string, mixed>>> $byDay day => its orders as /orders/{ids} gives them
     * @param list<string> $calls
     */
    private static function cnd(array $byDay, array &$calls, int $status = 200): ClickAndDropClient
    {
        $http = new class ($byDay, $calls, $status) implements HttpGet {
            /** @param array<string, list<array<string, mixed>>> $byDay @param list<string> $calls */
            public function __construct(private array $byDay, private array &$calls, private int $status)
            {
            }

            public function get(string $url, array $headers, int $timeoutSec): array
            {
                $this->calls[] = $url;
                if ($this->status !== 200) {
                    return ['status' => $this->status, 'body' => '{"message":"no"}', 'retry_after' => null];
                }
                $all = array_merge(...array_values($this->byDay));
                if (preg_match('#/orders\?startDateTime=(\d{4}-\d{2}-\d{2})#', $url, $m) === 1) {
                    $list = array_map(static fn (array $o): array => ['orderIdentifier' => $o['orderIdentifier'], 'orderReference' => $o['orderReference'],
                        'createdOn' => $o['createdOn']], $this->byDay[$m[1]] ?? []);
                    return ['status' => 200, 'body' => json_encode(['orders' => $list]), 'retry_after' => null];
                }
                if (preg_match('#/orders/([0-9;]+)$#', rawurldecode($url), $m) === 1) {
                    $ids = array_map('intval', explode(';', $m[1]));
                    return ['status' => 200, 'body' => json_encode(array_values(array_filter($all, static fn (array $o): bool => in_array($o['orderIdentifier'], $ids, true)))),
                        'retry_after' => null];
                }
                return ['status' => 404, 'body' => '', 'retry_after' => null];
            }
        };
        return new ClickAndDropClient($http, 'TEST-KEY-not-a-real-one', static function (int $micro): void {
        });
    }

    public function testThePullReadsFromTheCheckpointLessThreeDaysAndOnlyGets(): void
    {
        $o = static fn (int $id, string $ref, string $day, ?string $manifested, string $tracking): array => ['orderIdentifier' => $id, 'orderReference' => $ref,
            'createdOn' => $day . 'T10:00:00.1234567', 'manifestedOn' => $manifested, 'trackingNumber' => $tracking, 'packages' => [['packageNumber' => 1,
                'trackingNumber' => $tracking]]];
        $byDay = ['2026-11-08' => [$o(1, '1360001', '2026-11-08', '2026-11-08T18:00:35.4961013', 'JV000000001GB')],
            '2026-11-09' => [$o(2, '#2833', '2026-11-09', null, 'OX000000002GB'), $o(3, 'Mr Test', '2026-11-09', '2026-11-09T18:00:35', 'JV000000003GB')]];
        $calls = [];
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-10 02:20:00', new \DateTimeZone('UTC'));
        $rm = new RoyalMailBills(self::$db, $clock);
        $r = $rm->pull(self::system(), $this->acct['account'], self::cnd($byDay, $calls), '2026-11-08');
        self::assertSame(['2026-11-08', '2026-11-09', 3, 3, 3], [$r['from'], $r['to'], $r['orders'], $r['labels'], $r['added']]);
        self::assertSame([['JV000000001GB', 'api', '1', '1360001', '2026-11-08'], ['JV000000003GB', 'api', '3', null, '2026-11-09'], ['OX000000002GB', 'api', '2', '#2833', null]],
            array_map(static fn (array $x): array => array_map(static fn ($v): ?string => $v === null ? null : (string) $v, array_values($x)),
                self::$db->all('SELECT tracking_no, source, source_id, reference, manifest_day FROM carrier_label ORDER BY tracking_no')), 'a free-text reference not kept');
        self::assertSame('2026-11-09', (string) self::$db->value('SELECT read_to FROM carrier_label_pull WHERE account_id = ?', [$this->acct['account']]));
        // the next night: from the checkpoint less 3 days, and the label with no manifest yet asked for again by its number
        $byDay['2026-11-09'][0]['manifestedOn'] = '2026-11-10T00:45:59';
        $calls = [];
        $clock2 = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-11 02:20:00', new \DateTimeZone('UTC'));
        $r = (new RoyalMailBills(self::$db, $clock2))->pull(self::system(), $this->acct['account'], self::cnd($byDay, $calls));
        self::assertSame(['2026-10-28', '2026-11-10', 1], [$r['from'], $r['to'], $r['filled']], 'the last 14 days at least (r1 MEDIUM 10)');
        self::assertSame('2026-11-10', (string) self::$db->value("SELECT manifest_day FROM carrier_label WHERE tracking_no = 'OX000000002GB'"), 'filled when manifested');
        foreach ($calls as $url) {
            self::assertStringStartsWith(ClickAndDropClient::BASE . '/orders', $url);
        }
        self::assertCount(14 + 1, $calls, 'fourteen days listed, one call for the orders by number');
        // a key Click & Drop refuses: said in CW's words, never the key; the checkpoint stays
        $calls = [];
        try {
            (new RoyalMailBills(self::$db, $clock2))->pull(self::system(), $this->acct['account'], self::cnd($byDay, $calls, 401));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('cnd_refused', $e->errorCode);
        }
        $err = (string) self::$db->value('SELECT last_error FROM carrier_label_pull WHERE account_id = ?', [$this->acct['account']]);
        self::assertStringContainsString('401', $err);
        self::assertStringNotContainsString('TEST-KEY', $err . json_encode(self::$db->all('SELECT * FROM audit_log')));
        self::assertSame('2026-11-10', (string) self::$db->value('SELECT read_to FROM carrier_label_pull WHERE account_id = ?', [$this->acct['account']]));
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

    public function testTheServerToolsCheckStoreAndPrintNoLine(): void
    {
        $dir = sys_get_temp_dir() . '/cw_rm_tool_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            file_put_contents("{$dir}/bill.html", RmBill::page($this->week(), ['invoice' => '9070000001']));
            $check = self::script('rm_bill', "--file={$dir}/bill.html", '--account=0706703000');
            self::assertSame(0, $check['code'], $check['out']);
            self::assertStringContainsString('bill 9070000001 of 2026-11-16: 2026-11-04 to 2026-11-10, 7 rows, net £44.56', $check['out']);
            self::assertStringContainsString('checked only: nothing written', $check['out']);
            self::assertStringNotContainsString('JV0000', $check['out'], 'never a label');
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
            $up = self::script('rm_bill', "--file={$dir}/bill.html", '--account=0706703000', '--upload');
            self::assertSame(0, $up['code'], $up['out']);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM carrier_invoice WHERE status = 'live' AND uploaded_actor = 'system:delivery_cli'"));
            $st = self::script('rm_labels', '--status');
            self::assertSame(0, $st['code'], $st['out']);
            self::assertStringContainsString('0706703000 (active): 9 labels', $st['out']);
            // Vape Big's account: its number set while it has no bill, then switched on
            $vb = RmFixtures::account(self::$db, $this->vpg, null, 'off', 'vapebig-pending')['account'];
            $set = self::script('carrier_account', '--account=vapebig-pending', '--number=0706799999');
            self::assertSame(0, $set['code'], $set['out']);
            self::assertSame(1, self::script('carrier_account', '--account=0706703000', '--number=0706711111')['code'], 'a bill of it is stored: its number stays');
            self::assertSame(0, self::script('carrier_account', '--account=0706799999', '--switch-on')['code']);
            self::assertSame(['0706799999', 'active'], array_values(array_map('strval', (array) self::$db->one('SELECT account_no, state FROM carrier_account WHERE id = ?', [$vb]))));
            $this->assertRebuilds();
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testRefusalsAndOnlyTheDeliveryBillsJobWrites(): void
    {
        $page = RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '943')]);
        foreach ([
            'other_account' => fn () => self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '943')],
                ['account' => '0706709999'])),
            'account_off' => fn () => self::rm()->upload(self::system(), RmFixtures::account(self::$db, $this->vpg, null, 'off', 'vapebig-pending')['account'], $page),
            'staff_required' => fn () => self::rm()->upload(Caller::channel($this->vpg, 'vapeandgo'), $this->acct['account'], $page),
        ] as $code => $fn) {
            try {
                $fn();
                self::fail($code);
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        $admin = self::staff(); // StockTestCase's staff: no Delivery bills job
        try {
            self::rm()->upload($admin, $this->acct['account'], $page);
            self::fail('only the Delivery bills job uploads');
        } catch (CwException $e) {
            self::assertContains($e->errorCode, ['role_not_allowed', 'admin_cannot_post', 'staff_not_allowed']);
        }
        $person = Caller::staff(self::$db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('rm1', 'rm1@example.com', 'Shareef T', 'x')"));
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'delivery_bills')", [$person->staffUserId]);
        $r = self::rm()->upload($person, $this->acct['account'], $page);
        self::assertSame((string) $person->staffUserId, (string) self::$db->value('SELECT uploaded_by FROM carrier_invoice WHERE id = ?', [$r['invoice_id']]));
        try {
            self::rm()->replace(self::system(), $r['invoice_id'], RmBill::page([RmBill::line('2026-11-09', 'TPL', 1, 254, '943')], ['invoice' => '9070000099']));
            self::fail('a corrected file is of the same bill');
        } catch (CwException $e) {
            self::assertSame('other_bill', $e->errorCode);
        }
        $this->assertRebuilds();
    }

    public function testTheClickAndDropReportKeepsOnlyItsFieldsAndASecondSourceOnlyFillsEmptyOnes(): void
    {
        $head = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer', 'Company',
            'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code', 'Tracking number',
            'Tracking status', 'Sales order number', 'Department', 'Notification email address', 'Notification mobile number', 'Tags'];
        $serial = static fn (string $at): float => ((new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp()
            - (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->getTimestamp()) / 86400;
        $row = static fn (string $tracking, string $ref, string $svc, float $kg, string $so) => [779001.0, 'B1', 'M2288', RmFixtures::CHANNELS['api'], $ref,
            $serial('2026-11-09 17:00:00'), $serial('2026-11-09 18:00:35'), 'Testy McTestface', 'Fakeco', '1 Imaginary Road', 'Flat 9', 'Nowhereton', 'ZZ9 9ZZ', 'Neverland',
            $kg, 'Parcel', 'Tracked 48', $svc, $tracking, 'Delivered', $so, 'Dept', 'nobody@example.invalid', '07000000000', 'tag'];
        // the API brought one label first (its number and manifest time, no service): the report fills the rest, never changes what is there
        self::rm()->importLabels(self::system(), $this->acct['account'], [['tracking_no' => 'JV000000031GB', 'source_id' => 779001, 'reference' => '1360031',
            'created_on' => '2026-11-09 09:00:00.000000', 'manifested_at' => '2026-11-09 18:00:35.000000', 'manifest_day' => '2026-11-09']], 'api');
        $r = self::rm()->importReport(self::system(), $this->acct['account'], XlsWriter::file([$head, $row('JV000000031GB', '1360999', 'TPL48HV', 0.5, 'x'),
            $row('AS000000032GB', '1360032', 'SD1', 0.1, '5155514809')]));
        self::assertSame([2, 1, 1, 1], [$r['labels'], $r['added'], $r['filled'], $r['differs']], 'the reference differs: the first source\'s stays');
        $got = self::$db->all('SELECT tracking_no, source, source_id, reference, channel_code, service_code, package, weight_g, manifest_no, manifest_day, sales_order '
            . 'FROM carrier_label ORDER BY tracking_no');
        self::assertSame([
            ['AS000000032GB', 'report', '779001', '1360032', 'api', 'SD1', 'parcel', '100', '2288', '2026-11-09', '5155514809'],
            ['JV000000031GB', 'api', '779001', '1360031', 'api', 'TPL48HV', 'parcel', '500', '2288', '2026-11-09', null],
        ], array_map(static fn (array $x): array => array_map(static fn ($v): ?string => $v === null ? null : (string) $v, array_values($x)), $got));
        $all = json_encode([self::$db->all('SELECT * FROM carrier_label'), self::$db->all('SELECT * FROM audit_log')]);
        foreach (['Testy', 'Imaginary', 'ZZ9', 'nobody@', '07000000000', 'Neverland'] as $never) {
            self::assertStringNotContainsString($never, (string) $all, 'no name, address, postcode, email or phone kept anywhere');
        }
    }

    // ---- the Codex check r1 (cw_work/runs/rmbills_codex_r1.txt): each shown failing first (runs/rmbills_r1_red.log) ----------------------------

    /**
     * r1 HIGH 1: a bill read before Click & Drop's report has brought its labels' service: the API's labels of the day have no service yet, so
     * the day's money is HELD (waiting for the report), never all of it on the main shop's "not on an order" (it holds Electrofag's and Hayati's
     * part); the report then puts it where it belongs. A Special Delivery manifest with no label on a day not covered: held too.
     */
    public function testABillBeforeTheReportIsHeldUntilTheLabelsAreComplete(): void
    {
        $day = '2026-11-09';
        self::order($this->vpg, '1031', '1360031', '2026-11-09T09:00:00Z', ['JV000000031GB' => '2026-11-09T10:00:00Z']);
        self::order($this->ef, '2031', '2931', '2026-11-09T09:00:00Z', ['JV000000032GB' => '2026-11-09T10:00:00Z']);
        $api = static fn (string $t, string $ref): array => ['tracking_no' => $t, 'source_id' => 700000 + (int) substr($t, 2, 9), 'reference' => $ref,
            'created_on' => $day . ' 09:00:00.000000', 'manifested_at' => $day . ' 18:00:00.000000', 'manifest_day' => $day];
        self::rm()->importLabels(self::system(), $this->acct['account'], [$api('JV000000031GB', '1360031'), $api('JV000000032GB', '2931')], 'api');
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 2, 508, '943'),
            RmBill::line($day, 'SD1', 1, 1004, 'CLICK AND DROP M2288', '5155514809')]))['invoice_id'];
        self::assertSame(['held||no_report' => 1512], self::where($id), 'waiting for the report: in no shop\'s profit');
        self::assertSame([], self::days($this->vpg));
        self::assertSame([], self::days($this->ef));
        // the report: the labels' service; the day is planned again, each on its own order
        self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000031GB', 'TPL48HV', $day, '1360031'),
            RmFixtures::label('JV000000032GB', 'TPL48HV', $day, '2931'), RmFixtures::label('AS000000033GB', 'SD1', $day, '1360099',
                ['manifest_no' => 2288, 'sales_order' => '5155514809', 'weight_g' => 80])], 'report');
        self::assertSame([254, 254], [DeliveryBills::orderCost(self::$db, $this->vpg, '1031'), DeliveryBills::orderCost(self::$db, $this->ef, '2031')]);
        self::assertSame(['2026-11-09|delivery_cost' => 254, '2026-11-09|delivery_other' => 1004], self::days($this->vpg));
        $this->assertRebuilds();
    }

    /** r1 HIGH 2: a report of another account (its channels are not this account's) is refused whole: its labels would take this account's money. */
    public function testAReportOfAnotherAccountIsRefused(): void
    {
        $head = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer', 'Company',
            'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code', 'Tracking number',
            'Tracking status', 'Sales order number', 'Department', 'Notification email address', 'Notification mobile number', 'Tags'];
        $row = static fn (string $channel): array => [779001.0, 'B1', 'M2288', $channel, '13600', 46335.75, 46335.75, 'X', '', '', '', '', '', '', 0.3, 'Parcel', 'T48',
            'TPL48HV', 'JV000000041GB', 'x', 'uuid', '', '', '', ''];
        try {
            self::rm()->importReport(self::system(), $this->acct['account'], XlsWriter::file([$head, $row('Vape Big Ltd - API (Vape Big Ltd)')]));
            self::fail('another account\'s report');
        } catch (CwException $e) {
            self::assertSame('other_account', $e->errorCode);
            self::assertStringNotContainsString('Vape Big Ltd', $e->getMessage(), 'a channel CW does not know is never said');
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_label'));
    }

    /**
     * r1 HIGH 3: the parcels of a plan are read 500 tracking numbers a statement; an order stored again between two of them (one of its parcels
     * gone) is seen at two snapshots: the plan names no snapshot it could pass with (the write is tried again).
     */
    public function testAnOrderSeenAtTwoSnapshotsInOnePlanFailsItsCheck(): void
    {
        $day = '2026-11-09';
        self::order($this->vpg, '1051', '1360051', '2026-11-09T09:00:00Z', ['JV000000051GB' => '2026-11-09T10:00:00Z']);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [$this->vpg, '1051', 'odl:9' . $i, sprintf('JV%09dGB', 600000000 + $i)];
        }
        self::$db->exec('INSERT INTO sale_parcel (channel_id, order_ref, parcel_ref, tracking_no, carrier, label_at, first_at, updated_at) VALUES '
            . implode(', ', array_fill(0, 500, "(?, ?, ?, ?, 'royal_mail', '2026-11-09 10:00:00', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")), array_merge(...$rows));
        $labels = [1 => ['tracking_no' => 'JV000000051GB', 'reference' => null, 'channel_code' => null, 'manifest_day' => $day]];
        foreach ($rows as $k => $r) {
            $labels[$k + 2] = ['tracking_no' => $r[3], 'reference' => null, 'channel_code' => null, 'manifest_day' => $day];
        }
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        // between the two statements that read the parcels: the order stored again
        \CW\Tests\Support\HookedStatement::$before = ['SELECT p.channel_id, p.order_ref' => static function (): void {
            \CW\Tests\Support\HookedStatement::$before['SELECT p.channel_id, p.order_ref'] = static function (): void {
                self::$db->exec("UPDATE sale_order SET snapshot_version = snapshot_version + 1, updated_at = UTC_TIMESTAMP(6) WHERE order_ref = '1051'");
            };
        }];
        $versions = [];
        \CW\Profit\LabelDestinations::resolve($conn, \CW\Profit\CarrierSettings::account($conn, $this->acct['account']), $labels, [], $versions);
        \CW\Tests\Support\HookedStatement::$before = [];
        self::assertSame([$this->vpg . '|1051' => \CW\Profit\LabelDestinations::CONFLICT], $versions);
    }

    /** r1 HIGH 4: an account's number is changed only under the delivery bills' lock (a first bill of it being stored waits, or the change does). */
    public function testAnAccountsNumberChangesOnlyUnderTheBillsLock(): void
    {
        $vb = RmFixtures::account(self::$db, $this->vpg, null, 'off', 'vapebig-pending')['account'];
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', ['cw_delivery_bills:' . TestDb::name()]));
        $r = self::script('carrier_account', '--account=vapebig-pending', '--number=0706799999');
        $other->value('SELECT RELEASE_ALL_LOCKS()');
        self::assertSame(1, $r['code'], $r['out']);
        self::assertStringContainsString('busy', $r['out']);
        self::assertSame('vapebig-pending', (string) self::$db->value('SELECT account_no FROM carrier_account WHERE id = ?', [$vb]));
    }

    /** r1 MEDIUM 5: labels read again plan their keys again (a write that died between the labels and the plan is done by the next read). */
    public function testLabelsReadAgainPlanTheirDaysAgain(): void
    {
        $day = '2026-11-09';
        self::order($this->vpg, '1061', '1360061', '2026-11-09T09:00:00Z', ['JV000000061GB' => '2026-11-09T10:00:00Z']);
        self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000061GB', 'TPL48HV', $day, '1360061')], 'report');
        self::rm()->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line($day, 'TPL', 1, 254, '943')]));
        // a label stored by a read whose plan never ran (the process died between them)
        self::$db->exec("INSERT INTO carrier_label (account_id, tracking_no, source, reference, channel_code, service_code, package, weight_g, created_on, manifested_at, "
            . "manifest_no, manifest_day, first_at, updated_at) VALUES (?, 'JV000000062GB', 'report', '1360062', 'api', 'TPL48HV', 'parcel', 300, ?, ?, 2288, ?, "
            . 'UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$this->acct['account'], $day . ' 10:00:00', $day . ' 18:00:00', $day]);
        self::assertNotSame([], RoyalMailBills::drift(self::$db, $this->acct['account']));
        $r = self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000062GB', 'TPL48HV', $day, '1360062')], 'report');
        self::assertSame([0, 0], [$r['added'], $r['filled']]);
        self::assertSame([], RoyalMailBills::drift(self::$db, $this->acct['account']), 'read again: its day planned again');
        $this->assertRebuilds();
    }

    /** r1 MEDIUM 8: the clean-up after an undo failing never says the undo failed (it is done: the nightly ends the allocations). */
    public function testACleanUpFailureAfterAnUndoIsNotAFailedUndo(): void
    {
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($this->week(), ['invoice' => '9070000001']))['invoice_id'];
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['UPDATE carrier_allocation SET ended_at = ? WHERE ended_at IS NULL AND id IN' => static function (): void {
            throw new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded', 1205);
        }];
        (new RoyalMailBills($conn))->undo(self::system(), $id);
        \CW\Tests\Support\HookedStatement::$before = [];
        self::assertSame('undone', (string) self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$id]));
        self::assertNotSame([], array_filter(Invariants::check(self::$db), static fn (string $v): bool => str_starts_with($v, 'DB9')), 'its allocations not ended yet');
        // switched off or not, the nightly ends them
        self::$db->exec("UPDATE carrier_account SET state = 'off'");
        self::rm()->replan(self::system());
        self::assertSame([], Invariants::check(self::$db));
        self::$db->exec("UPDATE carrier_account SET state = 'active'");
    }

    /** r1 MEDIUM 9: a parcel of another carrier is never a Royal Mail one, even marked manual with a number of Royal Mail's shape. */
    public function testAManualParcelOfAnotherCarrierIsNotRoyalMails(): void
    {
        self::order($this->vpg, '1071', '1360071', '2026-11-09T09:00:00Z', []);
        self::$db->exec("INSERT INTO sale_parcel (channel_id, order_ref, parcel_ref, tracking_no, carrier, is_manual, label_at, first_at, updated_at) VALUES "
            . "(?, '1071', 'odl:1', 'JV000000071GB', 'dpd', 1, '2026-11-09 10:00:00', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "(?, '1071', 'odl:2', 'JV000000072GB', 'manual', 1, '2026-11-09 10:00:00', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))", [$this->vpg, $this->vpg]);
        $d = \CW\Profit\LabelDestinations::resolve(self::$db, \CW\Profit\CarrierSettings::account(self::$db, $this->acct['account']),
            [1 => ['tracking_no' => 'JV000000071GB', 'reference' => '1360071', 'channel_code' => 'api', 'manifest_day' => '2026-11-09'],
                2 => ['tracking_no' => 'JV000000072GB', 'reference' => '1360071', 'channel_code' => 'api', 'manifest_day' => '2026-11-09']], []);
        self::assertSame(['held', 'order'], [$d[1]['dest'], $d[2]['dest']]);
        self::assertSame('carrier', $d[1]['held_reason']);
    }

    /** r1 MEDIUM 10: every pull reads the last 14 days at least (an order that gets its label late is read when it does). */
    public function testEveryPullReadsTheLastFourteenDaysAtLeast(): void
    {
        $calls = [];
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20 02:20:00', new \DateTimeZone('UTC'));
        self::$db->exec("INSERT INTO carrier_label_pull (account_id, read_to) VALUES (?, '2026-11-19')", [$this->acct['account']]);
        $r = (new RoyalMailBills(self::$db, $clock))->pull(self::system(), $this->acct['account'], self::cnd([], $calls));
        self::assertSame(['2026-11-06', '2026-11-19'], [$r['from'], $r['to']]);
    }

    /** r1 MEDIUM 11: a reference of 10 digits or more (a phone number) and a tracking number not of Royal Mail's shape are never kept. */
    public function testAPhoneShapedReferenceOrTrackingIsNeverKept(): void
    {
        $r = self::rm()->importLabels(self::system(), $this->acct['account'], [RmFixtures::label('JV000000081GB', 'TPL48HV', '2026-11-09', '0712345678'),
            RmFixtures::label('07123456789', 'TPL48HV', '2026-11-09', '1360081'), RmFixtures::label('JV000000082GB', 'TPL48HV', '2026-11-09', '7123456789')], 'report');
        self::assertSame(2, $r['added']);
        self::assertSame([['JV000000081GB', null], ['JV000000082GB', null]], array_map(static fn (array $x): array => [(string) $x['tracking_no'], $x['reference']],
            self::$db->all('SELECT tracking_no, reference FROM carrier_label ORDER BY tracking_no')));
    }

    /** r1 LOW 20: the list's sums of a bill are its live allocations only (a bill loading counts nothing; a replaced one what it had). */
    public function testTheListsSumsAreOfLiveAllocationsOnly(): void
    {
        $id = self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($this->week(), ['invoice' => '9070000001']))['invoice_id'];
        self::$db->exec("UPDATE carrier_invoice SET status = 'loading' WHERE id = ?", [$id]);
        $sums = (new \CW\Profit\RoyalMailViews(self::$db))->sums([$id]);
        self::$db->exec("UPDATE carrier_invoice SET status = 'live' WHERE id = ?", [$id]);
        self::assertSame(0, ($sums[$id]['order']['p'] ?? 0) + ($sums[$id]['shop']['p'] ?? 0) + ($sums[$id]['outside']['p'] ?? 0));
    }

}
