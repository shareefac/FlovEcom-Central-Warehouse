<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The second final review of 3c31d94 (build/A/codex_final2_astra_{b,c}.txt; final2/FIX_BRIEF.md F18), one order's page, shown failing
 * first: an unpaid order shows no profit or margin (c-L1); the shop's grand total is "Shop order total", never "Customer paid" (b-L2,
 * c-L1); a clone's "not counted" price leaves its cancelled lines out (Fable L7).
 */
final class ProfitScreensFinalReview2Test extends KernelUiTestCase
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

    /** @param list<array<string, mixed>> $lines */
    private static function snap(int $ch, string $ref, ?string $paid, array $lines, ?string $parent = null): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => '2026-11-19T09:00:00Z',
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => $parent, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => 1200, 'parcels' => []], 'api');
    }

    /** @return array<string, mixed> */
    private static function pline(string $id, int $price, bool $cancelled = false): array
    {
        return ['line_id' => $id, 'variant_id' => '1', 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => $cancelled, 'refund_inc' => 0, 'free' => false];
    }

    public function testAnUnpaidOrderShowsNoProfitAndTheShopsTotalIsNotCalledPaid(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Unpaid one');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 30000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 30000)", [$a, 'start:' . $a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')");
        $this->listing($site, '1', $a);
        self::snap((int) $site->channelId, 'U1', null, [self::pline('u1', 1200)]);
        $fin = $this->signIn($this->uiUser('finance'));
        $m = self::money($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => 'U1']));
        self::assertSame('not counted (not paid)', $m[Words::PROFIT['profit']] ?? null, 'no profit and no margin on an order not paid: ' . json_encode($m));
        self::assertArrayHasKey('Shop order total', $m, 'what the shop asked for, never "Customer paid"');
        self::assertArrayNotHasKey('Customer paid', $m);
    }

    public function testACloneSaysWhatIsNotCountedWithoutItsCancelledLines(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        self::snap($ch, 'P1', '2026-11-19T10:00:00Z', [self::pline('p1', 1200)]);
        self::snap($ch, 'C1', '2026-11-19T11:00:00Z', [self::pline('c1', 1200), self::pline('c2', 800, true)], 'P1');
        $fin = $this->signIn($this->uiUser('finance'));
        $text = (string) preg_replace('/\s+/u', ' ', $fin->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => 'C1'])->text());
        self::assertStringContainsString(sprintf(Words::PROFIT['resend_not_counted'], Html::money(12.0), Html::money(0.0)), $text,
            'goods £12.00, the cancelled £8.00 line left out (it said £20.00)');
    }
}
