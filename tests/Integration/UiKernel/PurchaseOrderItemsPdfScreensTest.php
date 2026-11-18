<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Kernel;
use CW\Ui\Words;

/**
 * The order PDF without prices (the owner, 18 Nov 2026: "keeping the existing one, I need another one to download, so there will be two
 * formats; I can choose which I need from the drop down"; docs/decisions.md PL1-PL5) through the real /ui kernel: offered under More
 * right after the order PDF, in the same words for a draft, a confirmed order and one that says DO NOT SEND; its own route
 * `/ui/purchasing/orders/{id}/items.pdf` for whoever may open the order PDF (purchasing.view) and nobody else; "-items" in the file
 * name; the same head as the order PDF (a confirmed order's snapshots), the five columns, no money; a cancelled order says so.
 */
final class PurchaseOrderItemsPdfScreensTest extends KernelUiTestCase
{
    /** An active supplier created by $buyerId and approved by a fresh reviewer (through the services). @return array<string, mixed> */
    private function supplier(int $buyerId, string $name): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA', 'email' => 'sales@items.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400), 'dd_checked_by' => (string) $buyerId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyerId), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** @return list<string> every string a compressed PDF draws, in order (its page streams opened) */
    private static function pdfTexts(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        $out = [];
        foreach ($m[1] as $stream) {
            $plain = @gzuncompress($stream);
            if (is_string($plain) && preg_match_all('/\(((?:[^()\\\\\n]|\\\\.)*)\) Tj/', $plain, $t) > 0) {
                array_push($out, ...array_map(static fn (string $x): string => str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $x), $t[1]));
            }
        }
        return $out;
    }

    /** The order page's More menu, in order: [href, words] of each link. @return list<array{0: string, 1: string}> */
    private static function moreLinks(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//div[contains(@class, "doc-actions")]/details[contains(concat(" ", @class, " "), " more ")]/div[@class="more-menu"]/a') ?: [] as $a) {
            if ($a instanceof \DOMElement) {
                $out[] = [$a->getAttribute('href'), trim($a->textContent)];
            }
        }
        return $out;
    }

    /** The PDF's download: 200, a PDF attachment named $file under the download CSP; its strings. @return list<string> */
    private static function download(UiResponse $r, string $file): array
    {
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame('application/pdf', $r->header('content-type'));
        self::assertSame("attachment; filename=\"{$file}\"; filename*=UTF-8''{$file}", $r->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $r->headerValues('content-security-policy'));
        self::assertStringStartsWith('%PDF-', $r->body);
        return self::pdfTexts($r->body);
    }

    /** Nothing about money: no £, no amount, no totals, no price or VAT column ("VAT" only in "VAT no. ..."). @param list<string> $texts */
    private static function assertNoMoney(array $texts, string $body): void
    {
        $all = implode("\n", $texts);
        self::assertStringNotContainsString("\xA3", $all);
        self::assertStringNotContainsString('£', $body);
        foreach (['/\btotal/i', '/\bnet\b/i', '/\bprice/i', '/\d\.\d\d(?!\d)/', '/VAT(?! no\. )/', '/%/'] as $absent) {
            self::assertDoesNotMatchRegularExpression($absent, $all, $absent);
        }
        self::assertNotContains('Delivery', $texts, 'the delivery charge is money: not on it');
    }

    public function testTheItemsOnlyPdfIsOfferedAfterTheOrderPdfAndPrintsNoPrices(): void
    {
        $buyerUser = $this->uiUser('buyer');
        $bid = $buyerUser['id'];
        $s = $this->supplier($bid, 'Items Supplies');
        $pod = self::makeSku('Items pod 2ml');
        $liquid = self::makeSku('Items liquid cherry');
        $si = (new SupplierItems(self::$db))->create(Caller::staff($bid), (int) $s['id'], $pod, ['units_per_pack' => '10', 'supplier_code' => 'POD-10', 'purchase_unit' => 'box'],
            ['pack_price' => '12.5000']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft(Caller::staff($bid), (int) $s['id'], []);
        $d = $pos->saveDraft(Caller::staff($bid), $d->id, $d->version, ['note' => 'Ring before you deliver'], [
            ['kind' => 'item', 'supplier_item_id' => (int) $si['id'], 'packs' => 5],
            ['kind' => 'item', 'sku_id' => $liquid, 'units_per_pack' => 1, 'packs' => 24, 'pack_price' => '1.20'],
            ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '7.50'],
        ]);
        $id = $d->id;
        $codes = [sprintf('CW-%06d', $pod), sprintf('CW-%06d', $liquid)];
        $buyer = $this->signIn($buyerUser);

        // A draft: who may only look sees the order page, with the two PDFs under More, the items-only one right after the order PDF.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $look = $desk->get("/ui/purchasing/orders/{$id}");
        self::assertSame(200, $look->status, $look->describe());
        $links = self::moreLinks($look);
        $at = array_search(["/ui/purchasing/orders/{$id}/pdf", Words::ORDER['pdf_draft']], $links, true);
        self::assertIsInt($at, 'the order PDF under More');
        self::assertSame(["/ui/purchasing/orders/{$id}/items.pdf", Words::ORDER['pdf_items_draft']], $links[$at + 1], 'the items-only PDF right after it');
        $texts = self::download($desk->get("/ui/purchasing/orders/{$id}/items.pdf"), "PO-draft-{$id}-items.pdf");
        self::assertContains("DRAFT \x97 NOT AN ORDER", $texts);
        self::assertContains("draft #{$id}", $texts);
        $no = array_search('No.', $texts, true);
        self::assertIsInt($no);
        self::assertSame(['No.', 'Supplier code', 'CW code', 'Item name', 'Qty', '1', 'POD-10', $codes[0], 'Items pod 2ml', '5 boxes of 10 = 50', '2', $codes[1],
            'Items liquid cherry', '24', 'Notes to supplier', 'Ring before you deliver'], array_slice($texts, $no, 16), 'no supplier code on line 2: an empty cell');
        self::assertNoMoney($texts, $desk->get("/ui/purchasing/orders/{$id}/items.pdf")->body);

        // Confirmed (PO-000001), then the supplier renamed: both PDFs print the snapshot taken at approval, the same head string for string.
        $d = $pos->approve(Caller::staff($bid), $id, $d->version);
        self::assertSame('PO-000001', $d->number);
        self::$db->exec("UPDATE supplier SET name = 'Renamed Supplies' WHERE id = ?", [(int) $s['id']]);
        $priced = self::download($buyer->get("/ui/purchasing/orders/{$id}/pdf"), 'PO-000001.pdf');
        $items = self::download($buyer->get("/ui/purchasing/orders/{$id}/items.pdf"), 'PO-000001-items.pdf');
        self::assertContains('Items Supplies', $items, 'the supplier as approved');
        self::assertNotContains('Renamed Supplies', $items);
        self::assertContains("COMPANY DETAILS NOT CONFIRMED \x97 DO NOT SEND", $items, 'the test database has no confirmed company details');
        self::assertNotContains("DRAFT \x97 NOT AN ORDER", $items);
        $no = array_search('No.', $items, true);
        self::assertIsInt($no);
        self::assertSame(array_slice($priced, 0, (int) array_search('#', $priced, true)), array_slice($items, 0, $no), 'the same head as the order PDF');
        self::assertContains('PO-000001', $items);
        self::assertNoMoney($items, $buyer->get("/ui/purchasing/orders/{$id}/items.pdf")->body);

        // The order's page: under More, right after the order PDF (which says DO NOT SEND for now), in the same words; Ctrl+P stays the order PDF's.
        $view = $buyer->get("/ui/purchasing/orders/{$id}");
        $links = self::moreLinks($view);
        $at = array_search(["/ui/purchasing/orders/{$id}/pdf", Words::ORDER['pdf_hold']], $links, true);
        self::assertIsInt($at, 'the order PDF under More');
        self::assertSame(["/ui/purchasing/orders/{$id}/items.pdf", Words::ORDER['pdf_items_hold']], $links[$at + 1]);
        $xp = new \DOMXPath($view->dom());
        self::assertSame(1, $xp->query('//a[@data-key-print]')->length);
        self::assertSame("/ui/purchasing/orders/{$id}/pdf", $xp->query('//a[@data-key-print]')->item(0)?->getAttribute('href'));

        // Who may open the order PDF may open this one; nobody else (purchasing.view: warehouse and stock control see no purchase orders, I85).
        foreach (['warehouse', 'stock_controller'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            self::assertSame(403, $web->get("/ui/purchasing/orders/{$id}/pdf")->status, $role);
            self::assertSame(403, $web->get("/ui/purchasing/orders/{$id}/items.pdf")->status, $role);
        }
        foreach (['purchasing_desk', 'reviewer', 'accountant'] as $role) {
            self::assertSame(200, $this->signIn($this->uiUser($role))->get("/ui/purchasing/orders/{$id}/items.pdf")->status, $role);
        }
        self::assertSame(404, $buyer->get('/ui/purchasing/orders/999999999/items.pdf')->status, 'no such order');
        self::assertSame(405, $buyer->post("/ui/purchasing/orders/{$id}/items.pdf", ['csrf' => $this->token($buyer)])->status, 'a download, read only');

        // Cancelled: the order's items-only PDF says so; the cancellation's own reads as the cancellation, without prices.
        $pos->cancel(Caller::staff($bid), $id, (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]), 'not_needed', null);
        $cancelled = self::download($buyer->get("/ui/purchasing/orders/{$id}/items.pdf"), 'PO-000001-items.pdf');
        self::assertContains("CANCELLED \x97 see PO-000002", $cancelled);
        $reversal = (int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$id]);
        $note = self::download($buyer->get("/ui/purchasing/orders/{$reversal}/items.pdf"), 'PO-000002-items.pdf');
        self::assertContains('CANCELLATION', $note);
        self::assertContains('Order PO-000001 is cancelled. Please do not deliver it.', $note);
        self::assertNoMoney($note, $buyer->get("/ui/purchasing/orders/{$reversal}/items.pdf")->body);
    }
}
