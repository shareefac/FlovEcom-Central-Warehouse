<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Caller;
use CW\Expenses\ExpenseInvariants;
use CW\Expenses\Expenses;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCosts;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex check r1 of bb5c0a5 (cw_work/runs/expenses_codex_r1.txt; docs/decisions.md EX21-): each shown failing first on bb5c0a5. Through the
 * services and the server tool: a cost's chain of new amounts never counts two amounts on one day, also through a deleted line (H1); the seed
 * writes all 16 lines or none, and refuses a line of the same name with other figures (H3); the month list offers the months of the regular
 * costs (M8); the audit log keeps no amount, name or note of an expense (only who, when and which fields), the stored file a neutral name (H9);
 * the nightly checks compare a delete's reason and the suppliers' and types' rows too (M14). Every file is made up.
 */
final class ExpensesCodexR1Test extends MappingTestCase
{
    private const PDF = "%PDF-1.4\n% made-up test invoice r1\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private string $dir = '';
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_expenses_r1_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private static function rent(): int
    {
        return (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'regular' AND name = 'Rent'");
    }

    /** H1: £70 a week from 1 Nov, £84 from 8 Nov, £98 from 15 Nov; the £84 line deleted; the £70 line's end taken off: refused. */
    public function testAChainOfNewAmountsNeverCountsTwoAmountsOnOneDayThroughADeletedLine(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $l1 = $rc->add($job, ['name' => 'Unit 9 rent', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week', 'amount' => '70.00', 'from' => '2026-11-01']);
        $l2 = $rc->newAmount($job, $l1, 1, '84.00', '2026-11-08');
        $l3 = $rc->newAmount($job, $l2, 1, '98.00', '2026-11-15');
        $rc->delete($job, $l2, 2, 'the £84 was never charged');
        self::refused(422, 'overlaps', fn () => $rc->change($job, $l1, 2, ['name' => 'Unit 9 rent', 'type' => self::rent(), 'until' => '', 'note' => '']));
        self::refused(422, 'overlaps', fn () => $rc->change($job, $l1, 2, ['name' => 'Unit 9 rent', 'type' => self::rent(), 'until' => '2026-11-15', 'note' => '']));
        $rc->change($job, $l1, 2, ['name' => 'Unit 9 rent', 'type' => self::rent(), 'until' => '2026-11-14', 'note' => '']);
        self::assertSame(9800, (new ExpenseViews(self::$db))->regularBy('2026-11-15', '2026-11-21', null)[$this->vpg], '£98.00 for 15-21 Nov, never £168.00');
        // The nightly check finds two amounts of one chain on one day, also through a deleted line (a change around CW).
        self::$db->exec('UPDATE regular_cost SET until_day = NULL WHERE id = ?', [$l1]);
        $found = ExpenseInvariants::check(self::$db);
        self::$db->exec("UPDATE regular_cost SET until_day = '2026-11-14' WHERE id = ?", [$l1]);
        self::assertContains("EX-I3 regular cost {$l3}: line {$l1} of the same cost counts on some of its days too", $found);
    }

    /** @return array{code: int, out: string} */
    private static function seed(string ...$args): array
    {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . '/bin/seed_regular_costs.php', '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    /** H3: a first day after Reboot's last day refuses the whole list, nothing written; a line of the same name with other figures refuses it too. */
    public function testTheSeedWritesAllOrNothingAndNeverSkipsALineWithOtherFigures(): void
    {
        $late = self::seed('--apply', '--from=2027-03-01');
        self::assertSame(1, $late['code'], $late['out']);
        self::assertStringContainsString('Reboot', $late['out']);
        self::assertStringContainsString('nothing written', $late['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        // A line of the same name with other figures: the whole run refused, named.
        (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Unit 1 rent', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week',
            'amount' => '210.00', 'from' => '2026-11-01']);
        $other = self::seed('--apply');
        self::assertSame(1, $other['code'], $other['out']);
        self::assertStringContainsString('Unit 1 rent', $other['out']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$db->exec("DELETE FROM expense_history WHERE subject = 'regular_cost'");
        self::$db->exec('DELETE FROM regular_cost');
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $ok = self::seed('--apply');
        self::assertSame(0, $ok['code'], $ok['out']);
        self::assertSame(16, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
        self::assertSame(0, self::seed('--apply')['code'], 'the same figures again: already there, nothing added');
        self::assertSame(16, (int) self::$db->value('SELECT COUNT(*) FROM regular_cost'));
    }

    /** M8: the months of the regular costs are offered too (a month with regular costs only). */
    public function testTheMonthListOffersTheMonthsOfTheRegularCosts(): void
    {
        (new RegularCosts(self::$db))->add(Caller::system('test'), ['name' => 'Hub Box', 'shop' => $this->vpg, 'type' => self::rent(), 'every' => 'week',
            'amount' => '109.00', 'from' => '2026-09-01']);
        $months = (new ExpenseViews(self::$db))->months('2026-11-24');
        self::assertSame(['2026-11', '2026-10', '2026-09'], $months);
    }

    /** H9: the audit log says who changed which fields of an expense, never its amounts, names or note; the file is kept under a neutral name. */
    public function testTheAuditLogKeepsNoAmountOfAnExpense(): void
    {
        $job = $this->staffUser('expenses');
        $store = $this->dir . '/store';
        $s = new Expenses(self::$db, static fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($store)));
        $path = $this->dir . '/anthropic-nov-invoice.pdf';
        file_put_contents($path, self::PDF);
        $type = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $r = $s->add($job, ['shop' => $this->vpg, 'supplier' => 'Global Payments', 'new_supplier' => true, 'type' => $type, 'invoice_date' => '2026-11-12',
            'invoice_no' => 'GP-7781', 'net' => '750.00', 'vat' => '150.00', 'note' => 'card fees for October'], ['path' => $path, 'name' => 'anthropic-nov-invoice.pdf']);
        $s->change($job, $r['id'], 1, ['shop' => $this->vpg, 'supplier' => 'Global Payments', 'type' => $type, 'invoice_date' => '2026-11-12', 'invoice_no' => 'GP-7781',
            'net' => '760.00', 'vat' => '152.00', 'note' => 'card fees for October']);
        $s->delete($job, $r['id'], 2, 'entered on the wrong card');
        $details = array_map('strval', self::$db->column("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action LIKE 'expense%' OR action = 'file.store'"));
        self::assertNotSame([], $details);
        foreach ($details as $d) {
            foreach (['75000', '76000', '15000', 'Global Payments', 'GP-7781', 'card fees', 'wrong card', 'anthropic'] as $secret) {
                self::assertStringNotContainsString($secret, $d, 'the audit log is read without expenses.view');
            }
        }
        self::assertStringContainsString('net_pence', (string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'expense.change'"),
            'which fields changed');
        self::assertSame('expense-invoice.pdf', self::$db->value('SELECT original_name FROM stored_file'));
    }

    /** M14: the nightly checks compare a delete's reason exactly, and the suppliers' and types' rows with their latest version. */
    public function testTheNightlyChecksCompareTheReasonTheSuppliersAndTheTypes(): void
    {
        $job = $this->staffUser('expenses');
        $s = new Expenses(self::$db);
        $type = (int) self::$db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $r = $s->add($job, ['shop' => $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => true, 'type' => $type, 'invoice_date' => '2026-11-12',
            'invoice_no' => 'V1', 'net' => '180.00', 'vat' => '36.00', 'note' => '']);
        $s->delete($job, $r['id'], 1, 'Entered twice');
        self::assertSame([], ExpenseInvariants::check(self::$db));
        self::$db->exec("UPDATE expense SET deleted_reason = 'Supplier credit received' WHERE id = ?", [$r['id']]);
        $a = ExpenseInvariants::check(self::$db);
        self::$db->exec("UPDATE expense SET deleted_reason = 'Entered twice' WHERE id = ?", [$r['id']]);
        self::assertSame(["EX-I2 expense {$r['id']}: the row is not what its latest history version says (changed around CW)"], $a);
        self::$db->exec("UPDATE expense_supplier SET name = 'Viking Direct Ltd' WHERE id = ?", [$r['supplier_id']]);
        $b = ExpenseInvariants::check(self::$db);
        self::$db->exec("UPDATE expense_supplier SET name = 'Viking Direct' WHERE id = ?", [$r['supplier_id']]);
        self::assertSame(["EX-I2 expense_supplier {$r['supplier_id']}: the row is not what its latest history version says (changed around CW)"], $b);
        self::$db->exec('UPDATE expense_type SET is_active = 0 WHERE id = ?', [$type]);
        $c = ExpenseInvariants::check(self::$db);
        self::$db->exec('UPDATE expense_type SET is_active = 1 WHERE id = ?', [$type]);
        self::assertSame(["EX-I2 expense_type {$type}: the row is not what its latest history version says (changed around CW)"], $c);
        self::assertSame([], ExpenseInvariants::check(self::$db));
    }
}
