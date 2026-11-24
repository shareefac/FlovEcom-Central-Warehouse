<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Profit\RoyalMailLook;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex check r12 of b3295d3 against DL86 and DL87 (cw_work/runs/rmbills_codex_r12.txt: NO GO, 4 HIGH, 2 MEDIUM, 1 LOW; the last review
 * round), the findings fixed here, SYNTHETIC labels and bills, each shown failing first (runs/rmbills_r13_red.log; docs/decisions.md DL88).
 */
final class RoyalMailCodexR12Test extends StockTestCase
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
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed', 'placed_at' => '2026-09-01T09:00:00Z',
            'paid_at' => '2026-09-01T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
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

    /** F2: a busy order of a manifest with no row (its parcel's service left a label out) never stops a newly covered manifest from being priced. */
    public function testF2ABusyOrderOfAManifestWithNoRowNeverHoldsAnother(): void
    {
        self::order($this->vpg, '3401', '1393401', ['AS000003401GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->ef, '3403', '9343', ['AS000003403GB' => ['2026-11-12T12:00:00Z', 'TPM24HV']]);
        // X on M2901 (12th) and M2902 (13th); Y's label on M2902 differs from its CW parcel
        $this->report([self::sd('AS000003402GB', '2026-11-13', '1393402', 2902, '5155503401'), self::sd('AS000003403GB', '2026-11-13', '9343', 2902, '5155503403')],
            '2026-11-13', '2026-11-13');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2901', '5155503401'), RmBill::line('2026-11-13', 'SD1', 1, 1004, '', '5155503401')]);
        self::assertSame(['held||not_covered' => 930, "shop|{$this->vpg}|" => 1004], self::where($id), 'M2901\'s row waits for the 12th; the row of X alone has no single manifest');
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->ef, '3403')]));
        try {
            $r = $this->report([self::sd('AS000003401GB', '2026-11-12', '1393401', 2901, '5155503401')], '2026-11-12', '2026-11-12');
        } finally {
            $lock->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($this->ef, '3403')]);
        }
        self::assertNull($r['replan_error'], 'nothing of Electrofag\'s busy order is needed');
        self::assertSame(["order|{$this->vpg}|" => 930, "shop|{$this->vpg}|" => 1004], self::where($id));
        $this->assertRebuilds();
    }

    /** F3: a CW parcel outside the matching window (an old one of the same number) never makes a label's service differ. */
    public function testF3AnOldParcelOutsideTheWindowNeverMakesALabelOdd(): void
    {
        self::order($this->ef, '3411', '9411', ['JV000003411GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '3412', '9412', ['JV000003411GB' => ['2026-09-10T10:00:00Z', 'TPM24HV']]);
        $this->report([RmFixtures::label('JV000003411GB', 'TPL48HV', '2026-11-12', '9411')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(["order|{$this->ef}|" => 254], self::where($id), 'the September parcel is no parcel of this label (as its destination says)');
        self::assertSame([], array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']),
            static fn (array $i): bool => $i['kind'] === 'odd_service')));
    }

    /** F5: an account whose check fails is named by the number it was read with, so its open alerts are kept. */
    public function testF5AFailedCheckIsNamedByItsAccountNumber(): void
    {
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['SELECT COUNT(DISTINCT day) FROM carrier_day_cover' => static fn () => throw new \PDOException('lost'),
            'SELECT account_no FROM carrier_account WHERE id = ?' => static fn () => throw new \PDOException('lost')];
        $alerts = RoyalMailBills::health($conn, new \DateTimeImmutable('2026-11-24 13:00:00', new \DateTimeZone('Europe/London')));
        self::assertContains('rm_check_failed:' . RmFixtures::ACCOUNT, array_column($alerts, 'key'));
    }

    /** F6: a manifest of the bill's days lists the odd labels of it on other days, and is listed while its row waits too. */
    public function testF6AManifestsOddLabelsOnOtherDaysAreListed(): void
    {
        self::order($this->vpg, '3421', '1393421', ['AS000003421GB' => ['2026-11-11T10:00:00Z', 'SD1']]);
        self::order($this->vpg, '3422', '1393422', ['AS000003422GB' => ['2026-11-11T11:00:00Z', 'TPM24HV']]);
        $this->report([self::sd('AS000003421GB', '2026-11-11', '1393421', 2921, '5155503421'), self::sd('AS000003422GB', '2026-11-11', '1393422', 2921, '5155503421')],
            '2026-11-11', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 2, 1860, 'CLICK AND DROP M2921', '5155503421')]);
        self::assertSame(["order|{$this->vpg}|" => 1860], self::where($id));
        $look = array_map(static fn (array $i): array => [$i['kind'], $i['tracking_no'] ?? $i['manifest'] ?? null], RoyalMailLook::forDays(self::$db, $this->acct['account'],
            ['2026-11-12']));
        self::assertSame([['odd_service', 'AS000003422GB'], ['manifest', 2921]], $look, 'its odd label of the 11th, and the manifest billed 2 for its 1 usable label');
    }

    /** F7: a credit naming only a sales order of no manifest is listed, not red. */
    public function testF7ACreditOfNoManifestIsNotRed(): void
    {
        $this->report([], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', -1, -930, '', '5155503431'), RmBill::line('2026-11-12', 'RW4', 2, 2000, 'Rectification Week 46')]);
        self::assertSame(["shop|{$this->vpg}|" => 2000 - 930], self::where($id));
        self::assertSame([['no_labels', false]], array_map(static fn (array $i): array => [$i['kind'], $i['red']], RoyalMailLook::forDays(self::$db, $this->acct['account'],
            ['2026-11-12'])));
    }

    /** F3 (the list too): a manifest's label with an old parcel of its number outside the window is a usable label of the list's count. */
    public function testF3TheListCountsAManifestsLabelAsThePlanDoes(): void
    {
        self::order($this->vpg, '3441', '1393441', ['AS000003441GB' => ['2026-11-12T10:00:00Z', 'SD1']]);
        self::order($this->vpg, '3442', '1393442', ['AS000003441GB' => ['2026-09-10T10:00:00Z', 'TPM24HV']]);
        $this->report([self::sd('AS000003441GB', '2026-11-12', '1393441', 2941, '5155503441')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'SD1', 1, 930, 'CLICK AND DROP M2941', '5155503441')]);
        self::assertSame(["order|{$this->vpg}|" => 930], self::where($id));
        self::assertSame([], RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']), 'its one label is usable: 1 billed, 1 label, nothing to look at');
    }
}
