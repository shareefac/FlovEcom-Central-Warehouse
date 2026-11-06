<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Invoices\ErpApiReader;
use CW\Invoices\ErpInvoiceCopy;
use CW\Tests\Support\ErpFakeTransport;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The copies of ERPNext purchase invoices on the screens (CW-917; docs/decisions.md EC14): Purchases › Supplier invoices › From ERPNext
 * lists the waiting ones with why (the copied ones under Show, each with its copy's link); the invoices list and a copy's page carry the
 * badge "From ERPNext: stock already booked" and the ERPNext number; a copy's page offers no cancellation and no credit note (they are made
 * in ERPNext). Nothing of it is in Approvals.
 */
final class ErpCopyScreensTest extends KernelUiTestCase
{
    private ?string $was = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->was = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [ErpInvoiceCopy::SWITCH]);
    }

    protected function tearDown(): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$this->was, ErpInvoiceCopy::SWITCH]);
        parent::tearDown();
    }

    /** One copied invoice (PI-S1) and one waiting (PI-S2: its item is not linked), as a real run makes them. @return int the copy */
    private function copies(): int
    {
        $site = self::makeChannel('vapeandgo', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        $sku = self::makeSku('Screen pod');
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, 'VA', ?, 1, 'mapped')", [$site, $sku]);
        $erp = new ErpFakeTransport();
        $erp->items = ['A' => ['is_stock_item' => 1, 'custom_variant_id' => 'VA'], 'C' => ['is_stock_item' => 1, 'custom_variant_id' => 'VC']];
        foreach (['PI-S1' => 'A', 'PI-S2' => 'C'] as $name => $item) {
            $erp->invoices[$name] = ['name' => $name, 'company' => 'VAPE AND GO', 'supplier' => 'Screen Supplies', 'supplier_name' => 'Screen Supplies', 'bill_no' => 'SB-' . $name,
                'bill_date' => '2026-10-01', 'posting_date' => '2026-10-01', 'due_date' => '2026-10-15', 'docstatus' => 1, 'is_return' => 0, 'modified' => '2026-10-01 10:00:00', 'base_net_total' => 6,
                'base_grand_total' => 7.2, 'items' => [['idx' => 1, 'item_code' => $item, 'qty' => 2, 'stock_qty' => 2, 'conversion_factor' => 1, 'base_net_rate' => 3,
                    'base_net_amount' => 6]], 'taxes' => [['account_head' => 'VAT - VPG', 'category' => 'Total', 'base_tax_amount_after_discount_amount' => 1.2]]];
        }
        self::$db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON) WHERE setting_key = ?", [ErpInvoiceCopy::SWITCH]);
        $r = (new ErpInvoiceCopy(self::$db))->run(new ErpApiReader($erp, 'https://erp.example.test', 'abc123key', 'secret456'), true);
        self::assertSame(['copied' => 1, 'waiting' => 1], ['copied' => $r['counts']['copied'] ?? 0, 'waiting' => $r['counts']['waiting'] ?? 0]);
        return (int) self::$db->value("SELECT document_id FROM erp_pi WHERE name = 'PI-S1'");
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    public function testFromErpnextTheBadgeAndACopysPage(): void
    {
        $copy = $this->copies();
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $web->get('/ui/purchasing/invoices/from-erpnext');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $text = (string) preg_replace('/\s+/u', ' ', $page->text());
        self::assertStringContainsString('PI-S2', $text);
        self::assertStringContainsString(Words::ERP_COPY_WHY['no_item'], $text, 'why it waits, in words');
        self::assertStringNotContainsString('PI-S1', $text, 'the copied one is under Show');
        self::assertStringContainsString(Words::ERP_COPY['on'], $text);
        self::assertDoesNotMatchRegularExpression('/\b(no_item|erp_copy|erp_pi)\b/', $text, 'no code on the screen');
        $copied = $web->get('/ui/purchasing/invoices/from-erpnext', ['show' => 'copied']);
        self::assertSame(200, $copied->status);
        self::assertContains('/ui/purchasing/invoices/' . $copy, $copied->hrefs(), 'the copy opens from its row');
        // The list: the copy's badge under its number.
        $list = $web->get('/ui/purchasing/invoices');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString(Words::ERP_COPY['badge'], $list->text());
        // The copy's page: the badge, its ERPNext number; no cancel, no credit note.
        $one = $web->get('/ui/purchasing/invoices/' . $copy);
        self::assertSame(200, $one->status, $one->describe() . ' ' . implode(' | ', self::$log));
        $oneText = (string) preg_replace('/\s+/u', ' ', $one->text());
        self::assertStringContainsString(Words::ERP_COPY['badge'], $oneText);
        self::assertStringContainsString(Words::ERP_COPY['erp_name'] . ': PI-S1', $oneText);
        self::assertSame(0, self::xp($one)->query('//form[contains(@action, "/reverse")]')->length, 'cancelled in ERPNext only');
        self::assertSame(0, self::xp($one)->query('//form[contains(@action, "/credit-notes")]')->length, 'credited in ERPNext only');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'document'"), 'nothing in Approvals');
    }

    /**
     * CW-918 (owner, 6 Nov 2026: suppliers are paid outside ERPNext): a copy whose ERPNext due date has passed shows that date plain, never
     * red and never "days late", on the invoices list and on its supplier's Invoices tab. (A record keyed in CW still does:
     * InvoiceOnePageScreensTest.)
     */
    public function testACopyPastItsDueDateIsNeverLate(): void
    {
        $copy = $this->copies();
        self::assertSame('2026-10-15', (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$copy]), 'ERPNext\'s due date is kept');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $href = '/ui/purchasing/invoices/' . $copy;
        $list = $web->get('/ui/purchasing/invoices');
        self::assertSame(200, $list->status, $list->describe());
        $row = '//tr[.//a[@href="' . $href . '"]]';
        self::assertSame(1, self::xp($list)->query($row)->length, 'the copy is listed');
        self::assertSame(0, self::xp($list)->query($row . '//td[contains(@class, "late")]')->length, 'its due date is not red');
        $rowText = (string) preg_replace('/\s+/u', ' ', (string) self::xp($list)->query($row)->item(0)?->textContent);
        self::assertStringContainsString(\CW\Ui\Html::day('2026-10-15'), $rowText, 'the due date is still shown');
        self::assertStringNotContainsString(explode('%s', Words::INVOICES['late_many'])[1] ?? 'days late', $rowText, 'no "days late"');
        $supplier = (int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [$copy]);
        $tab = $web->get('/ui/purchasing/suppliers/' . $supplier, ['tab' => 'invoices']);
        self::assertSame(200, $tab->status, $tab->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(1, self::xp($tab)->query($row)->length, 'the copy is on its supplier\'s Invoices tab');
        self::assertSame(0, self::xp($tab)->query($row . '//td[contains(@class, "late")]')->length, 'not red there either');
    }
}
