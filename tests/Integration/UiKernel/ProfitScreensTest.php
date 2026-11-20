<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Profit section through the real /ui kernel as cw_app (profit step 1; docs/decisions.md PR23-PR28): only the Finance job sees it (the
 * section, every page, 403 for every other job, admin and the owner's own jobs included); each page draws its figures from the stored orders
 * (the summary, by day / week / month, by shop / product / brand / supplier with its sorts, the orders with the search and lowest margin
 * first, one order's page, the no buying price list); every table turns into cards on a phone (each cell names its column).
 */
final class ProfitScreensTest extends KernelUiTestCase
{
    /** @return array{0: Caller, 1: int, 2: int} the store, an item with a price and an item without one, and their orders */
    private function orders(): array
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Profit item A');
        $b = $this->item('legacy', 0, 'Profit item B');
        self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', ['Elux', $a]);
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16 00:00:00', '2026-11-16 00:00:00', 'start', ?, 5000)",
            [$a, 'start:' . $a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')"); // the cost job has read everything
        $this->listing($site, '11', $a);
        $this->listing($site, '12', $b);
        $paid = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z'); // today (UK)
        $body = static fn (array $lines, array $over = []): array => $over + ['v' => 1, 'version' => 1, 'order_no' => 'VPG100', 'status' => 'completed',
            'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '1', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 360, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0,
            'parcels' => [['parcel_ref' => 'odl:1', 'tracking_no' => 'TT123456', 'carrier' => 'royal_mail', 'service_code' => 'TPN', 'service' => 'Tracked 48',
                'status' => 'generated', 'label_at' => $paid, 'shipped_at' => null, 'manual' => false, 'partial' => false]]];
        $line = static fn (string $id, string $variant, int $price, array $over = []): array => $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1,
            'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false];
        $s = new OrderSnapshots(self::$db);
        // 100: two units of A at £1.20 (cost 50p each) and one of B (no price); 101: a low-margin order of A; 102: a re-send of 100.
        $s->store((int) $site->channelId, '100', $body([$line('1', '11', 120), $line('2', '11', 120), $line('3', '12', 600)]), 'api');
        $s->store((int) $site->channelId, '101', $body([$line('4', '11', 66)], ['order_no' => 'VPG101']), 'api');
        $s->store((int) $site->channelId, '102', $body([$line('5', '11', 0, ['free' => true])], ['order_no' => 'VPG102', 'parent_ref' => '100', 'delivery' => null,
            'parcels' => []]), 'api');
        return [$site, $a, $b];
    }

    /** @return list<list<string>> the rows of a table (by class) as their cells' text */
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

    /** Every data cell of every table names its column (the phone's cards: 390 px, docs/decisions.md PR24). */
    private static function phoneReady(UiResponse $r): void
    {
        $xp = new \DOMXPath($r->dom());
        foreach ($xp->query('//table[contains(@class, "rp-table")]') ?: [] as $t) {
            self::assertStringContainsString('stack', (string) $t->attributes?->getNamedItem('class')?->nodeValue, 'a table that turns into cards');
            foreach ($xp->query('.//thead/following-sibling::tbody/tr/td', $t) ?: [] as $td) {
                self::assertNotSame('', (string) $td->attributes?->getNamedItem('data-label')?->nodeValue, 'each cell names its column on a phone');
            }
        }
        self::assertStringNotContainsString('style=', $r->body);
    }

    public function testOnlyTheFinanceJobSeesTheProfit(): void
    {
        $this->orders();
        $fin = $this->signIn($this->uiUser('finance'));
        $home = $fin->get('/ui/profit');
        self::assertSame(200, $home->status, $home->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([Words::SECTION['profit'], Words::MENU['profit_summary']], [self::currentSection($home), self::currentTab($home)]);
        self::assertSame([Words::MENU['profit_summary'], Words::MENU['profit_periods'], Words::MENU['profit_breakdown'], Words::MENU['profit_orders'],
            Words::MENU['profit_no_cost']], array_column(self::sectionTabs($home), 'label'));
        $rev = $this->signIn($this->uiUser('reviewer'));
        foreach (['/ui/profit' => [], '/ui/profit/periods' => [], '/ui/profit/by' => [], '/ui/profit/orders' => [], '/ui/profit/no-cost' => [],
            '/ui/profit/orders/view' => ['store' => '1', 'ref' => '100']] as $path => $query) {
            self::assertSame(403, $rev->get($path, $query)->status, "the owner's reviewer job alone: {$path}");
        }
        self::assertNotContains(Words::SECTION['profit'], array_keys(self::nav($rev->get('/ui/'))), 'no Profit in the menu');
        self::assertContains(Words::SECTION['profit'], array_keys(self::nav($home)));
        foreach (['reviewer', 'accountant', 'auditor', 'admin', 'buyer', 'purchasing_manager', 'people_manager'] as $job) {
            self::assertSame(403, $this->signIn($this->uiUser($job))->get('/ui/profit')->status, $job);
        }
        self::assertSame(200, $this->signIn($this->uiUser(['finance', 'reviewer']))->get('/ui/profit/orders')->status, 'Finance goes with another job');
    }

    public function testEveryPageDrawsItsFigures(): void
    {
        [$site, $a, $b] = $this->orders();
        $fin = $this->signIn($this->uiUser('finance'));

        // The summary: today's row. Goods: 100 + 100 + 500 + 55 + 0 = £7.55; cost: 50p x 4 = £2.00 (the re-send's unit included).
        $sum = $fin->get('/ui/profit');
        self::phoneReady($sum);
        $today = self::rows($sum, 'profit-summary')[0];
        self::assertSame([Words::PROFIT['today'], '2', '£7.55', '£2.00', '£5.55', '73.5% ' . Words::PROFIT['incomplete'], '£6.00', '1'], $today,
            'two orders (the re-send is not one); 1 unit with no price: the margin says it is incomplete');
        self::assertSame(200, $fin->get('/ui/profit', ['store' => (string) $site->channelId])->status);

        $days = $fin->get('/ui/profit/periods', ['by' => 'week']);
        self::assertSame(200, $days->status, $days->describe());
        self::phoneReady($days);
        self::assertSame(Words::PROFIT['total'], self::rows($days, 'profit-periods')[1][0]);
        self::assertSame('£5.55', self::rows($days, 'profit-periods')[1][5]);

        $by = $fin->get('/ui/profit/by', ['by' => 'product']);
        self::assertSame(200, $by->status, $by->describe());
        self::phoneReady($by);
        self::assertSame([['Profit item B ' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$b]), '1', '£5.00', '£0.00', '£5.00', '100.0% ' . Words::PROFIT['incomplete'], '1'],
            ['Profit item A ' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$a]), '4', '£2.55', '£2.00', '£0.55', '21.6%', '0']], self::rows($by, 'profit-breakdown'),
            'the biggest profit first');
        self::assertSame('Profit item A', trim((string) (new \DOMXPath($fin->get('/ui/profit/by', ['by' => 'product', 'sort' => 'margin_low'])->dom()))
            ->query('//table[contains(@class, "profit-breakdown")]/tbody/tr[1]/th/a')?->item(0)?->textContent), 'the lowest margin first');
        self::assertSame(['Elux', Words::PROFIT['no_brand']], array_column(self::rows($fin->get('/ui/profit/by', ['by' => 'brand', 'sort' => 'units']), 'profit-breakdown'), 0));
        self::assertSame([Words::PROFIT['no_supplier']], array_column(self::rows($fin->get('/ui/profit/by', ['by' => 'supplier']), 'profit-breakdown'), 0));
        self::assertSame(['VAPEANDGO test site'], array_column(self::rows($fin->get('/ui/profit/by', ['by' => 'shop']), 'profit-breakdown'), 0));

        $list = $fin->get('/ui/profit/orders');
        self::assertSame(200, $list->status, $list->describe());
        self::phoneReady($list);
        self::assertCount(3, self::rows($list, 'profit-orders'));
        self::assertSame('VPG101', explode(' ', self::rows($fin->get('/ui/profit/orders', ['low' => '1']), 'profit-orders')[0][0])[0], 'the lowest margin first');
        self::assertSame([['VPG101', 'VAPEANDGO test site']], array_map(static fn (array $r): array => array_slice($r, 0, 2),
            self::rows($fin->get('/ui/profit/orders', ['q' => 'VPG101']), 'profit-orders')), 'by the number the customer sees');
        self::assertCount(1, self::rows($fin->get('/ui/profit/orders', ['q' => '100']), 'profit-orders'), 'by the shop\'s order id');
        self::assertSame(200, $fin->get('/ui/profit/orders', ['q' => "<script>'"])->status, 'a search that is no order number finds nothing, safely');

        $one = $fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '100']);
        self::assertSame(200, $one->status, $one->describe());
        self::phoneReady($one);
        $text = $one->text();
        foreach ([Words::PROFIT['source_cw_cost'], Words::PROFIT['source_none'], Words::PROFIT['resends_list'], 'VPG102', 'TT123456', Words::PROFIT['with_resends'],
            '£0.50'] as $want) {
            self::assertStringContainsString($want, $text);
        }
        self::assertSame(404, $fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => 'nope'])->status);
        self::assertSame(404, $fin->get('/ui/profit/orders/view', ['store' => 'x', 'ref' => '100'])->status);
        $child = $fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '102']);
        self::assertStringContainsString(Words::say('PROFIT', 'resend_of', '100'), $child->text());

        $none = $fin->get('/ui/profit/no-cost');
        self::assertSame(200, $none->status, $none->describe());
        self::phoneReady($none);
        self::assertSame([['Profit item B ' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$b]), '1', '£5.00', '1']], self::rows($none, 'profit-no-cost'));
        self::assertSame(1, (new \DOMXPath($none->dom()))->query('//table[contains(@class, "profit-no-cost")]//a[@href="/ui/items/' . $b . '"]')?->length,
            'a link to the product\'s page');
        self::assertSame([], self::rows($fin->get('/ui/profit/no-cost', ['period' => 'last_month']), 'profit-no-cost'));
    }
}
