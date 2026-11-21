<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex check of 51417a3 (build/A/codex_r6.txt), the screens, shown failing first: a line refunded in full whose unit came back to
 * stock has a cost of zero, known, even while its buying price was never fixed: its order's profit is £0.00, never "not known" (L6).
 */
final class ProfitScreensReviewR6Test extends KernelUiTestCase
{
    /** @return array<string, string> the money table of one order: its row names => what they say */
    private static function money(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//table[contains(@class, "profit-order-money")]/tbody/tr') ?: [] as $tr) {
            $cells = [];
            foreach ($xp->query('th|td', $tr) ?: [] as $c) {
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', (string) $c->textContent));
            }
            $out[$cells[0]] = $cells[1] ?? '';
        }
        return $out;
    }

    public function testARefundedLineBackInStockHasACostOfZeroKnown(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Came back');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)", [$a, 'start:' . $a]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // The cost job has read up to ten minutes ago: the order (just now) waits for its cost.
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?)", [$now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $this->listing($site, '1', $a);
        $paid = $now->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid,
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => '9100001', 'variant_id' => '1', 'qty' => 1,
            'price_inc' => 120, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 120, 'free' => false]], 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => 120, 'parcels' => []], 'api');
        // Its unit came back to stock in CW (the returns sweep marks it): refunded and back in stock, so no sale and no cost.
        self::$db->exec("UPDATE sale_line SET restocked = 1, sale_inc = 0, sale_ex = 0 WHERE order_ref = '1'");
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE order_ref = '1'"), 'its buying price was never fixed');
        $fin = $this->signIn($this->uiUser('finance'));
        $m = self::money($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '1']));
        self::assertStringStartsWith('£0.00', $m[Words::PROFIT['profit']], 'a cost of zero, known: £0.00, never "not known"');
    }
}
