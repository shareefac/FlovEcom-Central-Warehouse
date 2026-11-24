<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\DeliveryBills;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Profit\RoyalMailLook;
use CW\Profit\RoyalMailViews;
use CW\Settings;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * DL86, NO HOLD (the owner, 24 Nov 2026: "yes no hold"), SYNTHETIC labels and bills, each shown failing first (runs/rmbills_r11_red.log): only
 * a day covered by no report (or an account switched off) waits; units billed above the labels go to the main shop's "not on an order"; a CW
 * parcel missing from the report and a label of an odd service hold nothing and are on the "needs a look" list; Special Delivery is priced by
 * manifest number; wrong weight waits for no group; a day or manifest billed more than its labels by more than a CW setting (1.0%) is red;
 * the same report cannot cover other days; and the Codex check r10's findings that still apply (#10, #12, #13, #14, #15).
 */
final class RoyalMailNoHoldTest extends StockTestCase
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

    protected function tearDown(): void
    {
        \CW\Tests\Support\HookedStatement::$before = [];
        // the alarm setting as the migration seeds it (app_setting is not emptied between tests)
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"1.0\"' AS JSON) WHERE setting_key = 'delivery.rm_billed_more_pct'");
        parent::tearDown();
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

    /** @param array<string, int> $a @return array<string, int> by key (as where() gives it: the shops' ids decide the order) */
    private static function ks(array $a): array
    {
        ksort($a);
        return $a;
    }

    private function report(array $labels, string $from, string $to, ?string $sha = null, ?RoyalMailBills $rm = null): array
    {
        return RmFixtures::report($rm ?? self::rm(), self::system(), $this->acct['account'], $labels, $from, $to, $sha);
    }

    private function bill(array $lines, string $invoice = '9070000001', string $date = 'Nov 16, 2026'): int
    {
        return (int) self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => $invoice, 'date' => $date]))['invoice_id'];
    }

    private static function sd(string $t, string $day, string $ref, int $manifest, ?string $so, array $over = []): array
    {
        return RmFixtures::label($t, 'SD1', $day, $ref, $over + ['manifest_no' => $manifest, 'sales_order' => $so, 'weight_g' => 80]);
    }

    /** @return list<array<string, mixed>> the "needs a look" items of these days of a kind */
    private function look(array $days, string $kind): array
    {
        return array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], $days), static fn (array $i): bool => $i['kind'] === $kind));
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

    /** DL86 1: only a day covered by no report waits; the health alert's days waiting count only those. */
    public function testP1OnlyAnUncoveredDayWaits(): void
    {
        self::order($this->vpg, '3011', '1393011', ['JV000003011GB' => ['2026-11-13T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '3012', '1393012', ['JV000003012GB' => ['2026-11-13T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000003011GB', 'TPL48HV', '2026-11-13', '1393011')], '2026-11-13', '2026-11-13');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943'), RmBill::line('2026-11-13', 'TPL', 2, 508, '943')]);
        self::assertSame(self::ks(['held||not_covered' => 254, "order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 254]), self::where($id),
            'the 12th is covered by no report: it waits; the 13th goes through, a CW parcel of it in no report or not');
        self::assertSame([['day' => '2026-11-12', 'reasons' => ['not_covered'], 'pence' => 254]], RoyalMailBills::waitingDays(self::$db, $this->acct['account']));
        $alerts = RoyalMailBills::health(self::$db, new \DateTimeImmutable('2026-11-27 13:00:00', new \DateTimeZone('Europe/London')));
        $waiting = array_values(array_filter($alerts, static fn (array $a): bool => $a['kind'] === 'rm_days_waiting'));
        self::assertSame(1, $waiting[0]['data']['days'] ?? null, 'the 12th has waited 15 days');
        $this->assertRebuilds();
    }

    /** DL86 2: billed more than labels: the rest of the day goes whole to the main shop's "not on an order"; fewer: split by shop as built. */
    public function testP2BilledMoreGoesToTheMainShopsNotOnAnOrder(): void
    {
        self::order($this->vpg, '3021', '1393021', ['JV000003021GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '3022', '9322', ['JV000003022GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '3023', '1393023', ['JV000003023GB' => ['2026-11-13T10:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '3024', '9324', ['JV000003024GB' => ['2026-11-13T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000003021GB', 'TPL48HV', '2026-11-12', '1393021'), RmFixtures::label('JV000003022GB', 'TPL48HV', '2026-11-12', '9322'),
            RmFixtures::label('JV000003023GB', 'TPL48HV', '2026-11-13', '1393023'), RmFixtures::label('JV000003024GB', 'TPL48HV', '2026-11-13', '9324')],
            '2026-11-12', '2026-11-13');
        $a = $this->bill([RmBill::line('2026-11-12', 'TPL', 3, 762, '943')], '9070000021');
        self::assertSame(self::ks(["order|{$this->ef}|" => 254, "order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 254]), self::where($a),
            '3 billed for 2 labels: each order 254, the extra unit on Vape and Go\'s not on an order (never split with Electrofag)');
        $b = $this->bill([RmBill::line('2026-11-13', 'TPL', 1, 254, '943')], '9070000022');
        self::assertSame(self::ks(["order|{$this->ef}|" => 254, "order|{$this->vpg}|" => 254, "shop|{$this->ef}|" => -127, "shop|{$this->vpg}|" => -127]), self::where($b),
            '1 billed for 2 labels: the labels not charged split by the shops\' shares, as built');
        $c = $this->bill([RmBill::line('2026-11-13', 'TPL', 2, 508, '943')], '9070000023', 'Nov 23, 2026');
        self::assertSame(self::ks(["order|{$this->ef}|" => 254, "order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => -254]), self::where($b),
            'a later bill takes the 13th to 3 billed for 2 labels: the whole line of the day is Vape and Go\'s');
        self::assertSame(self::ks(["shop|{$this->vpg}|" => 508]), self::where($c));
        $this->assertRebuilds();
    }

    /** DL86 3 (and the Codex check r10 #10): a CW parcel of a covered day in no report holds nothing and is listed, a manual one too. */
    public function testP3AMissingCwParcelHoldsNothingAndIsListed(): void
    {
        $day = '2026-11-12';
        self::order($this->vpg, '3031', '1393031', ['JV000003031GB' => [$day . 'T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '3032', '1393032', ['JV000003032GB' => [$day . 'T11:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '3033', '9333', ['JV000003033GB' => [$day . 'T12:00:00Z', 'TPM24HV', 'manual']]);
        self::order($this->ef, '3034', '9334', ['JV000003034GB' => [$day . 'T13:00:00Z', 'TSS']]); // a return: no label of the account
        $this->report([RmFixtures::label('JV000003031GB', 'TPL48HV', $day, '1393031')], $day, $day);
        $id = $this->bill([RmBill::line($day, 'TPL', 3, 762, '943')]);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 508]), self::where($id), 'nothing waits: the 2 units of no label on Vape and Go\'s line');
        self::assertSame(['JV000003032GB', 'JV000003033GB'], array_column($this->look([$day], 'parcel_missing'), 'tracking_no'),
            'both CW parcels in no report are listed, the manual one too; the return is not');
        $this->report([RmFixtures::label('JV000003032GB', 'TPL48HV', $day, '1393032')], $day, $day);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 508, "shop|{$this->vpg}|" => 254]), self::where($id), 'the later report has it: it takes the day\'s price from the line');
        self::assertSame(['JV000003033GB'], array_column($this->look([$day], 'parcel_missing'), 'tracking_no'));
        $this->assertRebuilds();
    }

    /** DL86 4: a label with no service, a service CW does not map, or another service than its CW parcel's: no price, no hold, listed. */
    public function testP4AnOddServiceHoldsNothingAndIsListed(): void
    {
        $day = '2026-11-12';
        foreach (['3041' => 'TPL48HV', '3042' => 'TPL48HV', '3043' => 'TPL48HV', '3044' => 'TPM24HV'] as $ref => $service) {
            self::order($this->vpg, (string) $ref, '139' . $ref, ["JV00000{$ref}GB" => [$day . 'T10:00:00Z', $service]]);
        }
        $this->report([RmFixtures::label('JV000003041GB', 'TPL48HV', $day, '1393041'), RmFixtures::label('JV000003042GB', 'TPL49HV', $day, '1393042'),
            RmFixtures::label('JV000003043GB', 'TPL48HV', $day, '1393043', ['service_code' => null]), RmFixtures::label('JV000003044GB', 'TPL48HV', $day, '1393044')],
            $day, $day);
        $id = $this->bill([RmBill::line($day, 'TPL', 4, 1016, '943')]);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 762]), self::where($id), 'only the good label is priced; the 3 others\' units on the line');
        self::assertSame([['JV000003042GB', 'unknown_service'], ['JV000003043GB', 'no_service'], ['JV000003044GB', 'service_differs']],
            array_map(static fn (array $i): array => [$i['tracking_no'], $i['why']], $this->look([$day], 'odd_service')));
        $this->assertRebuilds();
    }

    /** DL86 5: Special Delivery by manifest number whatever day the bill gives; a manifest with no label: the main shop's, listed red. */
    public function testP5SpecialDeliveryIsPricedByManifestWhateverDayTheBillGives(): void
    {
        foreach (['3051', '3052', '3053'] as $ref) {
            self::order($this->vpg, $ref, '139' . $ref, ["AS00000{$ref}GB" => ['2026-11-07T23:00:00Z', 'SD1']]);
        }
        $this->report([self::sd('AS000003051GB', '2026-11-07', '1393051', 2501, '5155503051'), self::sd('AS000003052GB', '2026-11-07', '1393052', 2501, '5155503051'),
            self::sd('AS000003053GB', '2026-11-07', '1393053', 2501, '5155503051'), self::sd('AS000003054GB', '2026-11-08', '1393054', 2502, '5155503054'),
            self::sd('AS000003055GB', '2026-11-08', '1393055', 2502, '5155503054')], '2026-11-07', '2026-11-08');
        $id = $this->bill([RmBill::line('2026-11-08', 'SD1', 3, 2790, 'CLICK AND DROP M2501', '5155503051'),
            RmBill::line('2026-11-08', 'SD1', 2, 1860, 'CLICK AND DROP M2502', '5155503054'), RmBill::line('2026-11-08', 'SD1', 1, 1189, 'CLICK AND DROP M2503')]);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 2790, "shop|{$this->vpg}|" => 3049]), self::where($id),
            'M2501 (labelled the 7th, billed the 8th) on its 3 orders; M2502 on its 2 labels (no CW parcel: Vape and Go\'s line); M2503, no label: Vape and Go\'s line');
        $red = array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-07', '2026-11-08']), static fn (array $i): bool => $i['red']));
        self::assertSame([['manifest', 2503]], array_map(static fn (array $i): array => [$i['kind'], $i['manifest']], $red), 'only the manifest with no label is red');
        $this->assertRebuilds();
    }

    /** DL86 6: wrong weight is shared over its sales order's labels, no group waits (a label naming no sales order, one with no label). */
    public function testP6WrongWeightWithNoGroupWait(): void
    {
        self::order($this->vpg, '3061', '1393061', ['AS000003061GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->ef, '3062', '9362', ['AS000003062GB' => ['2026-11-12T11:00:00Z', 'SD1']]);
        self::order($this->vpg, '3063', '1393063', ['AS000003063GB' => ['2026-11-12T12:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000003061GB', '2026-11-12', '1393061', 2511, '5155503061'), self::sd('AS000003062GB', '2026-11-12', '9362', 2511, '5155503061'),
            self::sd('AS000003063GB', '2026-11-12', '1393063', 2512, null)], '2026-11-12', '2026-11-14');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 2, 1860, 'CLICK AND DROP M2511', '5155503061'), RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2512'),
            RmBill::line('2026-11-14', 'RX1', 1, 275, 'ADJ5155503061RCN Surcharge'), RmBill::line('2026-11-14', 'RX1', 1, 275, 'ADJ5155509999RCN Surcharge')]);
        self::assertSame(self::ks(["order|{$this->ef}|" => 930 + 137, "order|{$this->vpg}|" => 930 + 930 + 137, "shop|{$this->vpg}|" => 1 + 275]), self::where($id),
            'M2511 on its 2 orders, the wrong weight 2 x 137 (1p left); M2512 (its label names no sales order) on its order; the other sales order has no label');
        self::assertSame(['5155509999'], array_column($this->look(['2026-11-14'], 'no_labels'), 'sales_order'));
        $this->assertRebuilds();
    }

    /** DL86 7: billed more than labels by more than the CW setting (1.0%) is red; the setting changes it; the health alert says it. */
    public function testP7TheAlarmIsACwSettingRedOnlyAboveIt(): void
    {
        self::assertArrayHasKey('delivery.rm_billed_more_pct', Settings::RULES);
        self::assertSame('"1.0"', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'delivery.rm_billed_more_pct'"));
        $labels = [];
        foreach (['2026-11-12', '2026-11-13'] as $k => $day) {
            for ($i = 0; $i < 100; $i++) {
                $n = 3700000 + $k * 1000 + $i;
                $labels[] = RmFixtures::label(sprintf('JW%09dGB', $n), 'TPL48HV', $day, (string) (1300000 + $n % 100000)); // no CW parcel: Vape and Go's line
            }
        }
        $this->report($labels, '2026-11-12', '2026-11-13');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 101, 101 * 254, '943'), RmBill::line('2026-11-13', 'TPL', 102, 102 * 254, '943')]);
        self::assertSame(self::ks(["shop|{$this->vpg}|" => 203 * 254]), self::where($id));
        $red = array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12', '2026-11-13']), static fn (array $i): bool => $i['red']));
        self::assertSame([['billed_more', '2026-11-13', 'TPL', 100, 102]], array_map(static fn (array $i): array => [$i['kind'], $i['day'], $i['product'], $i['labels'],
            $i['billed']], $red), '101 for 100 is 1%: not above the setting; 102 is 2%: red');
        self::assertSame(1, count(array_filter((new RoyalMailViews(self::$db))->bill($id)['look'], static fn (array $i): bool => $i['red'])), 'red on the bill\'s page');
        $tuesday = new \DateTimeImmutable('2026-11-24 13:00:00', new \DateTimeZone('Europe/London'));
        self::assertContains('rm_billed_more', array_column(RoyalMailBills::health(self::$db, $tuesday), 'kind'));
        (new Settings(self::$db))->set(self::system(), 'delivery.rm_billed_more_pct', '2.5', 'a test of the alarm setting');
        self::assertSame([], array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12', '2026-11-13']),
            static fn (array $i): bool => $i['red'])), '2% is not above 2.5%');
        self::assertNotContains('rm_billed_more', array_column(RoyalMailBills::health(self::$db, $tuesday), 'kind'));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'delivery.rm_billed_more_pct' "
            . "AND action <> 'baseline'"), 'the change is a version of the setting');
    }

    /** DL86 10 (the Codex check r10 #1): the same report cannot cover other days; the same report for the same days is "already loaded". */
    public function testP10TheSameReportForOtherDaysIsRefused(): void
    {
        $labels = [RmFixtures::label('JV000003101GB', 'TPL48HV', '2026-11-12', '1393101')];
        $sha = str_repeat('ab', 32);
        $this->report($labels, '2026-11-12', '2026-11-12', $sha);
        try {
            $this->report($labels, '2026-11-12', '2026-11-13', $sha);
            self::fail('the same file for 12-13 Nov');
        } catch (CwException $e) {
            self::assertSame('already_loaded', $e->errorCode);
            self::assertStringContainsString('2026-11-12', $e->getMessage());
        }
        $again = $this->report($labels, '2026-11-12', '2026-11-12', $sha);
        self::assertTrue($again['already'] ?? false, 'the same file for the same days: already loaded');
        self::assertSame([1, 0], [(int) self::$db->value('SELECT COUNT(*) FROM carrier_report_import'),
            (int) self::$db->value("SELECT COUNT(*) FROM carrier_day_cover WHERE day = '2026-11-13'")]);
    }

    /** The Codex check r10 #12: a manifest whose order is busy never blocks another manifest of the same day and product. */
    public function testR10M12ABusyManifestNeverBlocksAnotherOfTheSameDay(): void
    {
        self::order($this->vpg, '3121', '1393121', ['AS000003121GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->ef, '3122', '9122', ['AS000003122GB' => ['2026-11-12T11:00:00Z', 'SD1']]);
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2601', '5155503121'),
            RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2602', '5155503122')]);
        self::assertSame(self::ks(['held||not_covered' => 1860]), self::where($id));
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '3121')]));
        try {
            $r = $this->report([self::sd('AS000003121GB', '2026-11-12', '1393121', 2601, '5155503121'), self::sd('AS000003122GB', '2026-11-12', '9122', 2602, '5155503122')],
                '2026-11-12', '2026-11-12');
        } finally {
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->vpg, '3121')]);
        }
        self::assertNotNull($r['replan_error']);
        self::assertSame(self::ks(['held||not_covered' => 930, "order|{$this->ef}|" => 930]), self::where($id), 'M2602 is priced although M2601\'s order is busy');
        self::rm()->replan(self::system(), null, 3650);
        self::assertSame(self::ks(["order|{$this->ef}|" => 930, "order|{$this->vpg}|" => 930]), self::where($id));
        $this->assertRebuilds();
    }

    /** The Codex check r10 #13: a long chain of manifests joined by sales orders never stops a manifest from being priced. */
    public function testR10M13ALongChainOfManifestsIsPriced(): void
    {
        self::order($this->vpg, '3131', '1393131', ['AT000003131GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        $labels = [self::sd('AT000003131GB', '2026-11-12', '1393131', 2701, '5155600001')];
        for ($i = 2; $i <= 45; $i++) {
            // M27xx holds a label of the sales order before it and one of its own: each manifest joined to the next
            $labels[] = self::sd(sprintf('AU%09dGB', 3130000 + $i), '2026-11-12', (string) (1390000 + $i), 2700 + $i, sprintf('51556%05d', $i - 1));
            $labels[] = self::sd(sprintf('AV%09dGB', 3130000 + $i), '2026-11-12', (string) (1391000 + $i), 2700 + $i, sprintf('51556%05d', $i));
        }
        $this->report($labels, '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2701', '5155600001')]);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 930]), self::where($id));
    }

    /** The Codex check r10 #14: a due day whose "done" cannot be written never stops the later due days from being planned. */
    public function testR10M14ADueDeleteErrorNeverSkipsTheLaterDays(): void
    {
        self::order($this->vpg, '3141', '1393141', ['JV000003141GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '3142', '1393142', ['JV000003142GB' => ['2026-11-13T10:00:00Z', 'TPL48HV']]);
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943'), RmBill::line('2026-11-13', 'TPL', 1, 254, '943')]);
        self::assertSame(self::ks(['held||not_covered' => 508]), self::where($id));
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['DELETE FROM carrier_replan_due' => static fn () => throw new \PDOException('the done of the 12th not written')];
        $this->report([RmFixtures::label('JV000003141GB', 'TPL48HV', '2026-11-12', '1393141'), RmFixtures::label('JV000003142GB', 'TPL48HV', '2026-11-13', '1393142')],
            '2026-11-12', '2026-11-13', null, new RoyalMailBills($conn));
        self::assertSame(self::ks(["order|{$this->vpg}|" => 508]), self::where($id), 'the 13th planned all the same');
        self::assertSame(['2026-11-12'], array_map('strval', self::$db->column('SELECT day FROM carrier_replan_due WHERE account_id = ?', [$this->acct['account']])),
            'the 12th stays due (its plan is done; the next run says so)');
    }

    /** The Codex check r10 #15: one account whose check fails never hides another account's alerts; the failure is its own alert. */
    public function testR10M15OneAccountsHealthErrorNeverHidesTheOthers(): void
    {
        $other = RmFixtures::account(self::$db, $this->vpg, null, 'active', '0706703999', false);
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['SELECT COUNT(DISTINCT day) FROM carrier_day_cover' => static fn () => throw new \PDOException('lost')];
        $alerts = RoyalMailBills::health($conn, new \DateTimeImmutable('2026-11-24 13:00:00', new \DateTimeZone('Europe/London')));
        $got = array_map(static fn (array $a): array => [$a['kind'], $a['data']['account'] ?? null], $alerts);
        self::assertContains(['rm_check_failed', RmFixtures::ACCOUNT], $got, 'the first account\'s check failed: said');
        self::assertContains(['rm_report_missing', '0706703999'], $got, 'the other account\'s alert all the same');
        self::assertGreaterThan($this->acct['account'], $other['account']);
    }
}
