<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex re-check of e5d2e5a (build/A/codex_r5b.txt), the screens, each shown failing first: an order's profit is "not known" exactly
 * when its own units lack a price (whatever it sold for), and its profit with its re-sends when a re-send's units lack one (S1); the No
 * buying price page never says every product has a price while units still wait for theirs (S2).
 */
final class ProfitScreensReviewR5Test extends KernelUiTestCase
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

    /** @param list<array<string, mixed>> $lines */
    private static function body(string $paid, ?string $parent, array $lines): array
    {
        return ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay',
            'currency' => 'GBP', 'parent_ref' => $parent, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    private static function sl(string $id, string $variant, int $price, bool $free = false): array
    {
        return ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => $free];
    }

    /** @return array<string, string> the money table of one order: its row names => what they say */
    private static function money(UiResponse $r): array
    {
        $out = [];
        foreach (self::rows($r, 'profit-order-money') as $row) {
            $out[$row[0]] = $row[1] ?? '';
        }
        return $out;
    }

    public function testAnOrdersProfitIsNotKnownExactlyWhenSomeOfItsUnitsLackAPrice(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Priced');
        $b = $this->item('legacy', 0, 'Not priced');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)", [$a, 'start:' . $a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')");
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $paid = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');
        $s = new OrderSnapshots(self::$db);
        // Order 1: one priced unit sold for £1.20 inc VAT (£1.00 ex), cost £0.50; its re-send 2 sends one unit that has no price.
        $s->store((int) $site->channelId, '1', self::body($paid, null, [self::sl('l1', '1', 120)]), 'api');
        $s->store((int) $site->channelId, '2', self::body($paid, '1', [self::sl('l2', '2', 0, true)]), 'api');
        // Order 3: a free unit with no price (nothing sold).
        $s->store((int) $site->channelId, '3', self::body($paid, null, [self::sl('l3', '2', 0, true)]), 'api');
        $fin = $this->signIn($this->uiUser('finance'));
        $one = self::money($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '1']));
        self::assertStringStartsWith('£0.50', $one[Words::PROFIT['profit']], 'its own profit is known: £1.00 - £0.50');
        self::assertStringContainsString('50.0%', $one[Words::PROFIT['profit']]);
        self::assertStringStartsWith(Words::PROFIT['unknown'], $one[Words::PROFIT['with_resends']], 'with its re-send, whose unit has no price: not known, never £0.50');
        $three = self::money($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '3']));
        self::assertStringStartsWith(Words::PROFIT['unknown'], $three[Words::PROFIT['profit']], 'nothing sold and a cost not known: a loss not known, never £0.00');
    }

    public function testTheNoBuyingPricePageSaysWhenUnitsStillWait(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Waiting');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)", [$a, 'start:' . $a]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?)", [$now->modify('-10 minutes')->format('Y-m-d H:i:s.u')]);
        $this->listing($site, '1', $a);
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', self::body($now->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z'), null,
            [self::sl('l1', '1', 120)]), 'api');
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE order_ref = '1'"), 'it waits for the cost job');
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/no-cost', ['period' => 'last_7']);
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString(Words::PROFIT['no_cost_none'], $page->text(), 'never "every product has a buying price" while one unit waits');
        self::assertStringContainsString(Words::PROFIT['no_cost_waiting'], $page->text());
    }
}
