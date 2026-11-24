<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Profit\ClickAndDropReport;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBillPage;
use CW\Profit\RoyalMailBills;
use CW\Profit\RoyalMailLook;
use CW\Profit\XlsFile;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use CW\Tests\Support\XlsWriter;

/**
 * The final reviews of 1e209e6 / 738ad6f (cw_work/runs/rmbills_final_astra_report.txt: Codex astra NO GO, 11 HIGH, 12 MEDIUM; the round-12
 * brief rmbills_r12_brief.txt F1-F12 and the app side of K2), SYNTHETIC labels and bills, each shown failing first (runs/rmbills_r12_red.log;
 * docs/decisions.md DL89, DL90).
 */
final class RoyalMailFinalReviewTest extends StockTestCase
{
    private const START = '2026-10-28 10:28:00.000000';
    private const RUN = 'rmundo-test-0001';
    /** RoyalMailBills::HOLD_SETTING (named here: the red run of the old code has no such constant) */
    private const HOLD = 'delivery.rm_writes_held';

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
        HookedStatement::$before = [];
        self::$db->exec("UPDATE app_setting SET value_json = '\"\"' WHERE setting_key = ?", [self::HOLD]);
        parent::tearDown();
    }

    private static function system(): Caller
    {
        return Caller::system('rm_test');
    }

    private static function rm(?\CW\Db $db = null): RoyalMailBills
    {
        return new RoyalMailBills($db ?? self::$db);
    }

    /** A connection whose statements can be hooked (HookedStatement::$before). */
    private static function hooked(): \CW\Db
    {
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        return $conn;
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

    private function bill(array $lines, string $invoice = '9070000001', string $date = 'Nov 16, 2026', ?RoyalMailBills $rm = null): int
    {
        return (int) ($rm ?? self::rm())->upload(self::system(), $this->acct['account'], RmBill::page($lines, ['invoice' => $invoice, 'date' => $date]))['invoice_id'];
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

    /** The orders' allocations are never negative (a credit never makes an order's cost negative). */
    private static function assertNoNegativeOrder(): void
    {
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM carrier_allocation WHERE ended_at IS NULL AND dest = 'order' AND pence < 0"));
    }

    /** F1: a first bill holding only a credit (quantity 1, net -2.54) sets no Tracked price: the next bill's charge does; the credit is on the day line. */
    public function testF1ACreditNeverSetsATrackedPrice(): void
    {
        self::order($this->vpg, '5101', '1395101', ['JV000005101GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '5102', '1395102', ['JV000005102GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005101GB', 'TPL48HV', '2026-11-12', '1395101'), RmFixtures::label('JV000005102GB', 'TPL48HV', '2026-11-12', '1395102')],
            '2026-11-12', '2026-11-12');
        $credit = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, -254, '943')], '9070000001', 'Nov 13, 2026');
        $charge = $this->bill([RmBill::line('2026-11-12', 'TPL', 2, 508, '943')], '9070000002', 'Nov 16, 2026');
        self::assertSame(["order|{$this->vpg}|" => 508], self::where($charge), 'the charge sets the price: 2.54 on each order');
        self::assertSame(["shop|{$this->vpg}|" => -254], self::where($credit), 'the credit is on the day line, never a price');
        self::assertNoNegativeOrder();
        $this->assertRebuilds();
    }

    /** F1: a credit row of the price-setting bill (quantity 1, net -2.54) is no billed unit: the day is not "billed more", its line is split by holders. */
    public function testF1ACreditIsNoBilledUnit(): void
    {
        self::order($this->vpg, '5111', '1395111', ['JV000005111GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->ef, '5112', '9512', ['JV000005112GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005111GB', 'TPL48HV', '2026-11-12', '1395111'), RmFixtures::label('JV000005112GB', 'TPL48HV', '2026-11-12', '9512')],
            '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 2, 508, '943'), RmBill::line('2026-11-12', 'TPL', 1, -254, '944')]);
        self::assertSame(["order|{$this->ef}|" => 254, "order|{$this->vpg}|" => 254, "shop|{$this->ef}|" => -127, "shop|{$this->vpg}|" => -127], self::where($id),
            'each order 2.54 (the charge\'s price); the credit split by the holders, not the main shop\'s "billed more" line');
        self::assertNoNegativeOrder();
        self::assertSame([], array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-12']),
            static fn (array $i): bool => $i['kind'] === 'billed_more')), '2 charged, 2 labels: not billed more');
    }

    /** F2: pasted page text whose two Special Delivery lines have hyphen dates is refused whole, never read as the one Tracked line left. */
    public function testF2APastedBillWithLinesItCannotReadIsRefusedWhole(): void
    {
        $lines = [RmBill::line('2026-11-04', 'TPL', 1, 254, '943'), RmBill::line('2026-11-05', 'SD1', 1, 930, 'CLICK AND DROP M2281'),
            RmBill::line('2026-11-05', 'SD1', -1, -930, 'CLICK AND DROP M2282')];
        $text = str_replace('05:11:2026', '05-11-2026', RmBill::spaced($lines));
        try {
            RoyalMailBillPage::parse($text, RmBill::PRODUCTS);
            self::fail('a bill with lines it cannot read is refused');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
            self::assertStringNotContainsString('9.30', $e->getMessage(), 'a refusal names the row, never a cell');
        }
        // a money amount inside a cell of a row that is no line (tab-split text) too
        $tabs = str_replace("05:11:2026\tSD1", "05-11-2026\tSD1", RmBill::text($lines));
        $this->expectException(CwException::class);
        RoyalMailBillPage::parse($tabs, RmBill::PRODUCTS);
    }

    /** F3 (DL89, the owner's DL86 applied to Special Delivery): 3 units of 9.30 billed for 2 usable labels: 9.30 on each order, 9.30 on Vape and Go's line. */
    public function testF3SpecialDeliveryExtraUnitsGoToTheMainShop(): void
    {
        self::order($this->vpg, '5121', '1395121', ['AS000005121GB' => ['2026-11-11T10:00:00Z', 'SD1']]);
        self::order($this->ef, '5122', '9522', ['AS000005122GB' => ['2026-11-11T11:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000005121GB', '2026-11-11', '1395121', 2951, '5155505121'), self::sd('AS000005122GB', '2026-11-11', '9522', 2951, '5155505121')],
            '2026-11-11', '2026-11-11');
        $id = $this->bill([RmBill::line('2026-11-11', 'SD1', 3, 2790, 'CLICK AND DROP M2951', '5155505121')]);
        self::assertSame(["order|{$this->ef}|" => 930, "order|{$this->vpg}|" => 930, "shop|{$this->vpg}|" => 930], self::where($id));
        $look = array_values(array_filter(RoyalMailLook::forDays(self::$db, $this->acct['account'], ['2026-11-11']), static fn (array $i): bool => $i['kind'] === 'manifest'));
        self::assertSame([[2951, true]], array_map(static fn (array $i): array => [$i['manifest'], $i['red']], $look), 'over the 1%: red');
        $this->assertRebuilds();
    }

    /** F3: a credit on a manifest never lowers its labels below one unit's price: it is the main shop's line (DL90). */
    public function testF3ACreditOfAManifestIsTheMainShops(): void
    {
        self::order($this->vpg, '5131', '1395131', ['AS000005131GB' => ['2026-11-11T10:00:00Z', 'SD1']]);
        self::order($this->ef, '5132', '9532', ['AS000005132GB' => ['2026-11-11T11:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000005131GB', '2026-11-11', '1395131', 2961, '5155505131'), self::sd('AS000005132GB', '2026-11-11', '9532', 2961, '5155505131')],
            '2026-11-11', '2026-11-11');
        $id = $this->bill([RmBill::line('2026-11-11', 'SD1', 2, 1860, 'CLICK AND DROP M2961', '5155505131'),
            RmBill::line('2026-11-11', 'SD1', -1, -930, 'CLICK AND DROP M2961', '5155505131')]);
        self::assertSame(["order|{$this->ef}|" => 930, "order|{$this->vpg}|" => 930, "shop|{$this->vpg}|" => -930], self::where($id));
        self::assertNoNegativeOrder();
        $this->assertRebuilds();
    }

    /** F4: every substantive row's channel is checked before rows are skipped: a foreign report whose tracking numbers are all unusable is refused. */
    public function testF4AForeignReportIsRefusedBeforeRowsAreSkipped(): void
    {
        $foreign = RmFixtures::reportFile([RmFixtures::label('XX12', 'TPL48HV', '2026-11-12', '943', ['channel_code' => 'other'])]);
        try {
            self::rm()->importReport(self::system(), $this->acct['account'], $foreign, '2026-11-12', '2026-11-12');
            self::fail('a report of another account is refused');
        } catch (CwException $e) {
            self::assertSame('other_account', $e->errorCode);
        }
        // the account's own channel, but no row with a usable tracking number: no labels, no cover
        $empty = RmFixtures::reportFile([RmFixtures::label('XX13', 'TPL48HV', '2026-11-12', '943')]);
        try {
            self::rm()->importReport(self::system(), $this->acct['account'], $empty, '2026-11-12', '2026-11-12');
            self::fail('a report whose rows are all skipped covers nothing');
        } catch (CwException $e) {
            self::assertSame('no_labels', $e->errorCode);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_day_cover'));
    }

    /** F5: a workbook with a second data worksheet is refused (only one worksheet is read and checked). */
    public function testF5ASecondWorksheetIsRefused(): void
    {
        $label = RmFixtures::label('JV000005141GB', 'TPL48HV', '2026-11-12', '1395141');
        $one = RmFixtures::reportFile([$label]);
        self::assertSame(1, count(ClickAndDropReport::read($one, array_flip(RmFixtures::CHANNELS))['labels']));
        $two = XlsWriter::file([['Order number'], [1.0]], ['second_sheet' => [['Order number'], [2.0]]]);
        $this->expectException(CwException::class);
        XlsFile::firstSheet($two, static function (): void {
        });
    }

    /** F6: a reference given but not usable (EXT-123) is kept as a flag only, and such a label is never put on an order by its tracking number alone. */
    public function testF6AnUnusableReferenceNeverMatchesByTrackingAlone(): void
    {
        self::order($this->vpg, '5151', '1395151', ['JV000005151GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $file = RmFixtures::reportFile([RmFixtures::label('JV000005151GB', 'TPL48HV', '2026-11-12', 'EXT-123')]);
        self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-12', '2026-11-12');
        self::assertSame([[null, 1]], array_map(static fn (array $r): array => [$r['reference'], (int) $r['ref_unusable']],
            self::$db->all('SELECT reference, ref_unusable FROM carrier_label')), 'the raw text is never kept: its flag is');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(['held||ref_unusable' => 254], self::where($id));
        self::assertNull(self::$db->value('SELECT cw_parcel_ref FROM carrier_label'));
    }

    /** F7: a second parcel of the same tracking number stored between the plan and its guards: no first pin, the label is planned again (held). */
    public function testF7AFirstPinIsCheckedUnderTheGuards(): void
    {
        self::order($this->vpg, '5161', '1395161', ['JV000005161GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005161GB', 'TPL48HV', '2026-11-12', null)], '2026-11-12', '2026-11-12');
        $vpg = $this->vpg;
        HookedStatement::$before = ['SELECT GET_LOCK(?, 0) AS g0' => static function () use ($vpg): void {
            self::order($vpg, '5162', '1395162', ['JV000005161GB' => ['2026-11-12T12:00:00Z', 'TPL48HV']]);
        }];
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')], '9070000001', 'Nov 16, 2026', self::rm(self::hooked()));
        self::assertSame([], HookedStatement::$before, 'the second parcel came while the bill was stored');
        self::assertSame(['held||two_parcels' => 254], self::where($id), 'two parcels match at commit: never pinned to the first');
        self::assertNull(self::$db->value('SELECT cw_parcel_ref FROM carrier_label'));
        $this->assertRebuilds();
    }

    /** F8: the nightly makes its edge days due before it reads anything: a run that fails reading its rows leaves them due. */
    public function testF8TheNightlyMarksItsEdgeDaysBeforeReading(): void
    {
        $conn = self::hooked();
        HookedStatement::$before = ['SELECT r.*, i.invoice_date' => static fn () => throw new \PDOException('lost')];
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-24 03:35:00');
        try {
            (new RoyalMailBills($conn, $clock))->replan(self::system(), $this->acct['account']);
        } catch (CwException | \PDOException) {
            // the run failed
        }
        $due = array_map('strval', self::$db->column('SELECT day FROM carrier_replan_due WHERE account_id = ? ORDER BY day', [$this->acct['account']]));
        self::assertCount(RoyalMailBills::EDGE_DAYS, $due, 'the window\'s first days are due although the run died reading');
        self::assertSame('2026-07-27', $due[0]);
    }

    /** F9: a damaged workbook naming one FAT sector over and over is refused before it is expanded. */
    public function testF9ARepeatedFatSectorIsRefused(): void
    {
        $good = RmFixtures::reportFile([RmFixtures::label('JV000005171GB', 'TPL48HV', '2026-11-12', '1395171')]);
        $bad = $good;
        $n = intdiv(strlen($good), 512) - 1;
        // the header's FAT count and its DIFAT entries: every one the first FAT sector
        $first = unpack('V', $good, 76)[1];
        $bad = substr_replace($bad, pack('V', $n), 44, 4);
        for ($i = 0; $i < 109; $i++) {
            $bad = substr_replace($bad, pack('V', $first), 76 + 4 * $i, 4);
        }
        $this->expectException(CwException::class);
        XlsFile::firstSheet($bad, static function (): void {
        });
    }

    /** F10: a correction whose Store fails leaves no link to the bill it was to replace (supersedes_id only in the write that makes it live). */
    public function testF10AFailedReplacementLinksNothing(): void
    {
        self::order($this->vpg, '5181', '1395181', ['JV000005181GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005181GB', 'TPL48HV', '2026-11-12', '1395181')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        $conn = self::hooked();
        HookedStatement::$before = ["UPDATE carrier_invoice SET status = 'superseded'" => static fn () => throw new \PDOException('lost')];
        try {
            self::rm($conn)->replace(self::system(), $id, RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 250, '943')], ['invoice' => '9070000001']));
            self::fail('the replacement failed');
        } catch (\PDOException | CwException) {
            // as the hook made it
        }
        self::assertSame([['live', null], ['undone', null]], array_map(static fn (array $r): array => [(string) $r['status'], $r['supersedes_id']],
            self::$db->all('SELECT status, supersedes_id FROM carrier_invoice ORDER BY id')));
        self::assertSame([], \CW\Invariants::check(self::$db));
    }

    /** F11: a covered day with a CW parcel in no report and no bill yet is on the account's list (Profit > Delivery bills, the alert). */
    public function testF11ACoveredDayWithNoBillIsListed(): void
    {
        self::order($this->vpg, '5191', '1395191', ['JV000005191GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([], '2026-11-12', '2026-11-12');
        $look = RoyalMailLook::recent(self::$db, $this->acct['account'], new \DateTimeImmutable('2026-11-20 12:00:00'));
        self::assertSame([['parcel_missing', '2026-11-12']], array_map(static fn (array $i): array => [$i['kind'], $i['day']], $look));
    }

    /** F12: the same report again (same days) plans what its first import left due (an order busy then) and says what is still due. */
    public function testF12TheSameReportAgainPlansWhatIsStillDue(): void
    {
        self::order($this->vpg, '5201', '1395201', ['JV000005201GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::assertSame(['held||not_covered' => 254], self::where($id));
        $file = RmFixtures::reportFile([RmFixtures::label('JV000005201GB', 'TPL48HV', '2026-11-12', '1395201')]);
        $lock = TestDb::connect();
        self::assertSame(1, (int) $lock->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($this->vpg, '5201')]));
        try {
            $first = self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-12', '2026-11-12');
        } finally {
            $lock->value('SELECT RELEASE_ALL_LOCKS()');
        }
        self::assertNotNull($first['replan_error']);
        self::assertSame(['held||not_covered' => 254], self::where($id));
        $again = self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-12', '2026-11-12');
        self::assertTrue($again['already']);
        self::assertNull($again['replan_error']);
        self::assertSame(0, $again['still_due']);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id));
    }

    /** K2 (DL90): while CW's hold names an undo's run, every Royal Mail write but that run's undo is refused; its clean-up counts what is left. */
    public function testK2TheHoldRefusesEveryWriteButItsOwnUndo(): void
    {
        self::order($this->vpg, '5211', '1395211', ['JV000005211GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005211GB', 'TPL48HV', '2026-11-12', '1395211')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [json_encode(self::RUN), self::HOLD]);
        $refused = [];
        $file = RmFixtures::reportFile([RmFixtures::label('JV000005212GB', 'TPL48HV', '2026-11-13', '1395212')]);
        foreach (['upload' => fn () => $this->bill([RmBill::line('2026-11-13', 'TPL', 1, 254, '943')], '9070000002'),
            'replace' => fn () => self::rm()->replace(self::system(), $id, RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 250, '943')], ['invoice' => '9070000001'])),
            'undo' => fn () => self::rm()->undo(self::system(), $id),
            'report' => fn () => self::rm()->importReport(self::system(), $this->acct['account'], $file, '2026-11-13', '2026-11-13'),
            'nightly' => fn () => self::rm()->replan(self::system())] as $what => $fn) {
            try {
                $fn();
            } catch (CwException $e) {
                $refused[$what] = $e->errorCode;
            }
        }
        self::assertSame(['upload' => 'writes_held', 'replace' => 'writes_held', 'undo' => 'writes_held', 'report' => 'writes_held', 'nightly' => 'writes_held'], $refused);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id), 'nothing moved');
        self::rm()->undo(self::system(), $id, self::RUN);
        self::assertSame(['loading' => 0, 'live' => 0, 'unended' => 0], self::rm()->cleanupForUndo(self::system(), self::RUN));
        $this->expectException(CwException::class);
        self::rm()->cleanupForUndo(self::system(), 'rmundo-other-0002');
    }
}
