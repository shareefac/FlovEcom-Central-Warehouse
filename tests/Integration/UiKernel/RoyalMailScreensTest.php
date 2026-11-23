<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\UiResponse;
use CW\Tests\Support\XlsWriter;
use CW\Ui\Words;

/**
 * Profit > Delivery bills with Royal Mail through the real /ui kernel as cw_app (docs/decisions.md DL69), SYNTHETIC bills and labels: only the
 * Delivery bills job uploads a Royal Mail bill (its saved page, or its text pasted) for the account chosen, and the Click & Drop report;
 * the bill's page says where its money went adding up to its net, the day tie-out, its manifests and rows, never a hidden input of the page;
 * Replace by pasted text and Undo; the order's page shows each parcel's Royal Mail cost; the list shows the bill and the accounts' labels.
 */
final class RoyalMailScreensTest extends KernelUiTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_rm_ui_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
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

    /** @return array{0: int, 1: int} Vape and Go with two orders of 9 Nov, each with a Royal Mail parcel, and the account */
    private function shop(): array
    {
        $ch = (int) $this->site('vapeandgo', 'live')->channelId;
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', "
            . "'2026-10-28 10:28:00', '2026-10-28 10:28:00')", [$ch]);
        $acct = RmFixtures::account(self::$db, $ch)['account'];
        foreach (['100' => 'JV000000101GB', '101' => 'JV000000102GB'] as $ref => $t) {
            (new OrderSnapshots(self::$db))->store($ch, (string) $ref, ['v' => 1, 'version' => 1, 'order_no' => '1360' . $ref, 'status' => 'completed',
                'placed_at' => '2026-11-09T08:00:00Z', 'paid_at' => '2026-11-09T09:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
                'lines' => [['line_id' => '8' . $ref, 'variant_id' => '40123', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                    'free' => false]], 'delivery' => ['method_id' => '5', 'method' => 'Royal Mail', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
                'grand_inc' => 1599, 'parcels' => [['parcel_ref' => 'odl:' . $ref, 'tracking_no' => $t, 'carrier' => 'royal_mail', 'service_code' => '', 'service' => '',
                    'status' => 'generated', 'label_at' => '2026-11-09T10:00:00Z', 'shipped_at' => '2026-11-09T10:00:00Z', 'manual' => false, 'partial' => false]]], 'api');
        }
        return [$ch, $acct];
    }

    private function upload(KernelBrowser $web, array $form, ?string $path = null): UiResponse
    {
        $page = $web->get('/ui/profit/delivery');
        self::assertSame(200, $page->status, $page->describe());
        return $web->postMultipart('/ui/profit/delivery/upload', $form + $page->form('/ui/profit/delivery/upload'),
            $path === null ? [] : ['file' => ['path' => $path, 'name' => 'bill.html']]);
    }

    public function testTheJobUploadsAPageAndThePageSaysWhereItsMoneyWent(): void
    {
        [$ch, $acct] = $this->shop();
        $job = $this->signIn($this->uiUser('delivery_bills'));
        $fin = $this->signIn($this->uiUser('finance'));
        // the labels from Click & Drop's report, through the screen
        $head = ['Order number', 'Batch number', 'Manifest number', 'Channel', 'Channel reference', 'Despatch date', 'Manifest date', 'Customer', 'Company',
            'Address line 1', 'Address line 2', 'City', 'Postcode', 'Country', 'Weight (kg)', 'Package size', 'Shipping service', 'Service code', 'Tracking number',
            'Tracking status', 'Sales order number', 'Department', 'Notification email address', 'Notification mobile number', 'Tags'];
        $at = ((new \DateTimeImmutable('2026-11-09 18:00:00', new \DateTimeZone('UTC')))->getTimestamp() - (new \DateTimeImmutable('1899-12-30',
            new \DateTimeZone('UTC')))->getTimestamp()) / 86400;
        $xls = $this->dir . '/report.xls';
        file_put_contents($xls, XlsWriter::file([$head, ...array_map(static fn (array $l): array => [779000.0, 'B1', 'M2288', RmFixtures::CHANNELS['api'], $l[1], $at, $at,
            'Testy McTestface', '', '1 Imaginary Road', '', 'Nowhereton', 'ZZ9 9ZZ', 'Neverland', 0.3, 'Parcel', 'Tracked 48', 'TPL48HV', $l[0], 'x', 'uuid', '',
            'nobody@example.invalid', '07000000000', ''], [['JV000000101GB', '1360100'], ['JV000000102GB', '1360101'], ['JV000000103GB', '1360199']])]));
        $list = $job->get('/ui/profit/delivery');
        self::assertTrue($list->hasForm('/ui/profit/delivery/labels'));
        self::assertFalse($fin->get('/ui/profit/delivery')->hasForm('/ui/profit/delivery/labels'), 'Finance looks only');
        self::assertSame(403, $fin->postMultipart('/ui/profit/delivery/labels', ['csrf' => $this->token($fin), 'account' => (string) $acct],
            ['file' => ['path' => $xls, 'name' => 'r.xls']])->status);
        $read = $job->postMultipart('/ui/profit/delivery/labels', ['account' => (string) $acct] + $list->form('/ui/profit/delivery/labels'),
            ['file' => ['path' => $xls, 'name' => 'r.xls']]);
        self::assertSame(200, $read->status, $read->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::say('DELIVERY', 'rm_labels_read', '3', '3', '0', '0'), $read->text());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM carrier_label'));
        // a bill page with no account chosen: refused, nothing stored; with it: stored, and its page
        $path = $this->dir . '/bill.html';
        file_put_contents($path, RmBill::page([RmBill::line('2026-11-09', 'TPL', 2, 508, '943'), RmBill::line('2026-11-08', 'RW4', 1, 105, 'Rectification Week 45')],
            ['invoice' => '9070000001']));
        $no = $this->upload($job, ['carrier' => 'royal_mail'], $path);
        self::assertSame(422, $no->status);
        self::assertStringContainsString(Words::DELIVERY['rm_no_account'], $no->text());
        $r = $this->upload($job, ['carrier' => 'royal_mail', 'account' => (string) $acct], $path);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $id = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE status = 'live'");
        $page = $fin->get("/ui/profit/delivery/{$id}");
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString('Royal Mail bill 9070000001 (8 Nov - 9 Nov 2026): £6.13 - on orders £5.08, not on an order £1.05', $page->text());
        self::assertStringNotContainsString('SESSION-', $page->body, 'a hidden input of the saved page is never shown');
        self::assertStringNotContainsString('Made Up Street', $page->body);
        $where = self::rows($page, 'delivery-rm-where');
        self::assertSame(['£5.08', '£3.59', '-£2.54', '£6.13'], array_column($where, 2),
            'on orders; not on an order (the label made on Click & Drop\'s site, the unreadable barcode); the label not charged on the day\'s line; adds up');
        self::assertStringContainsString(Words::DELIVERY['adds_up'], $where[count($where) - 1][0]);
        self::assertSame([['TPL', '3', '2', '3']], array_map(static fn (array $r): array => array_slice($r, 1, 4), self::rows($page, 'delivery-rm-days')));
        self::assertCount(2, self::rows($page, 'delivery-rm-rows'));
        // the order's page: its parcel's Royal Mail cost
        $finUser = $this->signIn($this->uiUser('finance'));
        $order = $finUser->get('/ui/profit/orders/view', ['store' => (string) $ch, 'ref' => '100']);
        self::assertSame(200, $order->status, $order->describe());
        self::assertStringContainsString(Words::say('PROFIT', 'bill_line', \CW\Ui\Html::money(2.54), '9070000001'), $order->text());
        // the list
        $rowsList = self::rows($job->get('/ui/profit/delivery'), 'delivery-bills');
        self::assertSame('Royal Mail bill 9070000001 0706703000', $rowsList[0][0]);
        self::assertSame(['£6.13', '£5.08 (2)', '£1.05 (3)'], [$rowsList[0][3], $rowsList[0][4], $rowsList[0][5]]);
        // a corrected bill pasted as text: replaced; then undone with its box ticked
        $form = $job->get("/ui/profit/delivery/{$id}")->form("/ui/profit/delivery/{$id}/replace");
        $text = RmBill::text([RmBill::line('2026-11-09', 'TPL', 3, 762, '943'), RmBill::line('2026-11-08', 'RW4', 1, 105, 'Rectification Week 45')], ['invoice' => '9070000001']);
        $rep = $job->postMultipart("/ui/profit/delivery/{$id}/replace", ['text' => $text] + $form, []);
        self::assertSame(303, $rep->status, $rep->describe() . ' ' . implode(' | ', self::$log));
        $new = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE status = 'live'");
        self::assertNotSame($id, $new);
        self::assertSame(['£5.08', '£3.59', '£8.67'], array_column(self::rows($job->get("/ui/profit/delivery/{$new}"), 'delivery-rm-where'), 2),
            '3 billed, 3 labels: no day\'s line');
        $undo = $job->get("/ui/profit/delivery/{$new}")->form("/ui/profit/delivery/{$new}/undo");
        self::assertSame(422, $job->post("/ui/profit/delivery/{$new}/undo", $undo)->status, 'its box');
        self::assertSame(303, $job->post("/ui/profit/delivery/{$new}/undo", ['sure' => '1'] + $undo)->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_allocation WHERE ended_at IS NULL'));
        self::assertSame(0, \CW\Profit\DeliveryBills::orderCost(self::$db, $ch, '100'));
        self::assertSame([], RoyalMailBills::drift(self::$db, $acct));
    }
}
