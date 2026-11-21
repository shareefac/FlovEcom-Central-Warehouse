<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex review of 92efa82 (build/A/codex_r1_c.txt), the screens, each shown failing first: Profit by order pages by a cursor (c2); the
 * shops ranked by what they show, the order-level refunds taken off (c4); a website product with no CW item is its own store's (c5); the
 * lists by product say what order-level refunds they leave out (c7); the payment way in words (c8).
 */
final class ProfitScreensReviewR1Test extends KernelUiTestCase
{
    private static function cost(int $sku, int $e4): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-16', '2026-11-16')", [$sku, $e4]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, ?)",
            [$sku, 'start:' . $sku, $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    private static function store(Caller $site, string $ref, array $lines, array $over = []): void
    {
        $paid = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, $over + ['v' => 1, 'version' => 1, 'order_no' => '5' . $ref, 'status' => 'completed',
            'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => array_map(
                static fn (array $l): array => $l + ['qty' => 1, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false], $lines),
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []], 'api');
    }

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

    public function testTheScreensAfterTheReview(): void
    {
        $a = $this->site('shopa', 'live');
        $b = $this->site('shopb', 'live');
        $x = $this->item('legacy', 0, 'Review item');
        self::cost($x, 500000); // £50
        $this->listing($a, '1', $x);
        $this->listing($b, '1', $x);
        // Shop A: £100 ex VAT, £90 of it refunded on the order, cost £50: -400%. Shop B: £80 ex VAT, cost £50: 37.5%.
        self::store($a, '9100005', [['line_id' => '9100001', 'variant_id' => '1', 'price_inc' => 12000]], ['order_refund_inc' => 10800, 'gateway' => 'pphf']);
        self::store($b, '9100011', [['line_id' => '9100002', 'variant_id' => '1', 'price_inc' => 9600]]);
        // The same website number, no CW item, on both shops.
        self::store($a, '9100012', [['line_id' => '9100003', 'variant_id' => '123', 'price_inc' => 1200]]);
        self::store($b, '9100013', [['line_id' => '9100004', 'variant_id' => '123', 'price_inc' => 2400]]);
        $fin = $this->signIn($this->uiUser('finance'));

        self::assertSame(['SHOPA test site', 'SHOPB test site'], array_column(self::rows($fin->get('/ui/profit/by', ['by' => 'shop', 'sort' => 'margin_low']), 'profit-breakdown'), 0),
            'the lowest margin first, as the rows show it');
        $none = self::rows($fin->get('/ui/profit/no-cost'), 'profit-no-cost');
        self::assertSame([Words::say('PROFIT', 'store_variant', 'SHOPA test site', '123'), Words::say('PROFIT', 'store_variant', 'SHOPB test site', '123')],
            array_column($none, 0), 'one row a store');
        $byProduct = $fin->get('/ui/profit/by', ['by' => 'product']);
        self::assertStringContainsString(Words::say('PROFIT', 'order_refunds_note', '£90.00'), $byProduct->text());
        $one = $fin->get('/ui/profit/orders/view', ['store' => (string) $a->channelId, 'ref' => '9100005']);
        self::assertStringContainsString(Words::PROFIT['gateway_pphf'], $one->text());

        // 52 orders more: the first page holds 50 and a link to the next, which holds the rest after its cursor.
        for ($i = 1; $i <= 52; $i++) {
            self::store($b, '92000' . $i, [['line_id' => '9100006' . $i, 'variant_id' => '1', 'price_inc' => 6000 + $i]]);
        }
        $page1 = $fin->get('/ui/profit/orders');
        self::assertCount(50, self::rows($page1, 'profit-orders'));
        $next = (new \DOMXPath($page1->dom()))->query('//p[@class="pager"]/a')?->item(0)?->attributes?->getNamedItem('href')?->nodeValue;
        self::assertIsString($next);
        parse_str((string) parse_url($next, PHP_URL_QUERY), $q);
        self::assertArrayHasKey('ak', $q);
        $page2 = $fin->get('/ui/profit/orders', array_map('strval', $q));
        self::assertCount(6, self::rows($page2, 'profit-orders'), '56 orders: 50 and 6');
        $seen = array_merge(array_column(self::rows($page1, 'profit-orders'), 0), array_column(self::rows($page2, 'profit-orders'), 0));
        self::assertCount(56, array_unique($seen), 'no order twice, none missed');
        $low = $fin->get('/ui/profit/orders', ['low' => '1']);
        self::assertSame('59100005', explode(' ', self::rows($low, 'profit-orders')[0][0])[0], 'the lowest margin first');
    }
}
