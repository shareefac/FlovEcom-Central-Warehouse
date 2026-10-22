<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\Ops\AlertDispatch;
use CW\Ops\Alerts;
use CW\Ops\AlertTransport;
use CW\Queues\LandingOrders;
use CW\Settings;
use CW\Tests\Support\MappingTestCase;
use CW\Ui\Words;

/**
 * An order for a landing page (docs/decisions.md LP7-LP10): a website bug, never a stock event. The line is an unlinked line as
 * before (no bucket moves, never refused, the same answer to the site); it is written to the website-bug list in the order's own
 * transaction (one row per order and page; a later payment adds to it, and opens it again when it was handled before the payment);
 * health_alert's checks raise one alert per store while one is open; an unmatched REAL product's line is exactly as before.
 */
final class LandingOrderTest extends MappingTestCase
{
    private const TITLE = 'Blue Razz Cherry Elux Legend Nic Salt 10ml';

    /** @return array{0: Caller, 1: int, 2: int, 3: int} the store, a strict item matched to V1, the landing page 960, a real unmatched product U1 */
    private function shop(): array
    {
        $site = $this->site('electrofag');
        self::$db->exec("UPDATE channel SET name = 'Electrofag' WHERE id = ?", [$site->channelId]);
        $item = $this->item('strict', 10);
        $this->listing($site, 'V1', $item);
        $landing = $this->listing($site, '960', null);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title) VALUES (?, ?, ?)', [$landing, self::TITLE, self::TITLE]);
        $this->decide($this->staffUser('mapping_lead'), 'landing', $landing);
        $real = $this->listing($site, 'U1', null);
        return [$site, $item, $landing, $real];
    }

    public function testAnOrderForALandingPageMovesNoStockAndGoesToTheWebsiteBugs(): void
    {
        [$site, $item, $landing] = $this->shop();
        $ledger = $this->ledgerCount();
        $lines = [self::line('V1', 'u1'), self::line('960', 'u2', 'u3')];
        $r = $this->ok($this->reserve($site, '5001', $lines));
        self::assertSame(201, $r->status);
        $byVariant = array_column($r->body['lines'], null, 'variant_id');
        self::assertSame(['unlinked', 'unlinked', null], [$byVariant['960']['kind'], $byVariant['960']['result'], $byVariant['960']['sku_code']],
            'the site is answered what it always was');
        $this->assertBal(10, 0, 1, $item);
        self::assertSame($ledger + 1, $this->ledgerCount(), 'only the real line moved a bucket');
        self::assertSame([null, null], self::$db->column("SELECT sku_id FROM reservation_unit WHERE unit_id IN ('u2', 'u3')"));
        $row = self::$db->one('SELECT * FROM landing_order');
        self::assertSame([$landing, '5001', 'reserve', 2, 0, 'open'], [(int) $row['listing_id'], $row['order_ref'], $row['seen_in'], (int) $row['units'], (int) $row['paid'], $row['status']]);
        self::assertSame((int) $this->reservation($site, '5001')['id'], (int) $row['reservation_id']);

        $this->ok($this->commit($site, '5001', $lines));
        $this->assertBal(10, 1, 0, $item);
        self::assertSame([1, 1, 'open'], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            (array) self::$db->one('SELECT COUNT(*) n, MAX(paid) paid, MAX(status) s FROM landing_order'))), 'one row, now paid');
        $this->ok($this->ship($site, '5001', ['u1', 'u2', 'u3']));
        $this->assertBal(9, 0, 0, $item);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE unit_id IN ('u2', 'u3')"), 'never a stock event');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'landing_order.flag'"));

        // The alert: one per store while one is open, in words.
        $problems = array_values(array_filter((new Alerts(self::$db, static fn (): float => 1.0))->problems(new Settings(self::$db)),
            static fn (array $p): bool => $p['kind'] === 'landing_order'));
        self::assertSame(['landing_order:electrofag'], array_column($problems, 'key'));
        self::assertSame(Words::say('ALERT', 'landing_order', 'Electrofag', self::TITLE, '960', '5001'), AlertDispatch::line($problems[0]));
        self::assertStringStartsWith('Website bug: an order on Electrofag for the landing page ' . self::TITLE, AlertDispatch::line($problems[0]));
        // a second order: still one alert for the store, saying how many
        $this->ok($this->commit($site, '5002', [self::line('960', 'u9')], 'unreserved'));
        $problems = array_values(array_filter((new Alerts(self::$db, static fn (): float => 1.0))->problems(new Settings(self::$db)),
            static fn (array $p): bool => $p['kind'] === 'landing_order'));
        self::assertCount(1, $problems);
        self::assertStringContainsString('2 such orders are open', AlertDispatch::line($problems[0]));
    }

    public function testTheAlertIsSentOncePerWindowAndClearsWhenTheOrdersAreHandled(): void
    {
        [$site] = $this->shop();
        $this->ok($this->commit($site, '6001', [self::line('960', 'x1')], 'unreserved'));
        $transport = new class () implements AlertTransport {
            /** @var list<string> */
            public array $sent = [];

            public function webhook(string $url, array $payload, int $timeoutSec): void
            {
                $this->sent[] = (string) $payload['text'];
            }

            public function email(string $to, string $subject, string $body): void
            {
            }
        };
        self::$db->exec("UPDATE app_setting SET value_json = '\"https://alerts.example.com/hook\"' WHERE setting_key = 'alerts.webhook_url'");
        try {
            $run = function () use ($transport): array {
                $problems = array_values(array_filter((new Alerts(self::$db, static fn (): float => 1.0))->problems(new Settings(self::$db)),
                    static fn (array $p): bool => $p['kind'] === 'landing_order'));
                return (new AlertDispatch(self::$db, $transport))->run($problems, new Settings(self::$db), 'cw_test');
            };
            self::assertSame(['landing_order:electrofag'], $run()['sent']);
            self::assertSame([], $run()['sent'], 'inside its window: not sent again');
            self::assertStringContainsString('Website bug: an order on Electrofag', $transport->sent[0]);
            $id = (int) self::$db->value('SELECT id FROM landing_order');
            (new LandingOrders(self::$db))->handle($this->staffUser('stock_controller'), $id, 'fixed_by_hand', 'sent the 20mg instead');
            self::assertSame(['landing_order:electrofag'], $run()['cleared']);
            self::assertStringContainsString('Back to normal', $transport->sent[1]);
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = '\"\"' WHERE setting_key = 'alerts.webhook_url'");
        }
    }

    public function testAPaymentAfterItWasHandledOpensItAgainAndHandlingNeedsTheJob(): void
    {
        [$site] = $this->shop();
        $this->ok($this->reserve($site, '7001', [self::line('960', 'y1')]));
        $id = (int) self::$db->value('SELECT id FROM landing_order');
        $orders = new LandingOrders(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $orders->handle($this->staffUser('mapper'), $id, 'no_action', 'not mine'));
        self::refused(400, 'bad_field', fn () => $orders->handle($this->staffUser('stock_controller'), $id, 'shrug', 'what'));
        $orders->handle($this->staffUser('stock_controller'), $id, 'no_action', 'basket left, never paid');
        self::refused(409, 'queue_settled', fn () => $orders->handle($this->staffUser('stock_controller'), $id, 'no_action', 'again'));
        self::assertSame(0, $orders->openCount());
        $this->ok($this->commit($site, '7001', [self::line('960', 'y1')]));
        self::assertSame(['open', 1, null], array_values(array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            (array) self::$db->one('SELECT CAST(status AS CHAR) s, paid, resolved_at FROM landing_order WHERE id = ?', [$id]))), 'paid after it was handled: open again');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'landing_order.reopened' AND entity_id = ?", [(string) $id]));
        self::assertSame(1, $orders->openCount());
    }

    public function testThePurgeOfATestStoreKeepsAnOrderThatIsAWebsiteBug(): void
    {
        [$site] = $this->shop();
        $this->ok($this->reserve($site, 'tst-9', [self::line('960', 'w1')]));
        $this->ok($this->reserve($site, 'tst-10', [self::line('U1', 'w2')]));
        self::$db->exec("UPDATE channel SET mode = 'shadow' WHERE id = ?", [$site->channelId]);
        $purge = new \CW\Ops\TestRefPurge(self::$db, $this->res);
        $plan = array_column($purge->plan('electrofag', 'tst-')['reservations'], null, 'order_ref');
        self::assertSame([false, ['landing_order']], [$plan['tst-9']['delete'], $plan['tst-9']['keep_why']]);
        self::assertTrue($plan['tst-10']['delete']);
        $done = $purge->apply('electrofag', 'tst-', null, 'tester');
        self::assertSame([['order_ref' => 'tst-9', 'why' => ['landing_order']]], $done['done']['kept']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM landing_order WHERE reservation_id IS NOT NULL'), 'the website bug and its order stay');
        self::assertNull($this->reservation($site, 'tst-10'));
    }

    public function testMarkingAPageListsItsOpenOrdersAsWebsiteBugsCodexR1(): void
    {
        // A landing page linked by mistake took an order (its units hold the item they were sold under, I14), and an unmatched one
        // was paid for: once a person undoes the match and the page is marked, both orders are on the website-bug list.
        $site = $this->site('electrofag');
        $item = $this->item('legacy', 10);
        $wrong = $this->listing($site, '960', $item);
        $this->ok($this->reserve($site, 'R1', [self::line('960', 'r1')]));
        $paid = $this->listing($site, '961', null);
        $this->ok($this->commit($site, 'C1', [self::line('961', 'c1', 'c2')], 'unreserved'));
        $lead = $this->staffUser('mapping_lead');
        $this->decide($lead, 'unlink', $wrong, ['reason' => 'a landing page, not the 10mg']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM landing_order'));
        $this->decide($lead, 'landing', $wrong);
        $this->decide($lead, 'landing', $paid);
        $rows = self::$db->all('SELECT order_ref, listing_id, seen_in, units, paid FROM landing_order ORDER BY order_ref');
        self::assertSame([['C1', $paid, 'commit', 2, 1], ['R1', $wrong, 'reserve', 1, 0]], array_map(static fn (array $r): array => [$r['order_ref'], (int) $r['listing_id'],
            $r['seen_in'], (int) $r['units'], (int) $r['paid']], $rows));
        $this->assertBal(10, 0, 1, $item);
        self::assertSame(1, (int) self::$db->value("SELECT JSON_EXTRACT(detail, '$.website_bugs') FROM audit_log WHERE action = 'mapping.landing' AND entity_id = ?",
            [(string) $wrong]), 'the mark says how many open orders it listed');
    }

    public function testAnErpLineNamingALandingPageWaitsForAPersonCodexR2(): void
    {
        [$site] = $this->shop();
        $this->grant($site, 'goods_in', 'erp_sale');
        $other = $this->item('strict', 4);
        self::$db->exec("INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES ('ERP-960', ?, 1)", [$other]);
        $this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-SINV-1', 'lines' => [['erp_item_code' => 'ERP-960', 'variant_id' => '960', 'qty' => 1,
            'line_index' => 0]]], 'relay-sinv-1');
        $this->assertBal(4, 0, 0, $other);
        self::assertSame(['landing_page', '960'], array_values(array_map('strval', (array) self::$db->one('SELECT reason, external_variant_id FROM goods_in_suspense'))),
            'a person places it: Lines to place');
        self::assertArrayHasKey('landing_page', Words::RELAY_REASON);
    }

    public function testAnAnswerFromBeforeThePaymentIsRefusedCodexR6(): void
    {
        [$site] = $this->shop();
        $this->ok($this->reserve($site, '9001', [self::line('960', 'p1')]));
        $id = (int) self::$db->value('SELECT id FROM landing_order');
        $this->ok($this->commit($site, '9001', [self::line('960', 'p1')]));
        $orders = new LandingOrders(self::$db);
        self::refused(409, 'queue_changed', fn () => $orders->handle($this->staffUser('stock_controller'), $id, 'no_action', 'never paid', 0));
        self::assertSame(1, $orders->openCount());
        $orders->handle($this->staffUser('stock_controller'), $id, 'fixed_by_hand', 'sent the 10mg', 1);
        self::assertSame(0, $orders->openCount());
    }

    public function testAnUnmatchedRealProductsLineIsExactlyAsBefore(): void
    {
        [$site, , , $real] = $this->shop();
        $r = $this->ok($this->commit($site, '8001', [self::line('U1', 'z1'), self::line('NEW', 'z2')], 'unreserved'));
        self::assertSame([], $r->body['oversell']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM landing_order'), 'not a website bug');
        self::assertSame([null, null], self::$db->column("SELECT sku_id FROM reservation_unit WHERE unit_id IN ('z1', 'z2')"));
        // linked later: its units are adopted into the item's buckets, as before (R4)
        $item = $this->item('legacy');
        $this->decide($this->staffUser('mapping_lead'), 'link', $real, ['sku_id' => $item]);
        $this->assertBal(0, 1, 0, $item);
        self::assertSame('unmapped', (string) self::$db->value("SELECT status FROM channel_listing WHERE external_variant_id = 'NEW'"));
    }
}
