<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Expenses\ExpenseInvariants;
use CW\Expenses\Expenses;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCosts;
use CW\Tests\Support\MappingTestCase;

/**
 * The Codex check r3 of ca25bfc (cw_work/runs/expenses_codex_r3.txt; docs/decisions.md EX32-), each shown failing first on ca25bfc: an invoice
 * number copied with a non-breaking space is the same number (H2); a new end on a line that meets another line of the same name asks first
 * (H3); a line's "new amount" link is the next one by date (M4); the month list offers a month of deleted invoices when they are shown (M6);
 * who deleted a row (its person) is compared every night (M9); a day in the year 9999 is worked out, never a loop (M11).
 */
final class ExpensesCodexR3Test extends MappingTestCase
{
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
    }

    private static function type(string $kind, string $name): int
    {
        return (int) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    private function form(array $over = []): array
    {
        return $over + ['shop' => $this->vpg, 'supplier' => 'Tabsyst', 'new_supplier' => true, 'type' => self::type('expense', 'Developer'), 'invoice_date' => '2026-11-12',
            'invoice_no' => 'INV-487', 'net' => '480.00', 'vat' => '96.00', 'note' => ''];
    }

    /** H2: "INV-487" again with a non-breaking space, a zero-width space or two spaces inside is the same invoice: refused unless confirmed. */
    public function testAnInvoiceNumberCopiedFromAPdfIsTheSameNumber(): void
    {
        $job = $this->staffUser('expenses');
        $s = new Expenses(self::$db);
        $s->add($job, $this->form());
        foreach (["\u{00A0}INV-487", "INV-487\u{00A0}", "\u{200B}INV-487", "INV-487\u{2009}"] as $copied) {
            self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['invoice_no' => $copied])));
        }
        $s->add($job, $this->form(['invoice_no' => "INV\u{00A0}\u{00A0}488"]));
        self::assertSame('INV 488', self::$db->value("SELECT invoice_no FROM expense WHERE invoice_no LIKE 'INV%488'"), 'kept with plain single spaces');
        self::assertSame(96000, (new ExpenseViews(self::$db))->expensesBy('2026-11-01', '2026-11-30', null)[$this->vpg]['net'], 'two invoices of £480.00, never three');
    }

    /** H3: £140 a week 1-7 Nov and, separately, £175 from 8 Nov: moving the first one's end onto the second's days asks first. */
    public function testANewEndOnALineThatMeetsAnotherOfTheSameNameAsksFirst(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $rent = self::type('regular', 'Rent');
        $a = $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01', 'until' => '2026-11-07']);
        $b = $rc->add($job, ['name' => 'Unit 5', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '175.00', 'from' => '2026-11-08']);
        self::refused(409, 'same_cost', fn () => $rc->change($job, $a, 1, ['name' => 'Unit 5', 'type' => $rent, 'until' => '2026-11-14', 'note' => '']));
        self::assertSame(17500, (new ExpenseViews(self::$db))->regularBy('2026-11-08', '2026-11-14', null)[$this->vpg], '£175.00, never £315.00');
        self::assertTrue($rc->change($job, $a, 1, ['name' => 'Unit 5', 'type' => $rent, 'until' => '2026-11-14', 'note' => ''], (string) $b), 'confirmed: another cost');
    }

    /** M4: £140 from 1 Nov; £196 from 15 Nov; then £168 from 8 Nov on the first line: its "new amount" is the 8 Nov line. */
    public function testALinesNewAmountLinkIsTheNextByDate(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l1 = $rc->add($job, ['name' => 'Unit 6', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '140.00', 'from' => '2026-11-01']);
        $rc->newAmount($job, $l1, 1, '196.00', '2026-11-15');
        $rc->newAmount($job, $l1, 2, '168.00', '2026-11-08');
        self::assertSame('2026-11-08', (string) (new ExpenseViews(self::$db))->regular($l1)['next']['from_day']);
    }

    /** M6: a month whose only invoice is deleted is offered when the deleted ones are shown. */
    public function testTheMonthListOffersAMonthOfDeletedInvoicesWhenTheyAreShown(): void
    {
        $job = $this->staffUser('expenses');
        $s = new Expenses(self::$db);
        $r = $s->add($job, $this->form(['invoice_date' => '2026-12-12']));
        $s->delete($job, $r['id'], 1, 'a test entry');
        $v = new ExpenseViews(self::$db);
        self::assertNotContains('2026-12', $v->months('2026-11-24'));
        self::assertContains('2026-12', $v->months('2026-11-24', true));
    }

    /** M9: the person who deleted a row is compared every night. */
    public function testWhoDeletedARowIsComparedEveryNight(): void
    {
        $job = $this->staffUser('expenses');
        $other = $this->staffUser('expenses');
        $s = new Expenses(self::$db);
        $r = $s->add($job, $this->form());
        $s->delete($job, $r['id'], 1, 'Entered twice');
        $rc = (new RegularCosts(self::$db))->add($job, ['name' => 'Bin', 'shop' => $this->vpg, 'type' => self::type('regular', 'Rent'), 'vat' => 'none', 'every' => 'week', 'amount' => '177.00',
            'from' => '2026-11-01']);
        (new RegularCosts(self::$db))->delete($job, $rc, 1, 'Entered twice');
        self::assertSame([], ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE expense SET deleted_by = ? WHERE id = ?', [$other->staffUserId, $r['id']]);
        self::$db->exec('UPDATE regular_cost SET deleted_by = ? WHERE id = ?', [$other->staffUserId, $rc]);
        $found = ExpenseInvariants::check(self::$db);
        self::$db->exec('UPDATE expense SET deleted_by = ? WHERE id = ?', [$job->staffUserId, $r['id']]);
        self::$db->exec('UPDATE regular_cost SET deleted_by = ? WHERE id = ?', [$job->staffUserId, $rc]);
        self::assertSame(["EX-I2 expense {$r['id']}: the row is not what its latest history version says (changed around CW)",
            "EX-I2 regular_cost {$rc}: the row is not what its latest history version says (changed around CW)"], $found);
    }

    /** M11: the last day of the year 9999 is worked out (no loop past it). */
    public function testTheLastDayOfTheYear9999IsWorkedOut(): void
    {
        $t = microtime(true);
        self::assertSame(1000, RegularCostMath::share('month', 31000, '2026-01-01', null, '9999-12-31', '9999-12-31'));
        self::assertSame(100, RegularCostMath::share('year', 36500, '2026-01-01', null, '9999-12-31', '9999-12-31'));
        self::assertLessThan(10.0, microtime(true) - $t);
    }
}
