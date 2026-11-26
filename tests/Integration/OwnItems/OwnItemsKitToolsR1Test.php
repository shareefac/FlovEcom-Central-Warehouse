<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Profit\OrderSnapshots;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1153, the Codex review of bd9104d (round 1), the go-live kit's tools, red on the round's tests-first state:
 *  H5  bin/recost_sales.php --fail-deferred exits 1 ("deferred=N") while an order is left because a newer snapshot of it waits for the money job,
 *      and 0 once the queue is worked out and it is run again (the kit's bounded retries); bin/item_owner_refresh.php takes --fail-deferred too
 *  M16 bin/item_owner_set.php --bill-lines=<n>: a bill that has not exactly n goods lines, each of the listed items, is refused whole
 */
final class OwnItemsKitToolsR1Test extends InvoicesTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function codesFile(string $text): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'vbp-r1-codes-');
        file_put_contents($f, $text);
        $this->files[] = $f;
        return $f;
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    private static function body(int $version): array
    {
        return ['v' => 1, 'version' => $version, 'order_no' => 'VB9701', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => '2026-11-19T10:00:00Z',
            'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8971', 'variant_id' => '40701', 'qty' => 1, 'price_inc' => 599, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]],
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 599, 'parcels' => []];
    }

    public function testH5TheReCostSaysTheOrdersLeftByItsExitCodeUntilTheQueueIsWorkedOut(): void
    {
        $vb = $this->site('vapebig', 'live');
        $h = $this->item('legacy', 0, 'Hayati 4000 R1 tool');
        $this->listing($vb, '40701', $h);
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, NULL, 'none', '2026-11-16', '2026-11-16')", [$h]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, NULL)", [$h, 'start:' . $h]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
        $snap = new OrderSnapshots(self::$db);
        $snap->store((int) $vb->channelId, '9701', self::body(1), 'api');
        self::assertSame('none', self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8971'"));
        // the owner's price, then a newer snapshot of the order waits for the money job (accepted, not worked out)
        self::$db->exec("UPDATE item_cost SET unit_cost_e4 = 37500 WHERE sku_id = ?", [$h]);
        self::$db->exec("UPDATE item_cost_event SET new_cost_e4 = 37500 WHERE sku_id = ?", [$h]);
        $snap->accept((int) $vb->channelId, '9701', self::body(2), 'api');
        $codes = $this->codesFile(self::code($h) . "\n");
        $args = ['--from=2026-11-01', '--to=2026-11-30', '--source=none,cw_cost', "--items={$codes}", '--apply', '--fail-deferred'];
        $left = self::script('recost_sales', ...$args);
        self::assertSame(1, $left['code'], $left['out']);
        self::assertStringContainsString('deferred=1', $left['out']);
        self::assertSame('none', self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8971'"), 'left as it was');
        $snap->applyQueued();
        $done = self::script('recost_sales', ...$args);
        self::assertSame(0, $done['code'], $done['out']);
        self::assertSame(375, (int) self::$db->value("SELECT cost FROM sale_line WHERE line_id = '8971'"));
        $refresh = self::script('item_owner_refresh', "--items={$codes}", '--from=2026-11-01', '--to=2026-11-30', '--apply', '--fail-deferred');
        self::assertSame(0, $refresh['code'], $refresh['out']);
    }

    public function testM16TheBillStepRefusesABillThatIsNotExactlyTheCheckedGoodsLines(): void
    {
        $this->site('vapebig', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech R1');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        $file = $this->codesFile(self::code($x['sku']) . "\n");
        $from = (string) self::$db->value("SELECT DATE_FORMAT(posted_at - INTERVAL 3 DAY, '%Y-%m-%d %H:%i:%s') FROM document WHERE id = ?", [$inv->id]);
        $wrong = self::script('item_owner_set', "--items={$file}", "--from={$from}", '--reason=the count that brought them in', "--bill={$inv->number}", '--bill-lines=8', '--apply');
        self::assertSame(2, $wrong['code'], $wrong['out']);
        self::assertStringContainsString('bill_not_pinned', $wrong['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_owner'), 'refused whole: no mark either');
        $right = self::script('item_owner_set', "--items={$file}", "--from={$from}", '--reason=the count that brought them in', "--bill={$inv->number}", '--bill-lines=1', '--apply');
        self::assertSame(0, $right['code'], $right['out']);
        self::assertStringContainsString('(1 goods lines)', $right['out']);
    }
}
