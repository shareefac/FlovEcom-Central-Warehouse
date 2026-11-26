<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * CW-1153, the Codex review of bd9104d (round 1), the screens, each red on the round's tests-first commit:
 *  M7  one order's page: our goods, cost and profit (a Vape Big-only sale of £4.99 at £3.75 shows £0.00 of ours, never £1.24) and the shop's own
 *      goods apart (sales, refund share, cost, profit)
 *  M8  an order of ours re-sent with the shop's own goods: our "with re-sends" never pays for them (the page and the list agree)
 *  M20 Profit > No buying price lists the shop's own items with no price apart; Real profit never prints the shop's own sales as its profit
 *      while one of its units has no price
 */
final class OwnItemsReviewR1ScreensTest extends KernelUiTestCase
{
    private \CW\Caller $vb;
    private int $h;
    private int $o;
    private int $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = $this->site('vapebig');
        $this->h = $this->item('legacy', 0, 'Hayati 4000 Grape R1');
        $this->o = $this->item('legacy', 0, 'Our liquid R1 page');
        $this->n = $this->item('legacy', 0, 'Elux Legend no price R1');
        foreach ([[$this->h, 37500], [$this->o, 20000]] as [$sku, $e4]) {
            self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-01', '2026-11-01')", [$sku, $e4]);
            self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-01', '2026-11-01', 'start', ?, ?)",
                [$sku, 'start:' . $sku, $e4]);
        }
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2099-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
        $this->listing($this->vb, '40201', $this->h);
        $this->listing($this->vb, '40203', $this->o);
        $this->listing($this->vb, '40204', $this->n);
        foreach ([$this->h, $this->n] as $sku) {
            (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 'Vape Big\'s own goods',
                '2026-11-01 00:00:00');
        }
    }

    protected function tearDown(): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['"2026-12-01"', 'profit.real_from_vapebig']);
        parent::tearDown();
    }

    private function store(string $ref, array $lines, ?string $parent = null): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        (new OrderSnapshots(self::$db))->store((int) $this->vb->channelId, $ref, ['v' => 1, 'version' => 1, 'order_no' => 'R' . $ref, 'status' => 'completed',
            'placed_at' => $now, 'paid_at' => $now, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => $parent,
            'lines' => array_map(static fn (array $l): array => ['line_id' => $l[0], 'variant_id' => $l[1], 'qty' => 1, 'price_inc' => $l[2], 'discount_inc' => 0,
                'cancelled' => false, 'refund_inc' => 0, 'free' => false], $lines),
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []], 'api');
    }

    /** @return array<string, string> a two-column table's rows: header => value */
    private static function pairs(UiResponse $r, string $class): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query("//table[contains(@class, \"{$class}\")]/tbody/tr") ?: [] as $tr) {
            $th = $xp->query('th', $tr)->item(0);
            $td = $xp->query('td', $tr)->item(0);
            if ($th !== null && $td !== null) {
                $out[trim((string) preg_replace('/\s+/u', ' ', $th->textContent))] = trim((string) preg_replace('/\s+/u', ' ', $td->textContent));
            }
        }
        return $out;
    }

    private static function text(UiResponse $r): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $r->text()));
    }

    public function testM7AndM8TheOrderPageShowsOursAndTheShopsOwnApart(): void
    {
        $this->store('9601', [['8961', '40201', 599]]);
        $web = $this->signIn($this->uiUser('finance'));
        $one = $web->get('/ui/profit/orders/view', ['store' => (int) $this->vb->channelId, 'ref' => '9601']);
        self::assertSame(200, $one->status, $one->describe());
        $money = self::pairs($one, 'profit-order-money');
        self::assertSame(['£0.00', '£0.00'], [$money['Goods sold'] ?? null, $money['Buying cost'] ?? null], 'ours: nothing of ours on this order');
        self::assertStringStartsWith('£0.00', (string) ($money['Profit'] ?? ''), 'never the shop\'s £1.24 as ours');
        self::assertStringContainsString('VAPEBIG test site\'s own goods on this order', self::text($one));
        $own = self::pairs($one, 'profit-order-own');
        self::assertSame(['£4.99', '£3.75'], [$own['Goods sold'] ?? null, $own['Buying cost'] ?? null]);
        self::assertStringStartsWith('£1.24', (string) ($own['Profit'] ?? ''));
        // an order of ours re-sent with the shop's own goods
        $this->store('9602', [['8962', '40203', 1200]]);
        $this->store('9603', [['8963', '40201', 599]], '9602');
        $first = $web->get('/ui/profit/orders/view', ['store' => (int) $this->vb->channelId, 'ref' => '9602']);
        $money = self::pairs($first, 'profit-order-money');
        self::assertSame('£0.00', $money['Re-sends\' cost'] ?? null, 'the re-sent goods are the shop\'s');
        self::assertStringStartsWith('£8.00', (string) ($money['Profit with its re-sends'] ?? ''));
        self::assertSame('£3.75', self::pairs($first, 'profit-order-own')['Re-sends\' cost'] ?? null);
        self::assertStringNotContainsStringIgnoringCase('partner', $first->body);
    }

    public function testM20NoPriceListsTheShopsOwnItemsApartAndRealProfitNeverShowsItsSalesAsProfit(): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', ['"2026-11-01"', 'profit.real_from_vapebig']);
        $this->store('9604', [['8964', '40201', 599], ['8965', '40204', 599]]);
        $fin = $this->signIn($this->uiUser('finance'));
        $page = $fin->get('/ui/profit/no-cost', ['period' => 'last_7', 'store' => (int) $this->vb->channelId]);
        self::assertSame(200, $page->status, $page->describe());
        $t = self::text($page);
        self::assertStringContainsString('VAPEBIG test site\'s own items with no price', $t);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//table[contains(@class, "profit-no-cost-own")]//a[contains(., "Elux Legend no price R1")]')->length);
        self::assertSame(0, $xp->query('//table[contains(@class, "profit-no-cost ")]//a[contains(., "Elux Legend no price R1")]')->length, 'never in our list');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $real = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d'), 'to' => $day,
            'store' => (int) $this->vb->channelId]);
        self::assertSame(200, $real->status, $real->describe());
        $line = (new \DOMXPath($real->dom()))->query('//p[contains(@class, "own-goods-line")]')->item(0);
        self::assertNotNull($line);
        $text = trim((string) preg_replace('/\s+/u', ' ', $line->textContent));
        self::assertStringContainsString('its profit is not known yet', $text);
        self::assertStringNotContainsString('profit £', $text, 'the shop\'s sales are never shown as its profit while a unit has no price');
    }
}
