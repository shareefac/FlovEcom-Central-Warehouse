<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Tests\Support\TestDb;

/**
 * Purchase-order races with two real connections (tests/Integration/PurchaseOrders/po_worker.php, one process each; spec
 * §9.2, I54):
 *  - the same draft approved twice at the same moment: one posting, the other 409 (both lock the document row first);
 *  - the same "new order" form (one form_key) sent by two processes at once: one draft (FormOnce / Idempotency queue the
 *    second, which replays the first's result);
 *  - approve against the supplier's deactivation: the approval's FOR SHARE on the supplier row and the deactivation's FOR
 *    UPDATE serialise them, so a PO is never posted after the deactivation committed — either it is posted first (the
 *    deactivation waits for it) or it is refused (422 supplier_not_active).
 */
final class PurchaseOrderRaceTest extends PurchaseOrderTestCase
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
            $p = proc_open([PHP_BINARY, __DIR__ . '/po_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
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

    public function testTheSameDraftApprovedTwice(): void
    {
        $buyer = $this->staffUser('buyer');
        $other = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Double Post Ltd']);
        $si = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Double item'), 1, '3.0000');
        foreach ([0, 800] as $hold) {
            $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 2]]);
            $res = self::race([
                ['op' => 'approve', 'staff_id' => $buyer->staffUserId, 'document_id' => $d->id, 'version' => $d->version, 'hold_ms' => $hold],
                ['op' => 'approve', 'staff_id' => $other->staffUserId, 'document_id' => $d->id, 'version' => $d->version, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            $statuses = array_column($res, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, json_encode($res));
            $loser = $res[0]['status'] === 409 ? $res[0] : $res[1];
            self::assertContains($loser['error'], ['not_draft', 'version_conflict']);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
            self::assertSame('posted', $this->docs->get($d->id)->status);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.approve' AND entity_id = ?", [(string) $d->id]));
        }
        self::assertSame(2, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'PO'"), 'one number per order, no gap');
    }

    public function testTheSameNewOrderFormFromTwoProcessesMakesOneDraft(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Form Once Ltd']);
        foreach ([0, 800] as $hold) {
            $key = bin2hex(random_bytes(16));
            $res = self::race([
                ['op' => 'create_once', 'staff_id' => $buyer->staffUserId, 'supplier_id' => (int) $s['id'], 'form_key' => $key, 'hold_ms' => $hold],
                ['op' => 'create_once', 'staff_id' => $buyer->staffUserId, 'supplier_id' => (int) $s['id'], 'form_key' => $key, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            self::assertSame([200, 200], array_column($res, 'status'), json_encode($res));
            self::assertSame($res[0]['id'], $res[1]['id'], 'the same draft for both');
            self::assertSame([false, true], [min($res[0]['replayed'], $res[1]['replayed']), max($res[0]['replayed'], $res[1]['replayed'])]);
        }
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"), 'one draft per form');
    }

    public function testApproveAgainstTheSupplierDeactivation(): void
    {
        $buyer = $this->staffUser('buyer');
        // (a) the approval holds its locks: the deactivation waits, the order is posted before it.
        $s = $this->activeSupplier($buyer, ['name' => 'Race One Ltd']);
        $si = $this->supplierItem($buyer, (int) $s['id'], self::makeSku('Race item 1'), 1, '1.0000');
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        $s = $this->sup->get((int) $s['id']);
        $res = self::race([
            ['op' => 'approve', 'staff_id' => $buyer->staffUserId, 'document_id' => $d->id, 'version' => $d->version, 'hold_ms' => 800],
            ['op' => 'deactivate', 'staff_id' => $buyer->staffUserId, 'supplier_id' => (int) $s['id'], 'version' => (int) $s['version'], 'delay' => 0.2],
        ]);
        self::assertSame([200, 200], array_column($res, 'status'), json_encode($res));
        self::assertGreaterThanOrEqual(400, $res[1]['ms'], 'the deactivation waited for the approval\'s share lock on the supplier');
        $posted = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$d->id]);
        $deactivated = (string) self::$db->value('SELECT deactivated_at FROM supplier WHERE id = ?', [(int) $s['id']]);
        self::assertLessThan($deactivated, $posted, 'posted before the deactivation');

        // (b) the deactivation holds its lock: the approval waits, then finds the supplier inactive.
        $s2 = $this->activeSupplier($buyer, ['name' => 'Race Two Ltd']);
        $si2 = $this->supplierItem($buyer, (int) $s2['id'], self::makeSku('Race item 2'), 1, '1.0000');
        $d2 = $this->draftPo($buyer, (int) $s2['id'], [['supplier_item_id' => (int) $si2['id'], 'packs' => 1]]);
        $s2 = $this->sup->get((int) $s2['id']);
        $res = self::race([
            ['op' => 'deactivate', 'staff_id' => $buyer->staffUserId, 'supplier_id' => (int) $s2['id'], 'version' => (int) $s2['version'], 'hold_ms' => 800],
            ['op' => 'approve', 'staff_id' => $buyer->staffUserId, 'document_id' => $d2->id, 'version' => $d2->version, 'delay' => 0.2],
        ]);
        self::assertSame([200, 422], array_column($res, 'status'), json_encode($res));
        self::assertSame('supplier_not_active', $res[1]['error']);
        self::assertGreaterThanOrEqual(400, $res[1]['ms'], 'the approval waited for the deactivation');
        self::assertSame('draft', $this->docs->get($d2->id)->status, 'never posted after the deactivation committed');
        self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
    }
}
