<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;

/**
 * CW-1153 B2 (docs/decisions.md VB11) through the real /ui kernel: Profit shows OUR figures; Vape Big's own goods are on a line of their own
 * (the summary's "The shop's own goods" table, a line under Real profit, and each order's line), never added to ours; no page says "partner".
 */
final class OwnGoodsScreensTest extends KernelUiTestCase
{
    private static function text(\CW\Tests\Support\UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $r->text()));
    }

    public function testTheProfitPagesShowOursAndTheShopsOwnGoodsApart(): void
    {
        $vb = $this->site('vapebig');
        $h = $this->item('legacy', 0, 'Hayati 4000 Blue Razz');
        $o = $this->item('legacy', 0, 'Our liquid 10ml');
        foreach ([[$h, 37500], [$o, 20000]] as [$sku, $e4]) {
            self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-01', '2026-11-01')", [$sku, $e4]);
            self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-01', '2026-11-01', 'start', ?, ?)",
                [$sku, 'start:' . $sku, $e4]);
        }
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2099-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
        $this->listing($vb, '40101', $h);
        $this->listing($vb, '40103', $o);
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$h]), 'Vape Big\'s own goods',
            '2026-11-01 00:00:00');
        $now = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $line = static fn (string $id, string $v, int $p): array => ['line_id' => $id, 'variant_id' => $v, 'qty' => 1, 'price_inc' => $p, 'discount_inc' => 0,
            'cancelled' => false, 'refund_inc' => 0, 'free' => false];
        (new OrderSnapshots(self::$db))->store((int) $vb->channelId, '9100', ['v' => 1, 'version' => 1, 'order_no' => '9100', 'status' => 'completed',
            'placed_at' => $now, 'paid_at' => $now, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [$line('8901', '40101', 599), $line('8902', '40103', 1200)],
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1799,
            'parcels' => []], 'api');
        $web = $this->signIn($this->uiUser('finance'));
        $summary = $web->get('/ui/profit', ['store' => (int) $vb->channelId]);
        self::assertSame(200, $summary->status, $summary->describe());
        $t = self::text($summary);
        self::assertStringContainsString('The shop\'s own goods', $t);
        self::assertStringNotContainsStringIgnoringCase('partner', $summary->body);
        $orders = $web->get('/ui/profit/orders', ['period' => 'last_7', 'store' => (int) $vb->channelId]);
        self::assertSame(200, $orders->status, $orders->describe());
        self::assertStringContainsString('VAPEBIG test site\'s own goods: £4.99, cost £3.75', self::text($orders));
        $one = $web->get('/ui/profit/orders/view', ['store' => (int) $vb->channelId, 'ref' => '9100']);
        self::assertSame(200, $one->status, $one->describe());
        self::assertStringContainsString('the shop\'s own', self::text($one));
        self::assertStringNotContainsStringIgnoringCase('partner', $one->body);
    }
}
