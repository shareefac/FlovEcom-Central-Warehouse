<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex re-check of 37a2cf0 (build/A/codex_r3.txt), the screens, each shown failing first: a row with units waiting for their cost is
 * incomplete and ranked after the others by margin, in every list (by shop, product, brand, supplier), and an order whose margin is not known
 * shows no profit as if it were (c6); a page cursor with a NUL byte starts again from the first page, never a 500 (R7).
 */
final class ProfitScreensReviewR3Test extends KernelUiTestCase
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

    private static function body(string $paid, string $variant, int $price): array
    {
        return ['v' => 1, 'version' => 1, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay',
            'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => 'l' . $variant, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0,
                'cancelled' => false, 'refund_inc' => 0, 'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $price, 'parcels' => []];
    }

    /**
     * Two items of two brands, each sold once: the first an hour ago (its cost fixed), the second just now (it waits for the cost job).
     *
     * @return array{0: \CW\Caller, 1: \CW\Tests\Support\KernelBrowser, 2: array<string, string>}
     */
    private function waiting(): array
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Priced');
        $b = $this->item('legacy', 0, 'Waiting');
        foreach ([$a => 'Pricebrand', $b => 'Waitbrand'] as $sku => $brand) {
            self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', [$brand, $sku]);
            self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$sku]);
            self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)",
                [$sku, 'start:' . $sku]);
        }
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        // The cost job has read up to ten minutes ago: the first order (an hour ago) has its cost, the second (just now) waits for it.
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?)", [$now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body($now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'), '1', 120), 'api');
        $s->store((int) $site->channelId, '2', self::body($now->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z'), '2', 600), 'api');
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE order_ref = '2'"), 'it waits for the cost job');
        $uk = new \DateTimeZone('Europe/London');
        return [$site, $this->signIn($this->uiUser('finance')),
            ['period' => 'choose', 'from' => $now->setTimezone($uk)->modify('-3 days')->format('Y-m-d'), 'to' => $now->setTimezone($uk)->format('Y-m-d')]];
    }

    public function testUnitsWaitingForTheirCostAreNeverRankedOrShownAsKnown(): void
    {
        [$site, $fin, $range] = $this->waiting();
        $by = self::rows($fin->get('/ui/profit/by', ['by' => 'product', 'sort' => 'margin'] + $range), 'profit-breakdown');
        self::assertSame(['Priced', 'Waiting'], array_map(static fn (array $r): string => explode(' ', $r[0])[0], $by),
            'the row whose units wait for their cost (no cost yet: 100%) after the one whose margin is known');
        $money = self::rows($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '2']), 'profit-order-money');
        $profit = array_values(array_filter($money, static fn (array $r): bool => $r[0] === Words::PROFIT['profit']))[0];
        self::assertStringStartsWith(Words::PROFIT['unknown'], $profit[1], 'its profit is not known yet');
        self::assertStringNotContainsString('%', $profit[1]);
    }

    public function testEveryListSaysWhenUnitsWaitForTheirCost(): void
    {
        [, $fin, $range] = $this->waiting();
        $brands = self::rows($fin->get('/ui/profit/by', ['by' => 'brand', 'sort' => 'margin'] + $range), 'profit-breakdown');
        self::assertSame(['Pricebrand', 'Waitbrand'], array_column($brands, 0), 'by brand too: the brand whose units wait after the known margin');
        self::assertStringContainsString(Words::PROFIT['incomplete'], implode(' ', $brands[1]));
        self::assertStringNotContainsString(Words::PROFIT['incomplete'], implode(' ', $brands[0]));
        foreach (['shop', 'supplier'] as $by) {
            $rows = self::rows($fin->get('/ui/profit/by', ['by' => $by] + $range), 'profit-breakdown');
            self::assertCount(1, $rows, $by);
            self::assertStringContainsString(Words::PROFIT['incomplete'], implode(' ', $rows[0]), "by {$by}: units wait for their cost");
        }
    }

    public function testACursorWithANulByteStartsAgainFromTheFirstPage(): void
    {
        $fin = $this->signIn($this->uiUser('finance'));
        self::assertSame(200, $fin->get('/ui/profit/orders', ['ak' => "\0", 'ac' => '1', 'ar' => 'x'])->status, 'never a 500');
    }
}
