<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Tests\Support\TestDb;

/**
 * Each box saved by itself, with two real connections on one draft (po_worker.php, one process each; docs/decisions.md LS1-LS14, the plan's
 * step 4 "two workers hammer one draft"):
 *  - two people saving different lines over and over at the same time: no change is ever lost (every save is saved, the last value of each
 *    line is the one kept), no deadlock;
 *  - the same line saved by both at the same moment with the version both saw: exactly one winner and one line_conflict (both lock the
 *    document row first, so the second finds the line's new version), measured as one draft_change `conflict`;
 *  - the same product added by both at the same moment: one line, and the other is asked (line_exists);
 *  - the totals are always the sum of the lines (PurchaseInvariants P6, after every test).
 */
final class LiveLinesRaceTest extends PurchaseOrderTestCase
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

    /** The net of a draft worked out again from its lines (Σ amounts), as purchase_order.net_total stores it. */
    private static function sum(int $id): string
    {
        return (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$id]);
    }

    public function testTwoPeopleOnOneDraftNeverLoseAChangeAndMeetOnTheSameLineOnce(): void
    {
        $fazil = $this->staffUser('buyer');
        $shareef = $this->staffUser('buyer');
        $s = $this->activeSupplier($fazil, ['name' => 'Live Race Ltd']);
        $si = [];
        foreach (['Race pods', 'Race liquid', 'Race coil', 'Race tank'] as $i => $name) {
            $si[] = (int) $this->supplierItem($fazil, (int) $s['id'], self::makeSku($name), 1 + $i, (2 + $i) . '.5000')['id'];
        }
        $d = $this->draftPo($fazil, (int) $s['id'], [['supplier_item_id' => $si[0], 'packs' => 1], ['supplier_item_id' => $si[1], 'packs' => 1],
            ['supplier_item_id' => $si[2], 'packs' => 1]]);
        $id = $d->id;

        // Different lines, over and over, at the same time: every save saved, nothing lost.
        $res = self::race([
            ['op' => 'live_sets', 'staff_id' => $fazil->staffUserId, 'document_id' => $id, 'uid' => 1, 'values' => range(2, 16)],
            ['op' => 'live_sets', 'staff_id' => $shareef->staffUserId, 'document_id' => $id, 'uid' => 2, 'values' => range(102, 116)],
        ]);
        self::assertSame(array_fill(0, 15, 'saved'), $res[0]['results'], json_encode($res));
        self::assertSame(array_fill(0, 15, 'saved'), $res[1]['results'], json_encode($res));
        self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
        self::assertSame([16, 116, 1], array_map('intval', self::$db->column('SELECT packs FROM po_line WHERE document_id = ? ORDER BY line_no', [$id])));
        self::assertSame([16, 16], [(int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = 1', [$id]),
            (int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = 2', [$id])]);
        self::assertSame(30, (int) self::$db->value("SELECT COUNT(*) FROM draft_change WHERE record_id = ? AND op = 'set'", [$id]));
        self::assertSame(self::sum($id), (string) self::$db->value('SELECT net_total FROM purchase_order WHERE document_id = ?', [$id]));

        // The same line, the same version, the same moment: one winner, one refused for that line; held open or not.
        foreach ([0, 600, 0] as $round => $hold) {
            $rev = (int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = 3', [$id]);
            $res = self::race([
                ['op' => 'live_set', 'staff_id' => $fazil->staffUserId, 'document_id' => $id, 'uid' => 3, 'rev' => $rev, 'packs' => 200 + $round, 'hold_ms' => $hold],
                ['op' => 'live_set', 'staff_id' => $shareef->staffUserId, 'document_id' => $id, 'uid' => 3, 'rev' => $rev, 'packs' => 300 + $round, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            $got = [$res[0]['result'] ?? $res[0]['error'], $res[1]['result'] ?? $res[1]['error']];
            $sorted = $got;
            sort($sorted);
            self::assertSame(['line_conflict', 'saved'], $sorted, "round {$round}: " . json_encode($res));
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
            $winner = $got[0] === 'saved' ? 200 + $round : 300 + $round;
            self::assertSame($winner, (int) self::$db->value('SELECT packs FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
                . 'WHERE dl.document_id = ? AND dl.uid = 3', [$id]), "round {$round}: the winner's value");
            self::assertSame($rev + 1, (int) self::$db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = 3', [$id]));
            if ($hold > 0) {
                self::assertSame('saved', $got[0], 'the one holding the draft wins; the other waited for it');
            }
        }
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM draft_change WHERE record_id = ? AND op = 'conflict'", [$id]), 'each clash measured once');

        // The same product added by both at the same moment: one line, the other asked.
        $res = self::race([
            ['op' => 'live_add', 'staff_id' => $fazil->staffUserId, 'document_id' => $id, 'si' => $si[3], 'packs' => 5, 'key' => 'f000000000000001', 'hold_ms' => 400],
            ['op' => 'live_add', 'staff_id' => $shareef->staffUserId, 'document_id' => $id, 'si' => $si[3], 'packs' => 5, 'key' => 'e000000000000001', 'delay' => 0.2],
        ]);
        self::assertSame(['added', 'line_exists'], [$res[0]['result'] ?? $res[0]['error'], $res[1]['result'] ?? $res[1]['error']], json_encode($res));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ? AND supplier_item_id = ?', [$id, $si[3]]));
        self::assertSame(5, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND supplier_item_id = ?', [$id, $si[3]]));
        self::assertSame(self::sum($id), (string) self::$db->value('SELECT net_total FROM purchase_order WHERE document_id = ?', [$id]));
    }
}

