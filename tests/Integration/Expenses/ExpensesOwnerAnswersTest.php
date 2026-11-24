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
 * The owner's answers of 24 Nov 2026 (~15:50 UK; docs/decisions.md EX37, EX38): a credit note is an expense ticked "This is a credit note",
 * typed with positive amounts and kept and counted as minus (net and VAT), guarded and audited as an invoice (Q1); a regular cost includes VAT
 * at the shops' rate (profit.vat_rate_pct, 20%) unless it is marked "no VAT": VAT = amount x rate / (100 + rate), to the penny half up, the
 * cost without VAT = amount - VAT, and only the cost without VAT is shared per day and taken off the profit (Q4). The seed's lines:
 * SeedRegularCostsTest; the pages: ExpensesScreensOwnerAnswersTest.
 */
final class ExpensesOwnerAnswersTest extends MappingTestCase
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
        return $over + ['shop' => $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => true, 'type' => self::type('expense', 'Stationery and packaging'),
            'invoice_date' => '2026-11-12', 'invoice_no' => '58812290', 'net' => '64.20', 'vat' => '12.84', 'note' => ''];
    }

    /** Q1: Viking's credit of £10.42 + £2.08 VAT takes £10.42 off Vape and Go's November expenses. */
    public function testACreditNoteIsKeptAndCountedAsMinus(): void
    {
        $job = $this->staffUser('expenses');
        $s = new Expenses(self::$db);
        $s->add($job, $this->form());
        $c = $s->add($job, $this->form(['credit' => true, 'invoice_no' => 'CN-0042', 'net' => '10.42', 'vat' => '2.08', 'invoice_date' => '2026-11-20']));
        self::assertSame([-1042, -208, -1250], array_map('intval', array_values(self::$db->one('SELECT net_pence, vat_pence, total_pence FROM expense WHERE id = ?', [$c['id']]))));
        self::assertSame(['net' => 6420 - 1042, 'vat' => 1284 - 208, 'total' => 7704 - 1250, 'count' => 2],
            (new ExpenseViews(self::$db))->expensesBy('2026-11-01', '2026-11-30', null)[$this->vpg]);
        // Typed as printed, without a minus: a minus typed is refused; a credit of nothing too.
        self::refused(422, 'bad_net', fn () => $s->add($job, $this->form(['credit' => true, 'invoice_no' => 'CN-1', 'net' => '-10.42'])));
        self::refused(422, 'bad_net', fn () => $s->add($job, $this->form(['credit' => true, 'invoice_no' => 'CN-2', 'net' => '0'])));
        // Guarded as an invoice: the same credit note twice asks; an invoice of the same number is not a credit note twice.
        self::refused(409, 'same_invoice', fn () => $s->add($job, $this->form(['credit' => true, 'invoice_no' => 'CN-0042', 'net' => '10.42', 'vat' => '2.08'])));
        $s->add($job, $this->form(['invoice_no' => 'CN-0042', 'net' => '5.00', 'vat' => '1.00']));
        // A change keeps it a credit note (or makes an invoice one), with its history; the state says minus.
        $s->change($job, $c['id'], 1, $this->form(['credit' => true, 'invoice_no' => 'CN-0042', 'net' => '10.50', 'vat' => '2.10', 'invoice_date' => '2026-11-20',
            'new_supplier' => false]));
        self::assertSame(-1050, (int) self::$db->value('SELECT net_pence FROM expense WHERE id = ?', [$c['id']]));
        $s->change($job, $c['id'], 2, $this->form(['credit' => false, 'invoice_no' => 'CN-0042', 'net' => '10.50', 'vat' => '2.10', 'invoice_date' => '2026-11-20',
            'new_supplier' => false]), null, true);
        self::assertSame(1050, (int) self::$db->value('SELECT net_pence FROM expense WHERE id = ?', [$c['id']]));
        self::assertSame(['add', 'change', 'change'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'expense' AND subject_id = ? ORDER BY version", [$c['id']]));
        self::assertSame([], ExpenseInvariants::check(self::$db));
    }

    /** Q4: VAT = amount / 6 at 20%, to the penny half up; the cost without VAT = amount - VAT. */
    public function testTheVatOfARegularCostIsWorkedOutToThePenny(): void
    {
        self::assertSame(3462, RegularCostMath::vat(20769, 2000), 'Unit 1 £207.69: VAT £34.62');
        self::assertSame(17307, 20769 - RegularCostMath::vat(20769, 2000), 'cost without VAT £173.07');
        self::assertSame(46150, RegularCostMath::vat(276900, 2000), 'Novos £2,769.00: VAT £461.50');
        self::assertSame(0, RegularCostMath::vat(20769, 0), 'no VAT');
        $list = [20769, 23077, 92308, 10900, 2640, 3600, 15200, 2000, 13800, 4200, 1300, 17700, 96900, 276900, 207600, 188300];
        self::assertSame(814328, array_sum(array_map(static fn (int $a): int => $a - RegularCostMath::vat($a, 2000), $list)), 'the weekly list without VAT: £8,143.28');
    }

    /** Q4: a line including 20% VAT counts its cost without VAT per day; "no VAT" counts it all; the choice is changed on the line, with its history. */
    public function testARegularCostCountsItsCostWithoutVat(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $rent = self::type('regular', 'Rent');
        $inc = $rc->add($job, ['name' => 'Unit 1 rent', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'inc', 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        self::assertSame(2000, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$inc]), 'the shops\' rate (profit.vat_rate_pct) kept on the line');
        $views = new ExpenseViews(self::$db);
        self::assertSame(74173, $views->regularBy('2026-11-01', '2026-11-30', null)[$this->vpg], 'November: £173.07 x 30 / 7 = £741.73');
        $line = $views->regularLines('2026-11-01', '2026-11-30', null)[0];
        self::assertSame([20769, 3462, 17307, 74173], [$line['amount'], $line['vat'], $line['cost'], $line['share']], 'the share is of the cost without VAT');
        $today = $views->regularList('2026-11-24');
        self::assertSame([20769, 3462, 17307], [$today['groups'][0]['rows'][0]['amount'], $today['groups'][0]['rows'][0]['vat'], $today['groups'][0]['rows'][0]['cost']]);
        self::assertSame(['week' => 20769, 'month' => 0, 'year' => 0], $today['today']);
        self::assertSame(['week' => 17307, 'month' => 0, 'year' => 0], $today['today_cost'], 'what is in force without VAT');
        // Default: includes VAT (the owner: "all of them i gave you is 20% inc vat").
        $def = $rc->add($job, ['name' => 'Unit 2 rent', 'shop' => $this->vpg, 'type' => $rent, 'every' => 'week', 'amount' => '230.77', 'from' => '2026-11-01']);
        self::assertSame(2000, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$def]));
        // Marked "no VAT" on the line: all of it counts, with its history; a new amount keeps the choice.
        self::assertTrue($rc->change($job, $def, 1, ['name' => 'Unit 2 rent', 'type' => $rent, 'vat' => 'none', 'until' => '', 'note' => '']));
        self::assertSame(0, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$def]));
        self::assertSame(74173 + 98901, $views->regularBy('2026-11-01', '2026-11-30', null)[$this->vpg]);
        $next = $rc->newAmount($job, $def, 2, '240.00', '2026-11-15');
        self::assertSame(0, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$next]));
        $n2 = $rc->newAmount($job, $inc, 1, '210.00', '2026-11-15');
        self::assertSame(2000, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$n2]));
        self::refused(422, 'bad_vat_choice', fn () => $rc->add($job, ['name' => 'Xmas lights', 'shop' => $this->vpg, 'type' => $rent, 'vat' => '17.5', 'every' => 'week',
            'amount' => '1.00', 'from' => '2026-11-01']));
        self::assertSame([], ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE regular_cost SET vat_rate_bp = 0 WHERE id = ?', [$inc]);
        $found = ExpenseInvariants::check(self::$db);
        self::$db->exec('UPDATE regular_cost SET vat_rate_bp = 2000 WHERE id = ?', [$inc]);
        self::assertSame(["EX-I2 regular_cost {$inc}: the row is not what its latest history version says (changed around CW)"], $found, 'the VAT choice is checked every night');
    }

    /**
     * Q4: the rate is the CW setting (profit.vat_rate_pct), not a constant; each line keeps the rate it was set with, so a new rate later changes
     * no past day: a new line, a new amount or a line switched from "no VAT" takes the rate of the day it is written.
     */
    public function testTheRateIsTheSettingAndEachLineKeepsItsOwn(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $rent = self::type('regular', 'Rent');
        $old = $rc->add($job, ['name' => 'Unit 1 rent', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'inc', 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01']);
        $none = $rc->add($job, ['name' => 'Bin', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '177.00', 'from' => '2026-11-01']);
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"17.5\"' AS JSON) WHERE setting_key = 'profit.vat_rate_pct'");
        try {
            $new = $rc->add($job, ['name' => 'Unit 2 rent', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'inc', 'every' => 'week', 'amount' => '235.00', 'from' => '2026-11-01']);
            self::assertSame(1750, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$new]));
            self::assertSame(3500, RegularCostMath::vat(23500, 1750), '£235.00 at 17.5%: VAT £35.00');
            // A change of its name keeps its rate; switched to "no VAT" and back, it takes the rate of today.
            $rc->change($job, $old, 1, ['name' => 'Unit 1 rent (Leeds)', 'type' => $rent, 'vat' => 'inc', 'until' => '', 'note' => '']);
            self::assertSame(2000, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$old]));
            $rc->change($job, $none, 1, ['name' => 'Bin', 'type' => $rent, 'vat' => 'inc', 'until' => '', 'note' => '']);
            self::assertSame(1750, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$none]));
            // A change that does not name it keeps it.
            $rc->change($job, $none, 2, ['name' => 'Bin collection', 'type' => $rent, 'until' => '', 'note' => '']);
            self::assertSame(1750, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$none]));
            $next = $rc->newAmount($job, $old, 2, '210.00', '2026-11-15');
            self::assertSame(1750, (int) self::$db->value('SELECT vat_rate_bp FROM regular_cost WHERE id = ?', [$next]), 'a new amount from a later day: today\'s rate');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('\"20\"' AS JSON) WHERE setting_key = 'profit.vat_rate_pct'");
        }
        $h = self::$db->one("SELECT CAST(before_state AS CHAR) AS b, CAST(state AS CHAR) AS a FROM expense_history WHERE subject = 'regular_cost' AND subject_id = ? "
            . 'AND version = 2', [$none]);
        self::assertSame([0, 1750], [json_decode((string) $h['b'], true)['vat_rate_bp'], json_decode((string) $h['a'], true)['vat_rate_bp']], 'kept with before and after');
        self::assertSame([], ExpenseInvariants::check(self::$db));
    }
}
