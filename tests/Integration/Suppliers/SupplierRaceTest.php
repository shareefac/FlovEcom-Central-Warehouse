<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Tests\Support\TestDb;

/**
 * Supplier races with two real connections (tests/Integration/Suppliers/supplier_worker.php, one process each, the admin
 * login on this slot's schema; TestDb::connect() per worker):
 *  - two reviewers approve the same activation at the same moment: one approves, the other gets 409 task_closed
 *    (both lock the supplier row first, then the task: they queue, never deadlock);
 *  - two buyers make two supplier items of one item preferred at the same moment: exactly one is preferred afterwards
 *    (the UNIQUE preferred_sku_id decides; the loser is retried once and then wins over the earlier one).
 * Each race runs twice: once started together, once with the first worker holding its transaction open for 800 ms, so the
 * second certainly meets its locks.
 */
final class SupplierRaceTest extends SupplierTestCase
{
    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private static function race(array $jobs): array
    {
        $start = microtime(true) + 1.0;
        $procs = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __DIR__ . '/supplier_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
            self::assertIsResource($p, "worker {$i}");
            fwrite($pipes[0], json_encode(['start' => $start + (float) ($job['delay'] ?? 0)] + $job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            $line = json_decode(trim($stdout), true);
            self::assertTrue($code === 0 && is_array($line), "worker {$i} failed (exit {$code}): " . substr($stderr . $stdout, 0, 2000));
            $out[] = $line;
        }
        return $out;
    }

    public function testTwoReviewersApprovingTheSameActivation(): void
    {
        $buyer = $this->staffUser('buyer');
        foreach ([0, 800] as $hold) {
            $s = $this->draft($buyer, ['name' => "Race supplier {$hold}"]);
            $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
            $task = $this->openTask((int) $s['id']);
            [$r1, $r2] = [$this->staffUser('reviewer'), $this->staffUser('reviewer')];
            $res = self::race([
                ['op' => 'approve', 'staff_id' => $r1->staffUserId, 'task_id' => $task, 'hold_ms' => $hold],
                ['op' => 'approve', 'staff_id' => $r2->staffUserId, 'task_id' => $task, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            $statuses = array_column($res, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, json_encode($res));
            $loser = $res[0]['status'] === 409 ? $res[0] : $res[1];
            self::assertSame('task_closed', $loser['error']);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], 'they queue on the supplier row: no deadlock');
            if ($hold > 0) {
                self::assertSame(200, $res[0]['status'], 'the holder approves');
                self::assertGreaterThanOrEqual(400, $res[1]['ms'], 'the second waited for the holder\'s supplier lock');
            }
            $after = $this->sup->get((int) $s['id']);
            $winner = $res[0]['status'] === 200 ? $r1 : $r2;
            self::assertSame(['active', $winner->staffUserId], [$after['status'], (int) $after['approved_by']]);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'supplier.approve' AND entity_id = ?", [(string) $s['id']]));
        }
    }

    public function testTwoBuyersPreferringTwoSuppliesOfOneItem(): void
    {
        $buyer = $this->staffUser('buyer');
        foreach ([0, 800] as $hold) {
            $a = $this->activeSupplier($buyer, ['name' => "Pref A {$hold}"]);
            $b = $this->activeSupplier($buyer, ['name' => "Pref B {$hold}"]);
            $sku = self::makeSku("Contested item {$hold}");
            $ia = $this->items->create($buyer, (int) $a['id'], $sku, []);
            $ib = $this->items->create($buyer, (int) $b['id'], $sku, []);
            [$b1, $b2] = [$this->staffUser('buyer'), $this->staffUser('buyer')];
            $res = self::race([
                ['op' => 'set_preferred', 'staff_id' => $b1->staffUserId, 'supplier_item_id' => (int) $ia['id'], 'hold_ms' => $hold],
                ['op' => 'set_preferred', 'staff_id' => $b2->staffUserId, 'supplier_item_id' => (int) $ib['id'], 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            foreach ($res as $r) {
                self::assertTrue($r['status'] === 200 || ($r['status'] === 409 && $r['error'] === 'preferred_busy'), json_encode($res));
            }
            $preferred = array_map('intval', self::$db->column('SELECT id FROM supplier_item WHERE sku_id = ? AND preferred_sku_id IS NOT NULL', [$sku]));
            self::assertCount(1, $preferred, 'exactly one preferred supply: ' . json_encode($res));
            if ($hold > 0) {
                self::assertSame([200, 200], array_column($res, 'status'), 'the loser was retried once and won');
                self::assertSame([(int) $ib['id']], $preferred, 'the later one unset the holder\'s once it committed');
            }
        }
    }
}
