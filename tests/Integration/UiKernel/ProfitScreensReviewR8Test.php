<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex check of 1179112..e1f075c (build/A/codex_r8.txt), the screens, shown failing first: units waiting for their cost are counted on
 * the row even when they are too few to say "incomplete" (LOW 3).
 */
final class ProfitScreensReviewR8Test extends KernelUiTestCase
{
    /** @return list<list<string>> */
    private static function rows(UiResponse $r, string $class): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query("//table[contains(@class, \"{$class}\")]/tbody/tr") ?: [] as $tr) {
            $row = [];
            foreach ($xp->query('th|td', $tr) ?: [] as $c) {
                $row[] = trim((string) preg_replace('/\s+/u', ' ', (string) $c->textContent));
            }
            $out[] = $row;
        }
        return $out;
    }

    public function testUnitsWaitingForTheirCostAreCountedOnTheRow(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Waiting one');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)", [$a, 'start:' . $a]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?)", [$now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $this->listing($site, '1', $a);
        $paid = $now->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid,
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => 'l1', 'variant_id' => '1', 'qty' => 1,
            'price_inc' => 120, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]], 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => 120, 'parcels' => []], 'api');
        // The day has 400 units (one of them waiting): too few to say "incomplete", never too few to count.
        self::$db->exec('UPDATE profit_day SET units = 400, sale_ex = 40000, cost = 24000 WHERE channel_id = ?', [(int) $site->channelId]);
        $fin = $this->signIn($this->uiUser('finance'));
        $today = implode(' ', self::rows($fin->get('/ui/profit'), 'profit-summary')[0]);
        self::assertStringNotContainsString(Words::PROFIT['incomplete'], $today);
        self::assertStringContainsString(sprintf(Words::PROFIT['waiting_count'], '1'), $today, 'the one waiting is counted on the row');
    }
}
