<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Items\Families;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * Pack B's races with two real connections (items_worker.php, one process each, the admin login on this slot's schema; the review of
 * b62c69f, findings 1 and 3): two people making an item of the same name and identity at the same moment (the second waits for the
 * first and is warned, never two items unseen); two people putting the same item, never in a family before, in a family at the same
 * moment (the second's line is skipped and listed, the rest of its list is done: never a duplicate-key error that undoes it all).
 */
final class ItemsRaceTest extends MappingTestCase
{
    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private static function race(array $jobs): array
    {
        $start = microtime(true) + 1.0;
        $procs = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __DIR__ . '/items_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
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

    public function testTwoPeopleMakingTheSameItemAtOnceTheSecondIsWarned(): void
    {
        [$a, $b] = [$this->staffUser('stock_controller'), $this->staffUser('mapping_lead')];
        $card = ['name' => 'Race nic shot 10ml', 'brand' => 'Acme', 'strength_mg' => '18'];
        $res = self::race([
            ['op' => 'create', 'staff_id' => $a->staffUserId, 'card' => $card, 'hold_ms' => 800],
            // The same name as the collation compares it (case, spaces): the same item.
            ['op' => 'create', 'staff_id' => $b->staffUserId, 'card' => ['name' => 'RACE NIC SHOT 10ML'] + $card, 'delay' => 0.25],
        ]);
        self::assertSame(200, $res[0]['status'], json_encode($res));
        self::assertSame([409, 'same_item_exists'], [$res[1]['status'], $res[1]['error'] ?? null], 'the second waited for the first and saw it: ' . json_encode($res));
        self::assertGreaterThanOrEqual(400, $res[1]['ms'], 'the second waited for the first');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE origin = 'manual'"));
        // A different name is not held up by it.
        $res = self::race([
            ['op' => 'create', 'staff_id' => $a->staffUserId, 'card' => ['name' => 'Race one'], 'hold_ms' => 800],
            ['op' => 'create', 'staff_id' => $b->staffUserId, 'card' => ['name' => 'Race two'], 'delay' => 0.25],
        ]);
        self::assertSame([200, 200], array_column($res, 'status'), json_encode($res));
        self::assertLessThan(700, $res[1]['ms'], 'another name does not wait: ' . json_encode($res));
    }

    /**
     * The window of finding 3 is between a list's read of the member rows (no row yet: under READ COMMITTED nothing is locked) and its
     * write. A third connection holds an item's sku row (lock_item), so the second list, which has that item before the contested one in
     * any order (the list's and the item id order), waits there with its read already done; meanwhile the first list puts the contested
     * item in its family and holds its transaction open; the second list's insert of it then meets the first's key.
     */
    public function testTwoFirstAssignmentsOfOneItemAtOnceTheSecondLineIsSkippedNotTheWholeList(): void
    {
        [$a, $b, $c] = [$this->staffUser('buyer'), $this->staffUser('mapping_lead'), $this->staffUser('stock_controller')];
        $svc = new Families(self::$db);
        $f1 = $svc->create($a, 'Race family one')['id'];
        $f2 = $svc->create($a, 'Race family two')['id'];
        // In id order: p (held by the third connection), x (contested), y, z, w.
        [$p, $x, $y, $z, $w] = [$this->item('legacy', 0, 'Held a moment'), $this->item('legacy', 0, 'Contested'), $this->item('legacy', 0, 'Only A'),
            $this->item('legacy', 0, 'Only B'), $this->item('legacy', 0, 'Also B')];
        $res = self::race([
            ['op' => 'lock_item', 'staff_id' => $c->staffUserId, 'sku_id' => $p, 'hold_ms' => 1000],
            ['op' => 'bulk_assign', 'staff_id' => $b->staffUserId, 'family_id' => $f2, 'list' => "{$z} {$p} {$x} {$w}", 'delay' => 0.1],
            ['op' => 'bulk_assign', 'staff_id' => $a->staffUserId, 'family_id' => $f1, 'list' => "{$x} {$y}", 'hold_ms' => 800, 'delay' => 0.35],
        ]);
        self::assertSame([200, 200, 200], array_column($res, 'status'), json_encode($res));
        self::assertSame([$x, $y], array_column($res[2]['result']['added'], 'sku_id'), 'the first list is done: ' . json_encode($res));
        self::assertSame([$z, $p, $w], array_column($res[1]['result']['added'], 'sku_id'), 'the rest of the second list is done');
        self::assertSame([[$x, 'in_other_family', 'Race family one']], array_map(static fn (array $s): array => [$s['sku_id'], $s['reason'], $s['other']],
            $res[1]['result']['skipped']), 'the contested line is skipped and listed: ' . json_encode($res));
        $of = Families::ofItems(self::$db, [$p, $x, $y, $z, $w]);
        self::assertSame([$f2, $f1, $f1, $f2, $f2], [$of[$p]['id'], $of[$x]['id'], $of[$y]['id'], $of[$z]['id'], $of[$w]['id']]);

        // The same family from both sides: the second says "already here".
        [$q, $v] = [$this->item('legacy', 0, 'Held again'), $this->item('legacy', 0, 'Contested again')];
        $res = self::race([
            ['op' => 'lock_item', 'staff_id' => $c->staffUserId, 'sku_id' => $q, 'hold_ms' => 1000],
            ['op' => 'bulk_assign', 'staff_id' => $b->staffUserId, 'family_id' => $f1, 'list' => "{$q} {$v}", 'delay' => 0.1],
            ['op' => 'bulk_assign', 'staff_id' => $a->staffUserId, 'family_id' => $f1, 'list' => (string) $v, 'hold_ms' => 800, 'delay' => 0.35],
        ]);
        self::assertSame([200, 200, 200], array_column($res, 'status'), json_encode($res));
        self::assertSame([$q], array_column($res[1]['result']['added'], 'sku_id'), json_encode($res));
        self::assertSame([[$v, 'already_here']], array_map(static fn (array $s): array => [$s['sku_id'], $s['reason']], $res[1]['result']['skipped']));

        // One item at a time (both wait for the held item, then insert its first row): one is done, the other is told the item changed
        // family meanwhile, never a duplicate-key error.
        $u = $this->item('legacy', 0, 'Contested once more');
        $res = self::race([
            ['op' => 'lock_item', 'staff_id' => $c->staffUserId, 'sku_id' => $u, 'hold_ms' => 1000],
            ['op' => 'assign', 'staff_id' => $b->staffUserId, 'family_id' => $f2, 'sku_id' => $u, 'hold_ms' => 300, 'delay' => 0.1],
            ['op' => 'assign', 'staff_id' => $a->staffUserId, 'family_id' => $f1, 'sku_id' => $u, 'hold_ms' => 300, 'delay' => 0.35],
        ]);
        $two = [[$res[1]['status'], $res[1]['error'] ?? null], [$res[2]['status'], $res[2]['error'] ?? null]];
        sort($two);
        self::assertSame([[200, null], [409, 'family_moved']], $two, json_encode($res));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_family_member WHERE sku_id = ?', [$u]));
    }
}
