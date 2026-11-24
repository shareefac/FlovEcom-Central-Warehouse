<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Caller;
use CW\Expenses\ExpenseInvariants;
use CW\Expenses\Expenses;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCosts;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex check r2 of 9b25afe (cw_work/runs/expenses_codex_r2.txt; docs/decisions.md EX28-), each shown failing first on 9b25afe: two
 * branches of one cost's new amounts never count on one day (H1); the same regular cost twice (a form sent twice, the seed and a person at once)
 * is refused unless confirmed (H2, H4); the seed is Vape and Go's only (H3); the nightly checks compare who deleted a row and when (M11).
 */
final class ExpensesCodexR2Test extends MappingTestCase
{
    private int $vpg = 0;
    private int $ef = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
    }

    private static function rent(): int
    {
        return (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'regular' AND name = 'Rent'");
    }

    /** H1: £70 from 1 Nov; £98 from 15 Nov; then £84 from 8 Nov on the first line again; the £84 line's end taken off: refused (the £98 line follows). */
    public function testTwoBranchesOfOneCostNeverCountOnOneDay(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l1 = $rc->add($job, ['name' => 'Unit 8 rent', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week', 'amount' => '70.00', 'from' => '2026-11-01']);
        $rc->newAmount($job, $l1, 1, '98.00', '2026-11-15');
        $l2 = $rc->newAmount($job, $l1, 2, '84.00', '2026-11-08');
        self::assertSame('2026-11-14', self::$db->value('SELECT until_day FROM regular_cost WHERE id = ?', [$l2]));
        self::refused(422, 'overlaps', fn () => $rc->change($job, $l2, 1, ['name' => 'Unit 8 rent', 'type' => self::rent(), 'until' => '', 'note' => '']));
        self::refused(422, 'overlaps', fn () => $rc->change($job, $l2, 1, ['name' => 'Unit 8 rent', 'type' => self::rent(), 'until' => '2026-11-20', 'note' => '']));
        self::assertSame(9800, (new ExpenseViews(self::$db))->regularBy('2026-11-15', '2026-11-21', null)[$this->vpg], '£98.00 for 15-21 Nov, never £182.00');
    }

    /** H2, H4: the same name in the same shop on the same days again is refused unless confirmed; another shop or days after its end are not the same. */
    public function testTheSameRegularCostTwiceIsRefusedUnlessConfirmed(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $form = ['name' => 'Van lease', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week', 'amount' => '152.00', 'from' => '2026-11-01', 'until' => '2027-01-31'];
        $first = $rc->add($job, $form);
        $e = self::refused(409, 'same_cost', fn () => $rc->add($job, $form));
        self::assertSame($first, $e->detail['other'] ?? null);
        self::refused(409, 'same_cost', fn () => $rc->add($job, ['name' => ' van  LEASE ', 'from' => '2027-01-01', 'until' => ''] + $form), 'any case; days that meet');
        $rc->add($job, ['shop' => $this->ef] + $form);
        $rc->add($job, ['from' => '2027-02-01', 'until' => ''] + $form);
        self::assertSame(15200, (new ExpenseViews(self::$db))->regularBy('2026-11-01', '2026-11-07', $this->vpg)[$this->vpg], 'one charge of £152.00, never £304.00');
        $rc->add($job, $form, true);
        self::assertSame(30400, (new ExpenseViews(self::$db))->regularBy('2026-11-01', '2026-11-07', $this->vpg)[$this->vpg], 'confirmed: a second one');
    }

    /** H3: the seed has no --shop: the owner's list is Vape and Go's. */
    public function testTheSeedIsVapeAndGosOnly(): void
    {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . '/bin/seed_regular_costs.php', '--db=' . TestDb::name(), '--admin', '--apply',
            '--shop=electrofag'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(2, proc_close($p), $out);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
    }

    /** M11: who deleted a row and when are in its history and compared every night. */
    public function testTheNightlyChecksCompareWhoDeletedARowAndWhen(): void
    {
        $job = $this->staffUser('expenses');
        $type = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $r = (new Expenses(self::$db))->add($job, ['shop' => $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => true, 'type' => $type,
            'invoice_date' => '2026-11-12', 'invoice_no' => 'V2', 'net' => '180.00', 'vat' => '36.00', 'note' => '']);
        (new Expenses(self::$db))->delete($job, $r['id'], 1, 'Entered twice');
        $rc = (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Bin', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week', 'amount' => '177.00',
            'from' => '2026-11-01']);
        (new RegularCosts(self::$db))->delete(Caller::system('test'), $rc, 1, 'Entered twice');
        self::assertSame([], ExpenseInvariants::check(self::$db));
        $at = (string) self::$db->value('SELECT deleted_at FROM expense WHERE id = ?', [$r['id']]);
        self::$db->exec("UPDATE expense SET deleted_actor = 'staff:999999', deleted_at = '2026-11-24 15:00:00' WHERE id = ?", [$r['id']]);
        $a = ExpenseInvariants::check(self::$db);
        self::$db->exec('UPDATE expense SET deleted_actor = ?, deleted_at = ? WHERE id = ?', [$job->actor, $at, $r['id']]);
        self::assertSame(["EX-I2 expense {$r['id']}: the row is not what its latest history version says (changed around CW)"], $a);
        $at = (string) self::$db->value('SELECT deleted_at FROM regular_cost WHERE id = ?', [$rc]);
        self::$db->exec("UPDATE regular_cost SET deleted_at = '2026-11-24 15:00:00' WHERE id = ?", [$rc]);
        $b = ExpenseInvariants::check(self::$db);
        self::$db->exec('UPDATE regular_cost SET deleted_at = ? WHERE id = ?', [$at, $rc]);
        self::assertSame(["EX-I2 regular_cost {$rc}: the row is not what its latest history version says (changed around CW)"], $b);
        self::assertSame([], ExpenseInvariants::check(self::$db));
    }
}
