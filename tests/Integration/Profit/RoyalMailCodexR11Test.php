<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Ops\AlertDispatch;
use CW\Ops\AlertTransport;
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
 * The Codex check r11 of be868d6 against DL86 (cw_work/runs/rmbills_codex_r11.txt: NO GO, 6 HIGH, 3 MEDIUM, 1 LOW), SYNTHETIC labels and bills,
 * each shown failing first (runs/rmbills_r12_red.log; docs/decisions.md DL87).
 */
final class RoyalMailCodexR11Test extends StockTestCase
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

    protected function tearDown(): void
    {
        \CW\Tests\Support\HookedStatement::$before = [];
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

    /** An order with one goods line and its parcels (tracking => [label time, service, carrier, manual flag]), at a snapshot version. */
    private static function order(int $ch, string $ref, string $no, array $parcels, int $version = 1): void
    {
        $p = [];
        $i = 0;
        foreach ($parcels as $tracking => $x) {
            [$label, $service, $carrier, $manual] = $x + [2 => 'royal_mail', 3 => null];
            $p[] = ['parcel_ref' => 'odl:' . ((int) $ref * 10 + $i++), 'tracking_no' => $tracking, 'carrier' => $carrier, 'service_code' => $service, 'service' => '',
                'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => $manual ?? $carrier === 'manual', 'partial' => false];
        }
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => $version, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => '2026-10-20T09:00:00Z', 'paid_at' => '2026-10-20T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
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

    /** @param array<string, int> $a @return array<string, int> */
    private static function ks(array $a): array
    {
        ksort($a);
        return $a;
    }

    private function report(array $labels, string $from, string $to, ?RoyalMailBills $rm = null): array
    {
        return RmFixtures::report($rm ?? self::rm(), self::system(), $this->acct['account'], $labels, $from, $to);
    }

    private function bill(array $lines, string $invoice = '9070000001', string $date = 'Nov 16, 2026'): int
    {
        return (int) self::rm()->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => $invoice, 'date' => $date]))['invoice_id'];
    }

    private static function sd(string $t, string $day, string $ref, int $manifest, ?string $so, array $over = []): array
    {
        return RmFixtures::label($t, 'SD1', $day, $ref, $over + ['manifest_no' => $manifest, 'sales_order' => $so, 'weight_g' => 80]);
    }

    /** @return list<array<string, mixed>> */
    private function look(array $days, ?string $kind = null): array
    {
        return array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], $days), static fn (array $i): bool => $kind === null || $i['kind'] === $kind));
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

    /** H1: a sales order's label of an odd service never picks the manifest a row naming only that sales order is priced on. */
    public function testH1AnOddLabelNeverPicksTheManifestOfASalesOrder(): void
    {
        self::order($this->ef, '3301', '9331', ['AS000003301GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->vpg, '3302', '1393302', ['AS000003302GB' => ['2026-11-12T11:00:00Z', 'TPM24HV']]);
        $this->report([self::sd('AS000003301GB', '2026-11-12', '9331', 2801, '5155503399'), self::sd('AS000003302GB', '2026-11-12', '1393302', 2801, '5155503302')],
            '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, '', '5155503302')]);
        self::assertSame(["shop|{$this->vpg}|" => 930], self::where($id), 'X\'s only label differs from its CW parcel: X is on no manifest CW can use, never on Electrofag\'s order');
        self::assertSame(['5155503302'], array_column($this->look(['2026-11-12'], 'no_labels'), 'sales_order'));
        $this->assertRebuilds();
    }

    /** H2, H3: a row of a day covered by no report waits alone in its manifest; the manifest's other rows go through, and it joins them once covered. */
    public function testH3ARowOfAnUncoveredDayWaitsAloneInItsManifest(): void
    {
        self::order($this->vpg, '3311', '1393311', ['AS000003311GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->vpg, '3312', '1393312', ['AS000003312GB' => ['2026-11-12T11:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000003311GB', '2026-11-12', '1393311', 2811, '5155503311')], '2026-11-12', '2026-11-12');
        $a = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2811', '5155503311')], '9070000311');
        $b = $this->bill([RmBill::line('2026-11-10', 'SD1', 1, 930, '', '5155503319')], '9070000312');
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($a));
        self::assertSame(['held||not_covered' => 930], self::where($b));
        // X's label comes on M2811 while its order is held elsewhere: the import's plan cannot run now
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '3311')]));
        try {
            $r = $this->report([self::sd('AS000003312GB', '2026-11-12', '1393312', 2811, '5155503319')], '2026-11-12', '2026-11-12');
        } finally {
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->vpg, '3311')]);
        }
        self::assertNotNull($r['replan_error']);
        self::rm()->replan(self::system(), null, 3650);
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($a), 'M2811\'s covered row goes through (over its 2 labels), never held for the 10th');
        self::assertSame(['held||not_covered' => 930], self::where($b), 'only the row of the 10th waits');
        $this->report([], '2026-11-10', '2026-11-10');
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($b), 'covered: both rows on M2811\'s 2 labels by weight');
        // X gets a label on another manifest (16th): the row naming only X is on no single manifest now; M2811 is planned again without it
        self::order($this->ef, '3313', '9313', ['AS000003313GB' => ['2026-11-16T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000003313GB', '2026-11-16', '9313', 2812, '5155503319')], '2026-11-16', '2026-11-16');
        self::assertSame(["shop|{$this->vpg}|" => 930], self::where($b));
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($a));
        $this->assertRebuilds();
    }

    /** H4: a parcel of carrier manual with a Royal Mail number is compared for its service whatever its manual flag says. */
    public function testH4AManualCarrierParcelWithoutTheFlagIsComparedToo(): void
    {
        self::order($this->ef, '3321', '9321', ['JV000003321GB' => ['2026-11-12T10:00:00Z', 'TPM24HV', 'manual', false]]);
        $this->report([RmFixtures::label('JV000003321GB', 'TPL48HV', '2026-11-12', '9321')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(["shop|{$this->vpg}|" => 254], self::where($id), 'its service differs: no price, the unit on Vape and Go\'s line');
        self::assertSame([['JV000003321GB', 'service_differs']], array_map(static fn (array $i): array => [$i['tracking_no'], $i['why']],
            $this->look(['2026-11-12'], 'odd_service')));
    }

    /** H5: the CW parcels whose service left a label out are held by the write's guards: a correction never commits between the check and the Store. */
    public function testH5AServiceCorrectionNeverSlipsInBeforeTheStoreCommits(): void
    {
        self::order($this->ef, '3331', '9333', ['JV000003331GB' => ['2026-11-12T10:00:00Z', 'TPM24HV']]);
        $this->report([RmFixtures::label('JV000003331GB', 'TPL48HV', '2026-11-12', '9333')], '2026-11-12', '2026-11-12');
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        $ran = false;
        $ef = $this->ef;
        \CW\Tests\Support\HookedStatement::$before = ["UPDATE carrier_invoice SET status = 'live'" => static function () use (&$ran, $ef): void {
            $ran = true;
            try {
                // Electrofag's snapshot corrects the parcel's service while the Store holds its guards
                self::order($ef, '3331', '9333', ['JV000003331GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']], 2);
            } catch (\Throwable) {
                // held by the Store's guard: it tries again later
            }
        }];
        $id = (int) (new RoyalMailBills($conn))->upload(self::system(), $this->acct['account'], RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]))['invoice_id'];
        \CW\Tests\Support\HookedStatement::$before = [];
        self::assertTrue($ran);
        $service = (string) self::$db->value("SELECT service_code FROM sale_parcel WHERE tracking_no = 'JV000003331GB'");
        self::assertSame($service === 'TPL48HV' ? ["order|{$this->ef}|" => 254] : ["shop|{$this->vpg}|" => 254], self::where($id),
            "what the Store committed agrees with the parcel's service at its commit ({$service})");
    }

    /** H6: the days about to leave the nightly's window are made due before it plans them: a nightly that dies never strands a repricing. */
    public function testH6ADayAboutToAgeOutIsDueBeforeTheNightlyPlansIt(): void
    {
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-24 12:00:00', new \DateTimeZone('UTC'));
        self::order($this->ef, '3341', '9341', ['JV000003341GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000003341GB', 'TPL48HV', '2026-11-12', '9341')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(["order|{$this->ef}|" => 254], self::where($id));
        // the parcel's service corrected in CW; the nightly's last run with the 12th in its days finds its order busy, and dies before it says so
        self::order($this->ef, '3341', '9341', ['JV000003341GB' => ['2026-11-12T10:00:00Z', 'TPM24HV']], 2);
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->ef, '3341')]));
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['INSERT INTO carrier_replan_due' => static fn () => throw new \RuntimeException('the nightly died')];
        $died = false;
        try {
            (new RoyalMailBills($conn, $clock))->replan(self::system(), null, 12);
        } catch (\RuntimeException) {
            $died = true;
        } finally {
            \CW\Tests\Support\HookedStatement::$before = [];
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->ef, '3341')]);
        }
        self::assertTrue($died);
        // the next nightly's days no longer reach the 12th
        (new RoyalMailBills(self::$db, $clock))->replan(self::system(), null, 0);
        self::assertSame(["shop|{$this->vpg}|" => 254], self::where($id), 'repriced all the same: the 12th was due');
    }

    /** M7: a manifest's alarm counts every live row of it, a row naming only its sales order on another day too. */
    public function testM7TheManifestAlarmCountsEveryRowOfIt(): void
    {
        self::order($this->vpg, '3351', '1393351', ['AS000003351GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000003351GB', '2026-11-12', '1393351', 2851, '5155503351')], '2026-11-11', '2026-11-12');
        $this->bill([RmBill::line('2026-11-11', 'SD1', 1, 930, '', '5155503351')], '9070000351');
        $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2851', '5155503351')], '9070000352');
        self::assertSame([['manifest', 2851, 2, 1]], array_map(static fn (array $i): array => [$i['kind'], $i['manifest'], $i['billed'], $i['labels']],
            array_values(array_filter($this->look(['2026-11-12']), static fn (array $i): bool => $i['red']))));
    }

    /** M8: the money of no label is listed by the labels the plan could use; a manifest with no label is listed whatever its units (a credit too). */
    public function testM8TheFallbacksAreListedByTheUsableLabels(): void
    {
        self::order($this->vpg, '3361', '1393361', ['AS000003361GB' => ['2026-11-12T10:00:00Z', 'TPM24HV']]);
        $this->report([self::sd('AS000003361GB', '2026-11-12', '1393361', 2861, '5155503361')], '2026-11-12', '2026-11-13');
        $id = $this->bill([RmBill::line('2026-11-13', 'RX1', 1, 275, 'ADJ5155503361RCN Surcharge'), RmBill::line('2026-11-12', 'SD1', -1, -930, 'CLICK AND DROP M2862')]);
        self::assertSame(["shop|{$this->vpg}|" => 275 - 930], self::where($id));
        $fallbacks = array_map(static fn (array $i): array => [$i['kind'], $i['manifest'] ?? null, $i['sales_order'] ?? null],
            array_values(array_filter($this->look(['2026-11-12', '2026-11-13']), static fn (array $i): bool => in_array($i['kind'], ['no_labels', 'manifest'], true))));
        self::assertSame([['manifest', 2862, null], ['no_labels', null, '5155503361']], $fallbacks);
    }

    /** M9: an account whose check failed keeps its open alerts (never "back to normal" because it could not be read). */
    public function testM9AFailedCheckNeverClearsTheAccountsOpenAlerts(): void
    {
        $sent = new class implements AlertTransport {
            public function webhook(string $url, array $payload, int $timeoutSec): void
            {
            }

            public function email(string $to, string $subject, string $body): void
            {
            }
        };
        $more = ['key' => 'rm_billed_more:' . RmFixtures::ACCOUNT, 'kind' => 'rm_billed_more', 'channel' => null, 'channel_id' => null, 'name' => null, 'mode' => null,
            'detail' => 'x', 'data' => ['account' => RmFixtures::ACCOUNT, 'count' => 1, 'oldest' => '2026-11-12', 'pct' => '1']];
        $failed = ['key' => 'rm_check_failed:' . RmFixtures::ACCOUNT, 'kind' => 'rm_check_failed', 'channel' => null, 'channel_id' => null, 'name' => null, 'mode' => null,
            'detail' => 'x', 'data' => ['account' => RmFixtures::ACCOUNT, 'error' => 'database error 2006']];
        (new AlertDispatch(self::$db, $sent))->run([$more], new Settings(self::$db), 'cw_test');
        $r = (new AlertDispatch(self::$db, $sent))->run([$failed], new Settings(self::$db), 'cw_test');
        self::assertNotContains('rm_billed_more:' . RmFixtures::ACCOUNT, $r['cleared'], 'not read is not back to normal');
        $r = (new AlertDispatch(self::$db, $sent))->run([], new Settings(self::$db), 'cw_test');
        self::assertContains('rm_billed_more:' . RmFixtures::ACCOUNT, $r['cleared'], 'a check that reads it clean clears it');
    }

    /** L10: the bill's day table compares the units billed with the labels priced (an odd label is no label of the day's line). */
    public function testL10TheDaysLineComparesBilledWithThePricedLabels(): void
    {
        foreach (['3371' => 'TPL48HV', '3372' => 'TPM24HV', '3373' => 'TPM24HV'] as $ref => $service) {
            self::order($this->vpg, (string) $ref, '139' . $ref, ["JV00000{$ref}GB" => ['2026-11-12T10:00:00Z', $service]]);
        }
        $this->report([RmFixtures::label('JV000003371GB', 'TPL48HV', '2026-11-12', '1393371'), RmFixtures::label('JV000003372GB', 'TPL48HV', '2026-11-12', '1393372'),
            RmFixtures::label('JV000003373GB', 'TPL48HV', '2026-11-12', '1393373')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 2, 508, '943')]);
        self::assertSame(self::ks(["order|{$this->vpg}|" => 254, "shop|{$this->vpg}|" => 254]), self::where($id));
        $day = (new RoyalMailViews(self::$db))->bill($id)['days'][0];
        self::assertSame([2, 1, -1], [$day['billed'], $day['priced'], $day['difference']], '1 billed with no label, never "1 label not charged"');
    }
}
