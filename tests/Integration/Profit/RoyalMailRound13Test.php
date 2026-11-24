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
use CW\Profit\RoyalMailViews;
use CW\Profit\XlsFile;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * Round 13, the last fix round (cw_work/runs/rmbills_r13_brief.txt): the Codex astra re-check of b581e70 (runs/rmbills_recheck_astra_report.txt,
 * NO GO 8 HIGH / 13 MEDIUM) and the Fable re-check's LOW points, SYNTHETIC labels and bills, each shown failing first
 * (runs/rmbills_r13_red.log; docs/decisions.md DL91).
 */
final class RoyalMailRound13Test extends StockTestCase
{
    private const START = '2026-10-28 10:28:00.000000';
    private const RUN = 'rmundo-test-0013';
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

    private static function hooked(): \CW\Db
    {
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        return $conn;
    }

    private static function hold(string $run): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = ? WHERE setting_key = ?', [json_encode($run), self::HOLD]);
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

    private static function person(string $name): Caller
    {
        $p = Caller::staff(self::$db->insert('INSERT INTO staff_user (username, email, display_name, password_hash) VALUES (?, ?, ?, \'x\')', [$name, $name . '@example.com',
            'Test ' . $name]));
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'delivery_bills')", [$p->staffUserId]);
        return $p;
    }

    private static function assertRefused(string $bytes): void
    {
        try {
            RoyalMailBillPage::parse($bytes, RmBill::PRODUCTS);
            self::fail('a bill with a second section of lines is refused whole');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
            self::assertStringNotContainsString('9.30', $e->getMessage(), 'a refusal names the row, never a cell');
        }
    }

    /** H F2: a saved page with a SECOND table of lines (+9.30 / -9.30) is refused whole, never read as its first table's one line. */
    public function testF2ASecondTableOfLinesOnAPageIsRefused(): void
    {
        $a = [RmBill::line('2026-11-04', 'TPL', 1, 254, '943')];
        $b = [RmBill::line('2026-11-05', 'SD1', 1, 930, 'CLICK AND DROP M2281'), RmBill::line('2026-11-05', 'SD1', -1, -930, 'CLICK AND DROP M2282')];
        self::assertSame(1, count(RoyalMailBillPage::parse(RmBill::page($a), RmBill::PRODUCTS)['rows']));
        self::assertSame(1, preg_match('/<table class="lines">.*?<\/table>/s', RmBill::page($b), $m));
        self::assertRefused(str_replace('</body>', $m[0] . "\n</body>", RmBill::page($a)));
    }

    /** H F2: pasted text with a spaced section of lines BEFORE its tabbed one is refused whole. */
    public function testF2ASpacedSectionBeforeTheTabbedOneIsRefused(): void
    {
        $a = [RmBill::line('2026-11-04', 'TPL', 1, 254, '943')];
        $b = [RmBill::line('2026-11-05', 'SD1', 1, 930, 'CLICK AND DROP M2281'), RmBill::line('2026-11-05', 'SD1', -1, -930, 'CLICK AND DROP M2282')];
        $spaced = RmBill::spaced($b);
        $head = implode(' ', RoyalMailBillPage::LINE_HEADINGS);
        $section = substr($spaced, (int) strpos($spaced, $head), (int) strpos($spaced, "\nSurcharge") - (int) strpos($spaced, $head) + 1);
        $tabHead = implode("\t", RoyalMailBillPage::LINE_HEADINGS);
        self::assertRefused(str_replace($tabHead, $section . $tabHead, RmBill::text($a)));
        // and a second spaced heading in a spaced text
        self::assertRefused(str_replace("\nSurcharge", "\n" . $section . "\nSurcharge", RmBill::spaced($a)));
    }

    /** H F1: a credit-only bill of a Tracked day puts NO allocation on an order (not even 0p) and pins nothing: the credit is on the day line. */
    public function testF1ACreditOnlyBillMakesNoOrderAllocationNorPin(): void
    {
        self::order($this->vpg, '5301', '1395301', ['JV000005301GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '5302', '1395302', ['JV000005302GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005301GB', 'TPL48HV', '2026-11-12', '1395301'), RmFixtures::label('JV000005302GB', 'TPL48HV', '2026-11-12', '1395302')],
            '2026-11-12', '2026-11-12');
        $credit = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, -254, '943')], '9070000001', 'Nov 13, 2026');
        self::assertSame(["shop|{$this->vpg}|" => -254], self::where($credit));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM carrier_allocation WHERE dest = 'order'"), 'no 0p allocation on an order');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_label WHERE cw_parcel_ref IS NOT NULL'), 'no first pin from a credit');
        $this->assertRebuilds();
    }

    /** H F6: a Boolean or an Excel error in the reference cell is a reference given but unusable (its flag kept), never "no reference". */
    public function testF6BooleanAndErrorReferenceCellsAreUnusable(): void
    {
        $file = RmFixtures::reportFile([RmFixtures::label('JV000005311GB', 'TPL48HV', '2026-11-12', null, ['reference_cell' => true]),
            RmFixtures::label('JV000005312GB', 'TPL48HV', '2026-11-12', null, ['reference_cell' => ['error' => 7]]),
            RmFixtures::label('JV000005313GB', 'TPL48HV', '2026-11-12', null, ['reference_cell' => ''])]);
        $read = ClickAndDropReport::read($file, array_flip(RmFixtures::CHANNELS))['labels'];
        self::assertSame([[null, true], [null, true], [null, false]], array_map(static fn (array $l): array => [$l['reference'], $l['ref_unusable']], $read),
            'a Boolean and an error cell: unusable; a blank cell: no reference');
    }

    /** H K2: an undo naming a run is refused once the hold is gone (the lead gave the run up); the hold itself moves only under the bills' lock. */
    public function testK2AnUndoOfARunIsRefusedOnceItsHoldIsGone(): void
    {
        self::order($this->vpg, '5321', '1395321', ['JV000005321GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005321GB', 'TPL48HV', '2026-11-12', '1395321')], '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        try {
            self::rm()->undo(self::system(), $id, self::RUN);
            self::fail('no hold: the run\'s undo is refused');
        } catch (CwException $e) {
            self::assertSame('not_held', $e->errorCode);
        }
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id));
        // the generic settings path never moves the hold
        try {
            (new \CW\Settings(self::$db))->set(self::system(), self::HOLD, self::RUN, 'test');
            self::fail('the hold is the kit\'s only');
        } catch (CwException $e) {
            self::assertSame('reserved_setting', $e->errorCode);
        }
        // the kit's own way: set from empty, cleared only by the run that holds it
        self::rm()->setHold(self::system(), null, self::RUN);
        self::assertSame(self::RUN, RoyalMailBills::heldBy(self::$db));
        try {
            self::rm()->setHold(self::system(), 'rmundo-other-0002', null);
            self::fail('another run never clears the hold');
        } catch (CwException $e) {
            self::assertSame('not_held', $e->errorCode);
        }
        self::rm()->setHold(self::system(), self::RUN, null);
        self::assertNull(RoyalMailBills::heldBy(self::$db));
    }

    /** M K2: a Store refused by the hold leaves its check open (pressed again once the hold is gone: stored); a check is refused while held. */
    public function testK2AHeldStoreLeavesTheCheckOpen(): void
    {
        self::order($this->vpg, '5331', '1395331', ['JV000005331GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005331GB', 'TPL48HV', '2026-11-12', '1395331')], '2026-11-12', '2026-11-12');
        $person = self::person('rm13a');
        $page = RmBill::page([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')]);
        $c = self::rm()->check($person, $this->acct['account'], $page);
        self::hold(self::RUN);
        try {
            self::rm()->storeChecked($person, (string) $c['token'], (string) $c['bill_sha256']);
            self::fail('held');
        } catch (CwException $e) {
            self::assertSame('writes_held', $e->errorCode);
        }
        try {
            self::rm()->check($person, $this->acct['account'], $page);
            self::fail('a check is refused while held');
        } catch (CwException $e) {
            self::assertSame('writes_held', $e->errorCode);
        }
        self::hold('');
        $r = self::rm()->storeChecked($person, (string) $c['token'], (string) $c['bill_sha256']);
        self::assertSame('stored', $r['result']);
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($r['invoice_id']));
    }

    /** H H1: a CW parcel that comes for a label planned to the main shop, once the write's guards are held: the plan is made again (on the order). */
    public function testH1AParcelThatComesUnderTheGuardsIsSeen(): void
    {
        $this->report([RmFixtures::label('JV000005341GB', 'TPL48HV', '2026-11-12', '1395341')], '2026-11-12', '2026-11-12');
        $vpg = $this->vpg;
        HookedStatement::$before = ['SELECT 1 FROM profit_day WHERE day' => static function () use ($vpg): void {
            self::order($vpg, '5341', '1395341', ['JV000005341GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        }];
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')], '9070000001', 'Nov 16, 2026', self::rm(self::hooked()));
        self::assertSame([], HookedStatement::$before, 'the parcel came while the guards were held');
        self::assertSame(["order|{$this->vpg}|" => 254], self::where($id), 'never the main shop\'s line with its parcel in CW at commit');
        $this->assertRebuilds();
    }

    /** H F7: a second parcel of the number stored right after the guarded check and before the pin: no pin, the label held (two parcels). */
    public function testF7ASecondParcelBeforeThePinIsHeld(): void
    {
        self::order($this->vpg, '5351', '1395351', ['JV000005351GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005351GB', 'TPL48HV', '2026-11-12', null)], '2026-11-12', '2026-11-12');
        $vpg = $this->vpg;
        HookedStatement::$before = ['SELECT live_at FROM carrier_pin_due' => static function () use ($vpg): void {
            self::order($vpg, '5352', '1395352', ['JV000005351GB' => ['2026-11-12T12:00:00Z', 'TPL48HV']]);
        }];
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 1, 254, '943')], '9070000001', 'Nov 16, 2026', self::rm(self::hooked()));
        self::assertSame([], HookedStatement::$before, 'the second parcel came between the commit and the pin');
        self::assertNull(self::$db->value('SELECT cw_parcel_ref FROM carrier_label'), 'never pinned with two parcels of its number');
        self::assertSame(['held||two_parcels' => 254], self::where($id), 'planned again at once: held, two parcels');
        $this->assertRebuilds();
    }

    /** M F8: a nightly that dies in its first steps (the sweep) has made its edge days due already. */
    public function testF8ANightlyDyingInItsSweepLeavesTheEdgeDaysDue(): void
    {
        $conn = self::hooked();
        HookedStatement::$before = ["UPDATE carrier_invoice SET status = 'undone', ended_actor" => static fn () => throw new \PDOException('lost')];
        $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-24 03:35:00');
        try {
            (new RoyalMailBills($conn, $clock))->replan(self::system());
        } catch (CwException | \PDOException) {
            // the run failed
        }
        self::assertSame([], HookedStatement::$before, 'it died in the sweep');
        $due = array_map('strval', self::$db->column('SELECT day FROM carrier_replan_due WHERE account_id = ? ORDER BY day', [$this->acct['account']]));
        self::assertCount(RoyalMailBills::EDGE_DAYS, $due);
        self::assertSame('2026-07-27', $due[0]);
    }

    /** M F9: a mini FAT longer than the header says (or than the mini stream needs) is refused before it is expanded. */
    public function testF9AMiniFatOverItsBoundIsRefused(): void
    {
        $good = RmFixtures::reportFile([RmFixtures::label('JV000005361GB', 'TPL48HV', '2026-11-12', '1395361')]);
        self::assertSame(1, count(ClickAndDropReport::read($good, array_flip(RmFixtures::CHANNELS))['labels']));
        $bad = substr_replace($good, pack('V', 100000), 64, 4); // the header's mini FAT sector count
        $this->expectException(CwException::class);
        XlsFile::firstSheet($bad, static function (): void {
        });
    }

    /** M F1/F11: billed units are the charges' (quantity > 0 and net > 0) on the check, the bill's page and the list; a credit is no unit. */
    public function testF1F11ACreditIsNoBilledUnitOnTheScreens(): void
    {
        self::order($this->vpg, '5371', '1395371', ['JV000005371GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '5372', '1395372', ['JV000005372GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005371GB', 'TPL48HV', '2026-11-12', '1395371'), RmFixtures::label('JV000005372GB', 'TPL48HV', '2026-11-12', '1395372')],
            '2026-11-12', '2026-11-12');
        $lines = [RmBill::line('2026-11-12', 'TPL', 1, 254, '943'), RmBill::line('2026-11-12', 'TPL', 1, 254, '944'), RmBill::line('2026-11-12', 'TPL', 1, -254, '945')];
        $preview = self::rm()->preview($this->acct['account'], RmBill::page($lines));
        self::assertSame([2], array_column($preview['days'], 'billed'), 'the check: 2 charges');
        $id = $this->bill($lines);
        $view = (new RoyalMailViews(self::$db))->bill($id);
        self::assertSame([2], array_column($view['days'], 'billed'), 'the bill\'s page: 2 charges');
        // a Special Delivery credit naming only a sales order no manifest has: on the list, never red (no billed unit)
        $sd = $this->bill([RmBill::line('2026-11-13', 'SD1', 1, -930, '', '5155509999')], '9070000002', 'Nov 17, 2026');
        $look = array_values(array_filter(RoyalMailLook::forBill(self::$db, $sd), static fn (array $i): bool => $i['kind'] === 'no_labels'));
        self::assertSame([false], array_column($look, 'red'));
    }

    /** Fable L4: a Tracked day billed one more unit than its labels and credited that unit back is not red. */
    public function testL4ADayCorrectedByACreditIsNotRed(): void
    {
        self::order($this->vpg, '5381', '1395381', ['JV000005381GB' => ['2026-11-12T10:00:00Z', 'TPL48HV']]);
        self::order($this->vpg, '5382', '1395382', ['JV000005382GB' => ['2026-11-12T11:00:00Z', 'TPL48HV']]);
        $this->report([RmFixtures::label('JV000005381GB', 'TPL48HV', '2026-11-12', '1395381'), RmFixtures::label('JV000005382GB', 'TPL48HV', '2026-11-12', '1395382')],
            '2026-11-12', '2026-11-12');
        $id = $this->bill([RmBill::line('2026-11-12', 'TPL', 3, 762, '943'), RmBill::line('2026-11-12', 'TPL', 1, -254, '944')]);
        $red = array_values(array_filter(RoyalMailLook::forBill(self::$db, $id), static fn (array $i): bool => $i['red']));
        self::assertSame([], $red, '3 billed, 1 credited back, 2 labels: not red');
    }

    /** M F11: an odd label (no service) of a red Special Delivery manifest is red with its manifest. */
    public function testF11AnOddLabelOfARedManifestIsRed(): void
    {
        self::order($this->vpg, '5391', '1395391', ['AS000005391GB' => ['2026-11-11T10:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000005391GB', '2026-11-11', '1395391', 2991, '5155505391'),
            self::sd('AS000005392GB', '2026-11-10', '1395392', 2991, '5155505391', ['service_code' => null])], '2026-11-10', '2026-11-11');
        $id = $this->bill([RmBill::line('2026-11-11', 'SD1', 3, 2790, 'CLICK AND DROP M2991', '5155505391')]);
        $look = RoyalMailLook::forBill(self::$db, $id);
        $m = array_values(array_filter($look, static fn (array $i): bool => $i['kind'] === 'manifest'));
        self::assertSame([true], array_column($m, 'red'), 'the manifest: 3 billed, 2 labels');
        $odd = array_values(array_filter($look, static fn (array $i): bool => $i['kind'] === 'odd_service'));
        self::assertSame([['AS000005392GB', 2991, true]], array_map(static fn (array $i): array => [$i['tracking_no'], $i['manifest'] ?? null, $i['red']], $odd));
    }

    /** M F11: a Special Delivery credit naming only its sales order stays in its manifest's row of the bill's table (its manifest resolved). */
    public function testF11AResolvedCreditKeepsItsManifestInTheTable(): void
    {
        self::order($this->vpg, '5401', '1395401', ['AS000005401GB' => ['2026-11-11T10:00:00Z', 'SD1']]);
        self::order($this->ef, '5402', '9542', ['AS000005402GB' => ['2026-11-11T11:00:00Z', 'SD1']]);
        $this->report([self::sd('AS000005401GB', '2026-11-11', '1395401', 3001, '5155505401'), self::sd('AS000005402GB', '2026-11-11', '9542', 3001, '5155505401')],
            '2026-11-11', '2026-11-11');
        $id = $this->bill([RmBill::line('2026-11-11', 'SD1', 2, 1860, 'CLICK AND DROP M3001', '5155505401'), RmBill::line('2026-11-11', 'SD1', 1, -930, '', '5155505401')]);
        $view = (new RoyalMailViews(self::$db))->bill($id);
        self::assertSame([['3001', 2, 930]], array_map(static fn (array $m): array => [(string) $m['manifest'], $m['qty'], $m['net']], $view['manifests']),
            'the manifest: 2 units charged, net 18.60 - 9.30');
    }
}
