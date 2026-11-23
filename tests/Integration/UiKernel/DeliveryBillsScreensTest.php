<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\DpdCsv;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Profit > Delivery bills through the real /ui kernel as cw_app (docs/decisions.md DL23), from SYNTHETIC DPD files: Finance and the Delivery
 * bills job see the bills, only the job uploads, replaces and undoes (403 for every other job, admin included; CSRF on every POST); the
 * upload stores a bill and lands on its page (its sentence, where its money went adding up to its net, its lines on no order the dearest
 * first), a refused file stores nothing and says why in words, the same file again changes nothing; a corrected file replaces it; Undo asks
 * for its box; an order's page shows its delivery on a table of its own and each parcel's bill line or "not billed yet"; the summary, the
 * days and the shops show delivery charged, its cost, the charges not on an order and delivery after its cost.
 */
final class DeliveryBillsScreensTest extends KernelUiTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_dpd_ui_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
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

    /** Every data cell of every table with a head names its column (the phone's cards). */
    private static function phoneReady(UiResponse $r): void
    {
        $xp = new \DOMXPath($r->dom());
        foreach ($xp->query('//table[contains(@class, "rp-table")]') ?: [] as $t) {
            self::assertStringContainsString('stack', (string) $t->attributes?->getNamedItem('class')?->nodeValue);
            foreach ($xp->query('.//thead/following-sibling::tbody/tr/td', $t) ?: [] as $td) {
                self::assertNotSame('', (string) $td->attributes?->getNamedItem('data-label')?->nodeValue, 'each cell names its column on a phone');
            }
        }
        self::assertStringNotContainsString('style=', $r->body);
    }

    /**
     * Vape and Go with its first reservation on 28 Oct 2026, an order paid today with a DPD parcel labelled today, a re-send of it with its own
     * parcel, an order whose parcel is not billed, and an order a collection is for.
     *
     * @return array{0: int, 1: string} the store and today as DPD prints it
     */
    private function shop(): array
    {
        $ch = (int) $this->site('vapeandgo', 'live')->channelId;
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', "
            . "'2026-10-28 10:28:00', '2026-10-28 10:28:00')", [$ch]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-60 seconds');
        $paid = $now->format('Y-m-d\TH:i:s\Z');
        $label = $now->modify('+20 seconds')->format('Y-m-d\TH:i:s\Z');
        $parcel = static fn (string $ref, string $tracking): array => ['parcel_ref' => $ref, 'tracking_no' => $tracking, 'carrier' => 'dpd', 'service_code' => '12',
            'service' => 'DPD', 'status' => 'generated', 'label_at' => $label, 'shipped_at' => $label, 'manual' => false, 'partial' => false];
        $s = new OrderSnapshots(self::$db);
        $body = static fn (string $no, array $parcels, array $over = []): array => $over + ['v' => 1, 'version' => 1, 'order_no' => $no, 'status' => 'completed',
            'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => '8' . $no, 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]],
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 600, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1800, 'parcels' => $parcels];
        $s->store($ch, '100', $body('1356063', [$parcel('odl:1', '6599195040')]), 'api');
        $s->store($ch, '101', $body('1356064', [$parcel('odl:2', '6599195041')], ['parent_ref' => '100',
            'delivery' => ['method_id' => '5', 'method' => 'DPD', 'charged_inc' => 0, 'refund_inc' => 0]]), 'api');
        $s->store($ch, '102', $body('1356065', [$parcel('odl:3', '6599195042')]), 'api');
        $s->store($ch, '103', $body('1356070', []), 'api');
        return [$ch, (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/Y')];
    }

    /** The week's bill: order 100's parcel 4.20, the re-send's 3.10, a collection on 103 of 15.50, a line on no parcel of 2.50. */
    private function bill(string $today, string $invoice = '62368793', int $first = 420): string
    {
        $path = $this->dir . '/' . $invoice . '-' . $first . '.csv';
        file_put_contents($path, DpdCsv::file([
            DpdCsv::line($today, '6599195040/0', $first),
            DpdCsv::line($today, '6599195041/0', 310),
            DpdCsv::line($today, '6599195099/0', 1550, ['money' => ['Third Party Collection' => 1300, 'Fuel and Energy Charge' => 250], 'Senders Ref' => '1356070']),
            DpdCsv::line($today, '6599100001/0#', 250),
        ], ['invoice' => $invoice]));
        return $path;
    }

    private function upload(KernelBrowser $web, string $path, array $form = []): UiResponse
    {
        $page = $web->get('/ui/profit/delivery');
        self::assertSame(200, $page->status, $page->describe());
        return $web->postMultipart('/ui/profit/delivery/upload', $form + $page->form('/ui/profit/delivery/upload'), ['file' => ['path' => $path, 'name' => 'bill.csv']]);
    }

    public function testFinanceAndTheJobSeeTheBillsAndOnlyTheJobWrites(): void
    {
        [, $today] = $this->shop();
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $fin = $this->signIn($this->uiUser('finance'));
        $list = $fin->get('/ui/profit/delivery');
        self::assertSame(200, $list->status, $list->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([Words::SECTION['profit'], Words::MENU['profit_delivery']], [self::currentSection($list), self::currentTab($list)]);
        self::assertFalse($list->hasForm('/ui/profit/delivery/upload'), 'Finance looks only');
        $mine = $job->get('/ui/profit/delivery');
        self::assertSame(200, $mine->status);
        self::assertTrue($mine->hasForm('/ui/profit/delivery/upload'));
        self::assertSame([Words::MENU['profit_delivery']], array_column(self::sectionTabs($mine), 'label'), 'the job sees the bills, never the profit');
        self::assertSame(403, $job->get('/ui/profit')->status);
        foreach (['reviewer', 'accountant', 'auditor', 'admin', 'buyer', 'people_manager', 'purchasing_desk'] as $other) {
            self::assertSame(403, $this->signIn($this->uiUser($other))->get('/ui/profit/delivery')->status, $other);
        }
        // Finance's POST is refused before the file is read; nothing stored.
        $path = $this->bill($today);
        $r = $fin->postMultipart('/ui/profit/delivery/upload', ['csrf' => $this->token($fin), 'carrier' => 'dpd'], ['file' => ['path' => $path, 'name' => 'bill.csv']]);
        self::assertSame(403, $r->status);
        // No CSRF token: refused.
        $r = $job->postMultipart('/ui/profit/delivery/upload', ['carrier' => 'dpd'], ['file' => ['path' => $path, 'name' => 'bill.csv']]);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        $r = $this->upload($job, $path);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value('SELECT id FROM carrier_invoice');
        self::assertSame("/ui/profit/delivery/{$id}?notice=stored", $r->location());
        $bill = $fin->get("/ui/profit/delivery/{$id}");
        self::assertSame(200, $bill->status, $bill->describe());
        self::assertFalse($bill->hasForm("/ui/profit/delivery/{$id}/undo"), 'Finance cannot undo');
        self::assertFalse($bill->hasForm("/ui/profit/delivery/{$id}/replace"));
        foreach (["/ui/profit/delivery/{$id}/undo", "/ui/profit/delivery/{$id}/replace"] as $action) {
            self::assertSame(403, $fin->post($action, ['csrf' => $this->token($fin), 'sure' => '1'])->status, $action);
        }
        self::assertSame('live', (string) self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$id]));
    }

    public function testAnUploadLandsOnTheBillWhoseMoneyAddsUpToItsNet(): void
    {
        [$ch, $today] = $this->shop();
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $page = $job->follow($this->upload($job, $this->bill($today)));
        self::assertSame(200, $page->status, $page->describe());
        self::phoneReady($page);
        self::assertStringContainsString(Words::DELIVERY['notice_stored'], $page->text());
        $uk = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));
        $days = $uk->format('j M') . ' - ' . $uk->format('j M Y');
        self::assertStringContainsString("DPD bill 62368793 ({$days}): £25.30 - on orders £22.80 (3 lines), not on an order £2.50 (1 lines)", $page->text());
        self::assertSame([
            [Words::DELIVERY['state_matched'], '2', '£7.30'],
            [Words::DELIVERY['state_collection'], '1', '£15.50'],
            [Words::DELIVERY['state_before_start'], '0', '£0.00'],
            [Words::DELIVERY['state_not_matched'], '1', '£2.50'],
            [Words::DELIVERY['state_ambiguous'], '0', '£0.00'],
            [Words::DELIVERY['tie_total'] . ' ' . Words::DELIVERY['adds_up'], '4', '£25.30'],
        ], self::rows($page, 'delivery-tie'));
        // The lines on no order first (the dearest first), with DPD's mark.
        self::assertSame([['4', '6599100001 ' . Words::say('DELIVERY', 'marks', '#'), '£2.50', Words::DELIVERY['state_not_matched']]],
            array_map(static fn (array $r): array => [$r[0], $r[2], $r[5], $r[6]], self::rows($page, 'delivery-lines')));
        $all = $job->get('/ui/profit/delivery/' . (int) self::$db->value('SELECT id FROM carrier_invoice'), ['show' => 'all']);
        self::assertSame(['1', '2', '3', '4'], array_column(self::rows($all, 'delivery-lines'), 0));
        self::assertSame('1356063', self::rows($all, 'delivery-lines')[0][7], 'the order it is on, by the number the customer sees');
        // The list.
        $list = $job->get('/ui/profit/delivery');
        self::phoneReady($list);
        $row = self::rows($list, 'delivery-bills')[0];
        self::assertSame(['DPD bill 62368793 3012002', 'VAPEANDGO test site', '£25.30', '£22.80 (3)', '£2.50 (1)', '£0.00 (0)', Words::DELIVERY['status_live']],
            [$row[0], $row[1], $row[3], $row[4], $row[5], $row[6], $row[8]]);
        // The same file again: nothing changes.
        $again = $this->upload($job, $this->bill($today));
        self::assertSame(303, $again->status);
        self::assertStringEndsWith('?notice=same', (string) $again->location());
        self::assertStringContainsString(Words::DELIVERY['notice_same'], $job->follow($again)->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        // Another file of the same bill: refused with the way to load it (Replace); a file that does not add up: refused, in words.
        $other = $this->upload($job, $this->bill($today, '62368793', 421));
        self::assertSame(409, $other->status);
        self::assertStringContainsString('Replace with a corrected file', $other->text());
        $bad = $this->dir . '/bad.csv';
        file_put_contents($bad, DpdCsv::file([DpdCsv::line($today, '6599195040/0', 420)], ['invoice' => '62368800', 'net' => 400, 'vat' => 80, 'gross' => 480]));
        $refused = $this->upload($job, $bad);
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('the lines add up to £4.20, but the file says its net total is £4.00', $refused->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame((string) $ch, (string) self::$db->value('SELECT channel_id FROM carrier_invoice'));
    }

    public function testACorrectedFileAndAnUndoFromTheBillsPage(): void
    {
        [, $today] = $this->shop();
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $this->upload($job, $this->bill($today));
        $id = (int) self::$db->value('SELECT id FROM carrier_invoice');
        $page = $job->get("/ui/profit/delivery/{$id}");
        $r = $job->postMultipart("/ui/profit/delivery/{$id}/replace", $page->form("/ui/profit/delivery/{$id}/replace"),
            ['file' => ['path' => $this->bill($today, '62368793', 450), 'name' => 'corrected.csv']]);
        self::assertSame(303, $r->status, $r->describe());
        $new = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE status = 'live'");
        self::assertSame("/ui/profit/delivery/{$new}?notice=replaced", $r->location());
        self::assertStringContainsString(Words::say('DELIVERY', 'replaced_by', '62368793'), $job->get("/ui/profit/delivery/{$id}")->text());
        self::assertFalse($job->get("/ui/profit/delivery/{$id}")->hasForm("/ui/profit/delivery/{$id}/undo"), 'a replaced bill has nothing to undo');
        $page = $job->get("/ui/profit/delivery/{$new}");
        $form = $page->form("/ui/profit/delivery/{$new}/undo");
        $notSure = $job->post("/ui/profit/delivery/{$new}/undo", $form);
        self::assertSame(422, $notSure->status);
        self::assertStringContainsString(Words::DELIVERY['undo_tick'], $notSure->text());
        self::assertSame('live', (string) self::$db->value('SELECT status FROM carrier_invoice WHERE id = ?', [$new]));
        $done = $job->post("/ui/profit/delivery/{$new}/undo", ['sure' => '1'] + $form);
        self::assertSame(303, $done->status);
        self::assertStringContainsString(Words::DELIVERY['notice_undone'], $job->follow($done)->text());
        self::assertSame(['superseded', 'undone'], array_map('strval', self::$db->column('SELECT status FROM carrier_invoice ORDER BY id')));
        self::assertSame([Words::DELIVERY['status_undone'], Words::DELIVERY['status_superseded']],
            array_column(self::rows($job->get('/ui/profit/delivery'), 'delivery-bills'), 8));
    }

    public function testAnOrdersPageAndTheProfitPagesShowDeliveryOnItsOwnLine(): void
    {
        [$ch, $today] = $this->shop();
        $this->upload($this->signIn($this->uiUser('delivery_bills')), $this->bill($today));
        $fin = $this->signIn($this->uiUser('finance'));
        $order = $fin->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => '100']);
        self::assertSame(200, $order->status, $order->describe());
        self::phoneReady($order);
        // Delivery charged £6.00 inc VAT = £5.00; its parcel £4.20; its re-send's £3.10 (on the first order).
        self::assertSame([[Words::PROFIT['delivery_charged_ex'], '£5.00'], [Words::PROFIT['delivery_cost_own'], '£4.20'], [Words::PROFIT['delivery_cost_resends'], '£3.10'],
            [Words::PROFIT['delivery_after_resends'], '-£2.30']], self::rows($order, 'profit-order-delivery'));
        self::assertSame('£4.20 (bill 62368793)', self::rows($order, 'profit-order-parcels')[0][5]);
        $unbilled = $fin->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => '102']);
        self::assertSame(Words::PROFIT['not_billed'], self::rows($unbilled, 'profit-order-parcels')[0][5]);
        self::assertStringContainsString(Words::say('PROFIT', 'not_billed_note', '1'), $unbilled->text());
        $collected = $fin->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => '103']);
        self::assertSame([['£15.50']], array_map(static fn (array $r): array => [$r[2]], self::rows($collected, 'profit-order-collections')));
        self::assertSame([Words::PROFIT['delivery_cost_own'], '£15.50'], self::rows($collected, 'profit-order-delivery')[1]);
        $resend = $fin->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => '101']);
        self::assertStringContainsString(Words::PROFIT['delivery_resend_note'], $resend->text());
        // The summary's delivery table: today charged £5.00 x 3 orders (the re-send charges none), cost 4.20 + 3.10 + 15.50, not on an order 2.50.
        $sum = $fin->get('/ui/profit');
        self::phoneReady($sum);
        self::assertSame([Words::PROFIT['today'], '£15.00', '£22.80', '£2.50', '-£10.30'], self::rows($sum, 'profit-delivery-summary')[0]);
        $days = $fin->get('/ui/profit/periods');
        self::phoneReady($days);
        self::assertSame(['£22.80', '£2.50', '-£10.30'], array_slice(self::rows($days, 'profit-periods')[0], 9, 3));
        $shops = $fin->get('/ui/profit/by', ['by' => 'shop']);
        self::phoneReady($shops);
        self::assertSame(['£15.00', '£22.80', '£2.50', '-£10.30'], array_slice(self::rows($shops, 'profit-breakdown')[0], 7, 4));
        // The Delivery bills page is in Finance's Profit section, after the profit pages.
        self::assertSame([Words::MENU['profit_summary'], Words::MENU['profit_periods'], Words::MENU['profit_breakdown'], Words::MENU['profit_orders'],
            Words::MENU['profit_no_cost'], Words::MENU['profit_delivery']], array_column(self::sectionTabs($sum), 'label'));
    }
}
