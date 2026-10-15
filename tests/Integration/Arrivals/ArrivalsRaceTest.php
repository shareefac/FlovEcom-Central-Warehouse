<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Tests\Support\TestDb;

/**
 * Pack J races with two real connections (tests/Integration/Arrivals/arrival_worker.php, one process each; docs/decisions.md PJ1):
 * a site's checkout hold (strict policy: refused when short) against
 *  - the invoice made final ("count on arrival" puts its units in Incoming): with the posting's locks held, the hold waits and then
 *    holds units only Incoming has; started at the same moment, either order ends consistent (held, or refused as short);
 *  - the receipt of a short delivery (Incoming out, what came on hand): with the posting's locks held, a hold of the last units
 *    waits and is refused; at the same moment, either it came first (and the receipt raises the larger oversell) or it is refused.
 * (A checkout hold cannot be held open from outside: it owns its transaction.) No deadlock; the invariants (with J1-J4) hold after
 * every race (assertPostConditions).
 */
final class ArrivalsRaceTest extends ArrivalsTestCase
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
            $p = proc_open([PHP_BINARY, __DIR__ . '/arrival_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
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

    /** @return array<string, mixed> a checkout hold of $units units of variant $variant, as the site sends it */
    private function hold(\CW\Caller $site, string $ref, string $variant, int $units, int $holdMs = 0, float $delay = 0.0): array
    {
        $ids = [];
        for ($i = 1; $i <= $units; $i++) {
            $ids[] = $ref . '-' . $i;
        }
        return ['op' => 'reserve', 'channel_id' => $site->channelId, 'channel_code' => substr($site->actor, strlen('channel:')), 'order_ref' => $ref,
            'lines' => [self::line($variant, ...$ids)], 'key' => $this->key('race'), 'hold_ms' => $holdMs, 'delay' => $delay];
    }

    private function allocatedOrHeld(int $sku): int
    {
        return (int) (self::$db->value('SELECT allocated + held FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $sku]) ?? 0);
    }

    public function testACheckoutHoldAgainstTheInvoiceMadeFinal(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Race Final Ltd');
        $site = $this->site();
        foreach (['invoice first' => true, 'at the same moment' => false] as $round => $invoiceFirst) {
            $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 5);
            $this->strict($x['sku']);
            $this->book('goods_in', $x['sku'], 1); // one on the shelf: the stock row exists, the item is on sale
            $tag = $invoiceFirst ? 'A' : 'B';
            $variant = 'RF-' . $tag;
            $this->listing($site, $variant, $x['sku']);
            $v = $this->docs->get($x['doc']->id)->version;
            $post = ['op' => 'post_invoice', 'staff_id' => $desk->staffUserId, 'document_id' => $x['doc']->id, 'version' => $v];
            // A hold of three: more than the shelf has (1), less than the shelf and the invoice (6).
            $res = $invoiceFirst
                ? self::race([$post + ['hold_ms' => 800], $this->hold($site, 'RF-H' . $tag, $variant, 3, 0, 0.0) + ['after_lock' => 'stock_balance']])
                : self::race([$post, $this->hold($site, 'RF-H' . $tag, $variant, 3)]);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $round);
            self::assertSame(200, $res[0]['status'], $round . ': ' . json_encode($res));
            self::assertSame('posted', $this->docStatus($x['doc']->id), $round);
            self::assertSame(5, $this->incoming($x['sku']), $round);
            $held = $res[1]['status'] === 201;
            if ($invoiceFirst) {
                self::assertTrue($held, $round . ': the hold waited for the invoice, then found 1 on the shelf + 5 on their way ' . json_encode($res));
                self::assertGreaterThan(100, $res[1]['ms'], $round . ': it waited on the invoice\'s lock');
            } else {
                self::assertContains($res[1]['status'], [201, 409], $round . ': ' . json_encode($res));
                self::assertTrue($held || $res[1]['error'] === 'refused', $round . ': refused only as short (it came before the invoice)');
            }
            self::assertSame($held ? [3, 3] : [0, 6], [$this->allocatedOrHeld($x['sku']), $this->view($site, $variant)['available']], $round);
        }
    }

    public function testACheckoutHoldOfTheLastUnitsAgainstAShortReceipt(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Race Short Ltd');
        $site = $this->site();
        foreach (['receipt first' => true, 'at the same moment' => false] as $round => $receiptFirst) {
            $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10);
            $this->strict($x['sku']);
            $tag = $receiptFirst ? 'A' : 'B';
            $variant = 'RS-' . $tag;
            $this->listing($site, $variant, $x['sku']);
            $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
            $this->ok($this->commit($site, 'RS-PAID-' . $tag, [self::line($variant, ...array_map(static fn (int $i): string => "rs{$tag}{$i}", range(1, 8)))]));
            self::assertSame(2, $this->view($site, $variant)['available'], 'ten on their way, eight sold');
            $g = $this->arrivals->receive($desk, $p->id);
            $this->ready($desk, $g->id, [1 => ['short_units' => '4']]); // six come
            $v = $this->docs->get($g->id)->version;
            $post = ['op' => 'post_receipt', 'staff_id' => $desk->staffUserId, 'document_id' => $g->id, 'version' => $v];
            $res = $receiptFirst
                ? self::race([$post + ['hold_ms' => 800], $this->hold($site, 'RS-H' . $tag, $variant, 2, 0, 0.0) + ['after_lock' => 'stock_balance']])
                : self::race([$post, $this->hold($site, 'RS-H' . $tag, $variant, 2)]);
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks'], $round);
            self::assertSame(200, $res[0]['status'], $round . ': ' . json_encode($res));
            self::assertSame('posted', $this->docStatus($g->id), $round);
            self::assertSame([0, 6], [$this->incoming($x['sku']), $this->onHand($x['sku'])], $round . ': incoming to on hand, the missing four out');
            $held = $res[1]['status'] === 201;
            if ($receiptFirst) {
                self::assertSame([409, 'refused'], [$res[1]['status'], $res[1]['error']], $round . ': the hold waited, then found the delivery short ' . json_encode($res));
            } else {
                self::assertTrue($held || [$res[1]['status'], $res[1]['error']] === [409, 'refused'], $round . ': ' . json_encode($res));
            }
            // The hold first: ten sold of six, the receipt raised four; the receipt first: eight of six, two, and the hold was refused.
            self::assertSame($held ? [10, -4] : [8, -2], [$this->allocatedOrHeld($x['sku']), $this->view($site, $variant)['available']], $round);
            $e = self::$db->one('SELECT kind, shortfall FROM oversell_event WHERE sku_id = ? ORDER BY id DESC LIMIT 1', [$x['sku']]);
            self::assertSame(['movement_short', $held ? 4 : 2], [$e['kind'] ?? null, (int) ($e['shortfall'] ?? 0)], $round . ': the oversell the receipt raised');
        }
    }
}
