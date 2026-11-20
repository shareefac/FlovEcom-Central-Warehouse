<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Codex re-check of 57d98ad (build/A/codex_r2.txt), the screens, each shown failing first: a line with no price shows no profit as if
 * it were known, and a list sorted by margin puts the incomplete rows after the others (c6); the lowest margin first is bounded (c2); a page
 * cursor that is not a real time starts again from the first page (N11).
 */
final class ProfitScreensReviewR2Test extends KernelUiTestCase
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

    public function testUnknownFiguresAreNeverShownAsKnown(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Priced');
        $b = $this->item('legacy', 0, 'Not priced');
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, 5000, 'erp_bin', '2026-11-16', '2026-11-16')", [$a]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, 5000)", [$a, 'start:' . $a]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')");
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $paid = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', ['v' => 1, 'version' => 1, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => $paid,
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [
                ['line_id' => 'l1', 'variant_id' => '1', 'qty' => 1, 'price_inc' => 120, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false],
                ['line_id' => 'l2', 'variant_id' => '2', 'qty' => 1, 'price_inc' => 600, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]],
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 720, 'parcels' => []], 'api');
        $fin = $this->signIn($this->uiUser('finance'));
        $lines = self::rows($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '1']), 'profit-order-lines');
        $notPriced = array_values(array_filter($lines, static fn (array $r): bool => str_starts_with($r[0], 'Not priced')))[0];
        self::assertSame([Words::PROFIT['unknown'], Words::PROFIT['unknown']], array_slice($notPriced, 6, 2), 'no profit and no margin shown as if known');
        self::assertSame('Priced', explode(' ', self::rows($fin->get('/ui/profit/by', ['by' => 'product', 'sort' => 'margin']), 'profit-breakdown')[0][0])[0],
            'the highest margin first, of the rows whose margin is known');
        self::assertSame(200, $fin->get('/ui/profit/orders', ['ak' => '2026-99-99 99:99:99.000000', 'ac' => '1', 'ar' => 'x'])->status, 'a cursor that is no time: the first page');
        $far = $fin->get('/ui/profit/orders', ['low' => '1', 'period' => 'choose', 'from' => '2026-01-01', 'to' => '2026-11-20']);
        self::assertSame(200, $far->status);
        self::assertStringContainsString(Words::say('PROFIT', 'lowest_max', 93), $far->text());
    }
}
