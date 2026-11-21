<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The Codex check of the fix round (r11, build/A/codex_r11_astra.txt), one order's page, shown failing first on 973c8ff: an order paid with
 * its cost fixed and then no longer paid shows no profit or margin on its lines either (F18 PARTLY: it showed £7.00 and 70.0%).
 */
final class ProfitScreensCodexR11Test extends KernelUiTestCase
{
    public function testAnOrderNoLongerPaidShowsNoProfitOnItsLines(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Paid then not');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 30000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 30000)", [$a, 'start:' . $a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')");
        $this->listing($site, '1', $a);
        $body = static fn (int $version, ?string $paid): array => ['v' => 1, 'version' => $version, 'order_no' => '', 'status' => 'completed',
            'placed_at' => '2026-11-19T09:00:00Z', 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => 'w1',
            'variant_id' => '1', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]], 'delivery' => null,
            'order_refund_inc' => 0, 'grand_inc' => 1200, 'parcels' => []];
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'W1', $body(1, '2026-11-19T10:00:00Z'), 'api');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'W1', $body(2, null), 'api');
        $fin = $this->signIn($this->uiUser('finance'));
        $xp = new \DOMXPath($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => 'W1'])->dom());
        $cells = [];
        foreach ($xp->query('//table[contains(@class, "profit-order-lines")]/tbody/tr[1]/td') ?: [] as $td) {
            $cells[] = trim((string) preg_replace('/\s+/u', ' ', (string) $td->textContent));
        }
        self::assertSame(['not counted (not paid)', 'not counted (not paid)'], array_slice($cells, -2), 'no profit, no margin on its line: ' . json_encode($cells));
        self::assertNotContains('70.0%', $cells, Words::PROFIT['profit']);
    }
}
