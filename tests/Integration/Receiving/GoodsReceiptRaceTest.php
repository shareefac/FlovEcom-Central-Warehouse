<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Tests\Support\TestDb;

/**
 * Goods-receipt races with two real connections (tests/Integration/Receiving/grn_worker.php, one process each; I143):
 *  - the same receipt posted twice at once: one posting (one number, the stock once), the other 409;
 *  - two receipts against one PO line, each within the over-delivery tolerance alone but not together: the GRN number series and
 *    the PO's rows (locked by the posting after the series) serialise them, so the second sees the first's receipts and is refused;
 *  - the same supplier invoice keyed twice at once by two people: the unique (supplier, invoice key) lets one through;
 *  - the same "new delivery" form sent by two processes: one draft (FormOnce / Idempotency);
 *  - a posting against the PO's close: never a receipt on a closed order; either order of the two is consistent.
 * Every race ends with the invariants holding (assertPostConditions) and no deadlock.
 */
final class GoodsReceiptRaceTest extends ReceivingTestCase
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
            $p = proc_open([PHP_BINARY, __DIR__ . '/grn_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
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

    /** @param list<array<string, mixed>> $res @return list<int> the statuses, sorted */
    private static function statuses(array $res): array
    {
        $s = array_column($res, 'status');
        sort($s);
        return $s;
    }

    public function testTheSameReceiptPostedTwice(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $other = $this->staffUser('goods_in');
        $sku = self::makeSku('Double post item');
        $this->dry($sku);
        foreach ([0, 800] as $n => $hold) {
            $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 5]]);
            $this->ready($desk, $d->id);
            $v = $this->docs->get($d->id)->version;
            $res = self::race([
                ['op' => 'post', 'staff_id' => $desk->staffUserId, 'document_id' => $d->id, 'version' => $v, 'hold_ms' => $hold],
                ['op' => 'post', 'staff_id' => $other->staffUserId, 'document_id' => $d->id, 'version' => $v, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            self::assertSame([200, 409], self::statuses($res), json_encode($res));
            $loser = $res[0]['status'] === 409 ? $res[0] : $res[1];
            self::assertContains($loser['error'], ['not_draft', 'version_conflict']);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
            self::assertSame(5 * ($n + 1), $this->onHand($sku), 'booked once');
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'grn.post' AND entity_id = ?", [(string) $d->id]));
        }
        self::assertSame(2, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'GRN'"), 'one number per receipt, no gap');
    }

    public function testTwoReceiptsAgainstOnePoLineAreSerialisedByTheTolerance(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $desk2 = $this->staffUser('purchasing_desk');
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $this->dry($sku);
        foreach ([0, 800] as $hold) {
            $a = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'A-' . $hold], $po->id);
            $a = $this->grns->saveDraft($desk, $a->id, $a->version, [], [['sku_id' => $sku, 'packs' => 8]]);
            $b = $this->grns->createDraft($desk2, (int) $s['id'], ['invoice_number' => 'B-' . $hold], $po->id);
            $b = $this->grns->saveDraft($desk2, $b->id, $b->version, [], [['sku_id' => $sku, 'packs' => 8]]);
            $this->ready($desk, $a->id);
            $this->ready($desk2, $b->id);
            $res = self::race([
                ['op' => 'post', 'staff_id' => $desk->staffUserId, 'document_id' => $a->id, 'version' => $this->docs->get($a->id)->version, 'hold_ms' => $hold],
                ['op' => 'post', 'staff_id' => $desk2->staffUserId, 'document_id' => $b->id, 'version' => $this->docs->get($b->id)->version, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            self::assertSame([200, 422], self::statuses($res), json_encode($res));
            $loser = $res[0]['status'] === 422 ? $res[0] : $res[1];
            self::assertSame('over_tolerance', $loser['error']);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
            self::assertSame([8, 8], [(int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ?', [$po->id]), $this->onHand($sku)]);
            // Reset for the second round: the posted one reversed, the refused one cancelled.
            $winner = $res[0]['status'] === 200 ? $a : $b;
            $loserDoc = $winner === $a ? $b : $a;
            $this->docs->reverse($winner === $a ? $desk : $desk2, $winner->id, 'entered_in_error', null);
            $this->grns->cancel($loserDoc === $a ? $desk : $desk2, $loserDoc->id, $this->docs->get($loserDoc->id)->version, 'race test');
        }
    }

    public function testTheSameInvoiceKeyedTwiceAtOnce(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $desk2 = $this->staffUser('purchasing_desk');
        foreach ([0, 800] as $hold) {
            $res = self::race([
                ['op' => 'create', 'staff_id' => $desk->staffUserId, 'supplier_id' => (int) $s['id'], 'invoice' => 'TWICE-' . $hold, 'hold_ms' => $hold],
                ['op' => 'create', 'staff_id' => $desk2->staffUserId, 'supplier_id' => (int) $s['id'], 'invoice' => 'twice-' . $hold, 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            self::assertSame([200, 409], self::statuses($res), json_encode($res));
            $loser = $res[0]['status'] === 409 ? $res[0] : $res[1];
            self::assertSame('duplicate_invoice', $loser['error']);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
        }
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN'"));
    }

    public function testTheSameNewDeliveryFormFromTwoProcessesMakesOneDraft(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        foreach ([0, 800] as $hold) {
            $key = bin2hex(random_bytes(16));
            $res = self::race([
                ['op' => 'create_once', 'staff_id' => $desk->staffUserId, 'supplier_id' => (int) $s['id'], 'invoice' => 'ONCE-' . $hold, 'form_key' => $key, 'hold_ms' => $hold],
                ['op' => 'create_once', 'staff_id' => $desk->staffUserId, 'supplier_id' => (int) $s['id'], 'invoice' => 'ONCE-' . $hold, 'form_key' => $key,
                    'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            self::assertSame([200, 200], self::statuses($res), json_encode($res));
            self::assertSame($res[0]['id'], $res[1]['id'], 'one draft');
            self::assertTrue($res[0]['replayed'] xor $res[1]['replayed']);
        }
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN'"));
    }

    public function testAPostingAgainstThePoClose(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $this->dry($sku);
        $first = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'PART-1'], $po->id);
        $this->grns->saveDraft($desk, $first->id, $first->version, [], [['sku_id' => $sku, 'packs' => 4]]);
        $this->ready($desk, $first->id);
        $this->post($desk, $first->id);
        self::assertSame('part_received', $this->poRow($po->id)['state']);
        $b = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'PART-2'], $po->id);
        $this->grns->saveDraft($desk, $b->id, $b->version, [], [['sku_id' => $sku, 'packs' => 3]]);
        $this->ready($desk, $b->id);
        $res = self::race([
            ['op' => 'close_po', 'staff_id' => $buyer->staffUserId, 'po_id' => $po->id, 'version' => $this->docs->get($po->id)->version, 'hold_ms' => 800],
            ['op' => 'post', 'staff_id' => $desk->staffUserId, 'document_id' => $b->id, 'version' => $this->docs->get($b->id)->version, 'delay' => 0.2],
        ]);
        self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
        self::assertSame(200, $res[0]['status'], json_encode($res));
        self::assertSame([422, 'po_not_receivable'], [$res[1]['status'], $res[1]['error'] ?? null], 'the close held the order first: no receipt on a closed order');
        self::assertSame(['closed', 4], [$this->poRow($po->id)['state'], (int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ?', [$po->id])]);
        self::assertSame('draft', $this->docs->get($b->id)->status);
    }
}
