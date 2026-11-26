<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Profit\OrderSnapshots;
use CW\Settings;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Profit > Card fees, an order's card fee and the Real profit line through the real /ui kernel as cw_app (CW-1174; docs/decisions.md
 * GPF10-GPF12). Finance and the Expenses job look; only the Expenses job enters, changes and deletes an invoice (CSRF and one effect per
 * form on the add; a reason on a change or delete); the page says how the estimate works, why an invoice goes on the calendar month and that
 * a fixed charge of an account with no card payments is a regular cost; Real profit shows the card fees line, "estimated" until the month's
 * invoice is in; an order's page shows its card fee.
 */
final class GpFeesScreensTest extends KernelUiTestCase
{
    private int $vpg = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
        $s = new Settings(self::$db);
        $s->set(Caller::system('test'), 'profit.gp_from_vpg_online', '2026-11-01', 'switched on for the test');
        $s->set(Caller::system('test'), 'profit.gp_merchant_vpg_online', '55829291', 'the merchant number');
        foreach ([['9100001', '2026-11-19T10:00:00Z', 2999], ['9100002', '2026-11-20T10:00:00Z', 2001]] as [$ref, $paid, $grand]) {
            (new OrderSnapshots(self::$db))->store($this->vpg, $ref, ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid,
                'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0,
                'grand_inc' => $grand, 'parcels' => []], 'api');
        }
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

    public function testTheJobEntersAMonthsInvoiceAndRealProfitFollows(): void
    {
        $fin = $this->signIn($this->uiUser('finance'));
        $real = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        self::assertSame(200, $real->status, $real->describe() . ' ' . implode(' | ', self::$log));
        $line = array_column(self::rows($real, 'real-profit'), 1, 0)[Words::PROFIT['real_gp']] ?? '';
        self::assertStringContainsString('£0.50', $line, '30p + 20p estimated');
        self::assertStringContainsString(Words::PROFIT['real_gp_estimated'], $line);
        $page = $fin->get('/ui/profit/card-fees');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        foreach (['gp_how', 'gp_period_note', 'gp_fixed_note'] as $k) {
            self::assertStringContainsString(Words::PROFIT[$k], $page->text(), $k);
        }
        self::assertStringNotContainsString('action="/ui/profit/card-fees/new"', $page->body, 'Finance looks only');
        self::assertSame(403, $fin->post('/ui/profit/card-fees/new', ['csrf' => 'x'])->status);

        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/card-fees');
        self::assertSame(200, $page->status, $page->describe());
        $form = ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '0000000055829291', 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0.00', 'total' => '1.00', 'use_rate' => '1', 'current_ok' => '1'] + $page->form('/ui/profit/card-fees/new');
        $r = $job->post('/ui/profit/card-fees/new', $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value("SELECT id FROM gp_invoice WHERE account = 'vpg_online' AND month = '2026-11'");
        self::assertSame(303, $job->post('/ui/profit/card-fees/new', $form)->status, 'the same form again: the same answer');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM gp_invoice'), 'one effect per form');
        $one = $job->get("/ui/profit/card-fees/{$id}");
        self::assertSame(200, $one->status, $one->describe());
        self::assertStringContainsString(Words::say('PROFIT', 'gp_spread_line', '2', '£50.00', '£1.00', '£0.00'), $one->text());
        $real = $fin->get('/ui/profit/real', ['period' => 'choose', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $line = array_column(self::rows($real, 'real-profit'), 1, 0)[Words::PROFIT['real_gp']] ?? '';
        self::assertStringContainsString('£1.00', $line, 'the invoice\'s real charge');
        self::assertStringNotContainsString(Words::PROFIT['real_gp_estimated'], $line);
        $order = $fin->get('/ui/profit/orders/view', ['store' => $this->vpg, 'ref' => '9100001']);
        self::assertSame(200, $order->status, $order->describe());
        self::assertStringContainsString(Words::PROFIT['gp_table'], $order->text());
        self::assertStringContainsString(Words::say('PROFIT', 'gp_order_actual', '2026-11', '£29.99'), $order->text());

        // a change needs a reason; then the month is shared again
        $f = $one->form("/ui/profit/card-fees/{$id}/change");
        self::assertSame(400, $job->post("/ui/profit/card-fees/{$id}/change", ['net' => '2.00', 'total' => '2.00', 'reason' => ''] + $f)->status, 'no reason: refused');
        $r = $job->post("/ui/profit/card-fees/{$id}/change", ['net' => '2.00', 'total' => '2.00', 'reason' => 'typed wrongly'] + $f);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(200, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'"));
        // deleted with a reason: back to estimates
        $d = $job->get("/ui/profit/card-fees/{$id}")->form("/ui/profit/card-fees/{$id}/delete");
        $r = $job->post("/ui/profit/card-fees/{$id}/delete", ['reason' => 'the wrong statement', 'sure' => '1'] + $d);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(100, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'estimate'"),
            'at the rate the invoice set (2%): 60p + 40p');
    }

    public function testARefusalComesBackWithTheFormAsTyped(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/card-fees');
        $form = ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '55829371', 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0.00', 'total' => '1.00', 'current_ok' => '1'] + $page->form('/ui/profit/card-fees/new');
        $r = $job->post('/ui/profit/card-fees/new', $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString('the merchant number is not this account', $r->text());
        self::assertSame('55829371', $r->form('/ui/profit/card-fees/new')['merchant_no'] ?? null, 'the form as typed');
        // far from CW's card takings: asks for the tick
        $form = ['merchant_no' => '55829291', 'sales' => '500.00'] + $form;
        $r = $job->post('/ui/profit/card-fees/new', $form + $r->form('/ui/profit/card-fees/new'));
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('PROFIT', 'gp_gap', '£50.00', '£500.00'), $r->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_invoice'));
    }
    /**
     * The final review of 7332729: Codex M15 (the list's CW card takings are the invoice page's: Vape Big's card orders before its own first day
     * are in them too) and Fable F5 (the form offers last month; the month still running needs its own tick).
     */
    public function testTheListShowsTheInvoicesTakingsAndOffersLastMonth(): void
    {
        $vb = (int) self::$db->value("SELECT id FROM channel WHERE code = 'vapebig'");
        (new OrderSnapshots(self::$db))->store($vb, '9200001', ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => '2026-11-19T11:00:00Z',
            'paid_at' => '2026-11-19T11:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null,
            'order_refund_inc' => 0, 'grand_inc' => 5000, 'parcels' => []], 'api');
        $job = $this->signIn($this->uiUser('expenses'));
        $page = $job->get('/ui/profit/card-fees');
        self::assertSame(200, $page->status, $page->describe());
        $nov = array_values(array_filter(self::rows($page, 'card-fees'), static fn (array $r): bool => $r[0] === '2026-11'))[0] ?? [];
        self::assertSame('£100.00', $nov[1] ?? null, 'Vape and Go\'s £50.00 and Vape Big\'s £50.00 before its own first day: what the invoice holds');
        self::assertSame(10000, \CW\Profit\GpInvoices::cwTakings(self::$db, 'vpg_online', '2026-11'));
        $today = \CW\Profit\ProfitViews::today();
        $running = substr($today, 0, 7);
        $last = substr((new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 day')->format('Y-m-d'), 0, 7);
        self::assertSame($last, $page->form('/ui/profit/card-fees/new')['month'] ?? null, 'last month by default: its invoice comes early this month');
        $form = ['account' => 'vpg_online', 'month' => $running, 'merchant_no' => '55829291', 'invoice_date' => $running . '-01', 'sales' => '100.00',
            'net' => '1.00', 'vat' => '0.00', 'total' => '1.00', 'gap_ok' => '1'] + $page->form('/ui/profit/card-fees/new');
        $r = $job->post('/ui/profit/card-fees/new', $form);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::PROFIT['gp_current_month'], $r->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_invoice'));
        $r = $job->post('/ui/profit/card-fees/new', ['current_ok' => '1'] + $form + $r->form('/ui/profit/card-fees/new'));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM gp_invoice'));
    }

    /**
     * The final review of 7332729 (Codex M14): a change sent from a page opened before a colleague's correction is refused, and the page comes
     * back with the invoice as it is now (its new version): the old figures are never sent again by a second press.
     */
    public function testAStaleChangeComesBackWithTheCurrentFigures(): void
    {
        $job = $this->signIn($this->uiUser('expenses'));
        $inv = ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => '55829291', 'invoice_date' => '2026-11-30', 'sales' => '50.00', 'net' => '1.00',
            'vat' => '0', 'total' => '1.00', 'note' => '', 'current_ok' => '1'];
        $id = (new \CW\Profit\GpInvoices(self::$db))->add(Caller::system('test'), $inv)['id'];
        $mine = $job->get("/ui/profit/card-fees/{$id}")->form("/ui/profit/card-fees/{$id}/change");
        self::assertSame('1', $mine['seen'] ?? null);
        (new \CW\Profit\GpInvoices(self::$db))->change(Caller::system('test'), $id, 1, ['net' => '2.00', 'total' => '2.00'] + $inv, 'a colleague corrected it');
        $r = $job->post("/ui/profit/card-fees/{$id}/change", ['net' => '3.00', 'total' => '3.00', 'reason' => 'from my old page'] + $mine);
        self::assertSame(409, $r->status, $r->describe());
        $f = $r->form("/ui/profit/card-fees/{$id}/change");
        self::assertSame(['2', '2.00', '2.00'], [$f['seen'] ?? null, $f['net'] ?? null, $f['total'] ?? null],
            'the invoice as it is now, with its new version: a second press never sends the old figures');
        self::assertSame(200, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'"));
    }
}
