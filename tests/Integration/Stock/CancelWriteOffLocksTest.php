<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Clock;
use CW\Reservations;
use CW\Stock;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * CW-1180 under the locks (the final review of f3a7ed0, M1 and M5; docs/decisions.md NR3, NR5): the write-off's cost is the item's average at
 * its own place in the item's value sequence (read under the item's value clock, after the balance locks: a delivery that commits while the
 * cancel waits is in it), and an uncancel of many units reads "counted since" once per locked balance, never once per unit.
 */
final class CancelWriteOffLocksTest extends StockTestCase
{
    public function testADeliveryThatCommitsWhileTheCancelWaitsIsInTheWriteOffsCost(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $this->book('goods_in', $sku, 10, 'MAIN', null, '2'); // average £2.00
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a')]);

        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $ran = false;
        $averages = 0;
        HookedStatement::$before = [];
        // just before the cancel takes its first balance lock, another session books 10 more at £4.00 and commits (average now £3.00)
        HookedStatement::$before = ['SELECT warehouse_id, sku_id, on_hand, allocated, held, incoming, counted_at FROM stock_balance' => function () use ($sku, &$ran): void {
            $ran = true;
            $this->book('goods_in', $sku, 10, 'MAIN', null, '4');
        }, 'SELECT s.sku_id, SUM(l.qty_delta * l.unit_cost)' => static function () use (&$averages): void {
            $averages++;
        }];
        try {
            $res = new Reservations($conn, new Stock($conn), fn (): DateTimeImmutable => $this->now);
            $r = $res->cancel($site, '1', ['a'], false, 'cnl-race');
        } finally {
            HookedStatement::$before = [];
        }
        self::assertTrue($ran, 'the delivery ran between the cancel\'s first read of the cost and its balance lock');
        self::assertSame(1, $averages, 'the first read, before the locks (HookedStatement runs each hook once; the re-read is counted below)');
        self::assertSame([['unit_id' => 'a', 'result' => 'cancelled', 'outcome' => 'written_off']], $r->body['units']);
        $cost = (string) self::$db->value("SELECT CAST(unit_cost AS CHAR) FROM stock_ledger WHERE idem_key = 'cnl-race' AND movement_type = 'write_off'");
        self::assertSame('3.000000', $cost, 'the average at the write-off\'s place in the value sequence (10 at £2 + 10 at £4), never the stale £2.00');
        // and the write-off's seq is after the delivery's: the cost is the average of everything before it in the item's sequence
        $seq = self::$db->all("SELECT l.movement_type, s.seq FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id = ? ORDER BY s.seq", [$sku]);
        self::assertSame(['goods_in', 'goods_in', 'write_off'], array_column($seq, 'movement_type'));
    }

    public function testTheCostsAreReadBeforeAnyLockAndReReadUnderTheLocksOnlyWhenTheItemsValueStreamMoved(): void
    {
        // round 3 (the re-check of fa9cd43, "100-SKU cancel"): the averages and the items' value-clock versions are read BEFORE the balance
        // locks; under the locks (balances, then value clocks) the versions are read again: unchanged = the costs read stand (no scan under a
        // lock); changed = read again there, once
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $this->book('goods_in', $sku, 10, 'MAIN', null, '2');
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $seen = [];
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen): void {
            $sql = ltrim($sql);
            if (str_starts_with($sql, 'SELECT s.sku_id, SUM(l.qty_delta * l.unit_cost)')) {
                $seen[] = 'average';
            } elseif (str_starts_with($sql, 'SELECT warehouse_id, sku_id, on_hand, allocated, held, incoming, counted_at FROM stock_balance')) {
                $seen[] = 'balance lock';
            }
        }]]);
        $res = new Reservations($conn, new Stock($conn), fn (): DateTimeImmutable => $this->now);
        $res->cancel($site, '1', ['a'], false, 'cnl-quiet');
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        self::assertSame(['average', 'balance lock'], $seen, 'nothing moved: the average read once, before any lock');
        self::assertSame('2.000000', (string) self::$db->value("SELECT CAST(unit_cost AS CHAR) FROM stock_ledger WHERE idem_key = 'cnl-quiet' AND movement_type = 'write_off'"));
    }

    public function testOneUncancelOfManyUnitsReadsCountedSinceOncePerBalanceAndJudgesEachUnit(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $this->book('goods_in', $sku, 20, 'MAIN', null, '2');
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->ok($this->res->cancel($site, '1', ['a'], false, $this->key('cancel'))); // written off, then counted
        $this->now = $this->now->modify('+5 minutes');
        $this->book('count', $sku, 19, 'MAIN', Clock::iso(Clock::db($this->now)));
        $this->now = $this->now->modify('+5 minutes');
        $this->ok($this->res->cancel($site, '1', ['b', 'c', 'd'], false, $this->key('cancel'))); // written off after the count
        $this->assertBal(16, 0, 0, $sku);

        $conn = TestDb::connect();
        $lookups = 0;
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$lookups): void {
            if (str_contains($sql, "movement_type = 'count'") && str_contains($sql, 'FROM stock_ledger')) {
                $lookups++;
            }
        }]]);
        $res = new Reservations($conn, new Stock($conn), fn (): DateTimeImmutable => $this->now);
        $r = $res->uncancel($site, '1', ['a', 'b', 'c', 'd'], 'unc-many');
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        $answers = [];
        foreach ($r->body['units'] as $u) {
            $answers[$u['unit_id']] = $u['result'] . '|' . ($u['outcome'] ?? '');
        }
        self::assertSame(['a' => 'uncancelled_after_verify|after_write_off', 'b' => 'uncancelled|write_off_taken_back', 'c' => 'uncancelled|write_off_taken_back',
            'd' => 'uncancelled|write_off_taken_back'], $answers, 'each unit judged against the latest count: a before it, b-d after it');
        self::assertSame(1, $lookups, 'one "counted since" read for the one balance, not one per unit');
        $this->assertBal(19, 4, 0, $sku);
    }
}
