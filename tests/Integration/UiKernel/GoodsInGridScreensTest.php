<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\DocGrid;
use CW\Ui\Words;

/**
 * Goods received as a standard grid (the owner, 6 Nov 2026: "check all purchase order, purchase invoice, sales order like this all form";
 * Goods received first, 7 Nov; docs/decisions.md GW): a delivery's draft page works like the purchase order's and the supplier invoice's
 * (KG), through the real kernel as cw_app.
 *  - the lines (and the prices fold) have a No. column, the CW code beside the name, the key hints under the table with their words; the
 *    read-only page has KG10's view look;
 *  - the keyer's lines added in the page travel in ONE field (`new_lines`) with the save, in the same transaction, version check and
 *    FormOnce, before the action, by today's Add rules (this supplier's pack, switched on; an item not joined; the same purchase already a
 *    line gets the packs, as Add does; the price Add would use);
 *  - a refusal keeps every typed line, with its words on its row; a version conflict keeps the new lines; the same form twice adds nothing
 *    twice; someone who did not key it adds nothing;
 *  - the scan box's choices are what Add would add (its own route), with no price;
 *  - without the script, Add and Save as before.
 */
final class GoodsInGridScreensTest extends KernelUiTestCase
{
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevCutoff = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'receiving.unstamped_refusal_from'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'",
            [json_encode(gmdate('Y-m-d', time() + 365 * 86400), JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        if ($this->prevCutoff !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'", [$this->prevCutoff]);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** A dry item (no duty stamp). */
    private function dry(string $name): int
    {
        $sku = self::makeSku($name);
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        return $sku;
    }

    /** @return int one of $supplierId's packs of $sku, at $price a pack */
    private function pack(int $supplierId, int $sku, int $upp, string $code, string $price): int
    {
        $buyer = $this->uiUser('buyer')['id'];
        return (int) (new SupplierItems(self::$db))->create(Caller::staff($buyer), $supplierId, $sku, ['units_per_pack' => (string) $upp, 'supplier_code' => $code],
            ['pack_price' => $price])['id'];
    }

    private function receipts(): GoodsReceipts
    {
        return new GoodsReceipts(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    /** @param list<array<string, mixed>> $lines a draft delivery keyed by $creatorId through the service */
    private function delivery(int $creatorId, int $supplierId, array $lines): int
    {
        $svc = $this->receipts();
        $d = $svc->createDraft(Caller::staff($creatorId), $supplierId, []);
        $svc->saveDraft(Caller::staff($creatorId), $d->id, $d->version, [], $lines);
        return $d->id;
    }

    private static function docVersion(int $id): int
    {
        return (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> the delivery's lines as stored, in order */
    private static function stored(int $id): array
    {
        return self::$db->all('SELECT g.line_no, dl.sku_id, g.supplier_item_id, g.units_per_pack, g.purchase_unit, g.packs, g.pack_price, g.entry FROM grn_line g '
            . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no WHERE g.document_id = ? ORDER BY g.line_no', [$id]);
    }

    /** @return array<string, string> the one page's form */
    private static function onePage(UiResponse $page, int $id): array
    {
        $f = $page->form("/ui/receiving/{$id}/lines", true);
        self::assertNotSame([], $f, 'the one page has its form: ' . $page->describe() . ' ' . implode(' | ', array_slice(self::$log, -2)));
        return $f;
    }

    /** @param list<array<string, mixed>> $lines the `new_lines` field as the script sends it */
    private static function grid(array $lines): string
    {
        return json_encode($lines, JSON_THROW_ON_ERROR);
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return (new \DOMXPath($r->dom()))->query($xpath)->length;
    }

    /** @return list<string> */
    private static function texts(UiResponse $r, string $xpath): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)), iterator_to_array((new \DOMXPath($r->dom()))->query($xpath)));
    }

    private static function attr(UiResponse $r, string $xpath): string
    {
        return (string) (new \DOMXPath($r->dom()))->evaluate('string(' . $xpath . ')');
    }

    private static function why(UiResponse $r): string
    {
        return $r->describe() . "\n" . implode("\n", array_slice(self::$log, -3));
    }

    /**
     * A supplier with three packs and two loose items, and a delivery of the first three keyed by the buying desk.
     *
     * @return array{desk: array<string, mixed>, web: KernelBrowser, s: int, a: int, b: int, c: int, d: int, e: int, siA: int, siB: int, siD: int, id: int}
     */
    private function setUpDelivery(string $name): array
    {
        $desk = $this->uiUser('purchasing_desk');
        $s = (int) $this->supplier($name)['id'];
        $a = $this->dry($name . ' coil A');
        $b = $this->dry($name . ' coil B');
        $c = $this->dry($name . ' coil C');
        $d = $this->dry($name . ' coil D');
        $e = $this->dry($name . ' coil E');
        $siA = $this->pack($s, $a, 5, 'A-5', '10.00');
        $siB = $this->pack($s, $b, 1, 'B-1', '2.00');
        $siD = $this->pack($s, $d, 10, 'D-10', '3.50');
        $id = $this->delivery($desk['id'], $s, [['supplier_item_id' => $siA, 'packs' => 2], ['supplier_item_id' => $siB, 'packs' => 3], ['sku_id' => $c, 'packs' => 1]]);
        return ['desk' => $desk, 'web' => $this->signIn($desk), 's' => $s, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e, 'siA' => $siA, 'siB' => $siB, 'siD' => $siD,
            'id' => $id];
    }

    // ------------------------------------------------------------------------------------------
    // The look and the keys
    // ------------------------------------------------------------------------------------------

    /** The draft's lines and its prices fold have a No. column (KG1), the key hints say their words (KG3), the read page has KG10's look. */
    public function testTheDraftAndTheReadPageNumberTheirLinesAndNameTheirKeys(): void
    {
        $x = $this->setUpDelivery('Number Lines Ltd');
        $id = $x['id'];
        $page = $x['web']->get("/ui/receiving/{$id}");
        self::assertSame(200, $page->status, self::why($page));
        $grid = '//table[contains(@class, "gi-lines") and contains(@class, "kg-grid") and @data-kg-grid]';
        self::assertSame(1, self::nodes($page, $grid), 'the lines are KG\'s grid');
        self::assertSame(Words::DOC_GRID['no'], self::texts($page, $grid . '/thead/tr/th[1]')[0] ?? '', 'the No. column first');
        self::assertSame(['1', '2', '3'], self::texts($page, $grid . '/tbody/tr[@data-kg-row]/td[contains(@class, "c-no")]'), 'each line its number');
        self::assertSame(sprintf('CW-%06d', $x['a']), self::texts($page, '//tr[@id="line-1"]/th/span[@class="kg-code"]')[0] ?? '', 'the CW code beside the name');
        self::assertSame(1, self::nodes($page, '//tr[@id="line-1"]//input[@name="line_1_packs" and @data-kg-cell="qty" and @aria-keyshortcuts]'), 'the Got box is a grid box');
        self::assertSame('grn:' . $id, self::attr($page, '//form[contains(@class, "gi-form")]/@data-kg-editor'), 'the form is an editor the save without reload knows');
        $f = self::onePage($page, $id);
        self::assertArrayHasKey(DocGrid::FIELD, $f, 'the keyer\'s new lines go in one field');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $f['form_key'] ?? '', 'with a FormOnce key');
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "gi-lines-card")]//template[@data-kg-row]'), 'the new line\'s row');
        self::assertMatchesRegularExpression('#<template data-kg-row>\s*<tr class="gi-row kg-new[^"]*" data-kg-row data-kg-new[^>]*>.*?<input type="number"(?![^>]*\bname=)[^>]*data-kg-cell="qty"#s',
            $page->body, 'its Got box unnamed: the new lines go in their one field');
        // The hint line (KG3, KG19): its keys as kbd chips and their words; hidden on a phone and without the script (KG's rules on .kg-keys).
        $hint = '//section[contains(@class, "gi-lines-card")]//p[contains(@class, "kg-keys")]';
        self::assertSame(['Tab', '↑↓', 'Ctrl+Enter', 'Alt+R', 'Alt+U', 'Ctrl+S'], self::texts($page, $hint . '//kbd'));
        self::assertStringContainsString(Words::DOC_GRID['k_new'], self::texts($page, $hint)[0] ?? '');
        self::assertStringContainsString(Words::DOC_GRID['k_save'], self::texts($page, $hint)[0] ?? '');
        self::assertSame('Cmd+S', self::attr($page, $hint . '//kbd[.="Ctrl+S"]/@data-mac'), 'a Mac\'s key');
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "gi-lines-card")]//*[@data-kg-undo-line and @hidden]'), 'Undo waits hidden');
        self::assertSame('Control+S', self::attr($page, '//div[contains(@class, "doc-bar")]//button[@value="save"]/@aria-keyshortcuts'), 'Save names its key');
        self::assertSame('', self::attr($page, '//div[contains(@class, "doc-bar")]//button[@value="post"]/@aria-keyshortcuts'), 'Book in has no key');
        // The prices fold (the buying desk): the same No. column and look.
        $prices = '//table[contains(@class, "gi-price-lines") and contains(@class, "kg-grid")]';
        self::assertSame(Words::DOC_GRID['no'], self::texts($page, $prices . '/thead/tr/th[1]')[0] ?? '');
        self::assertSame(['1', '2', '3'], self::texts($page, $prices . '/tbody/tr/td[contains(@class, "c-no")]'));
        self::assertSame(1, self::nodes($page, $prices . '//input[@name="line_1_price" and @data-kg-cell="price"]'));

        // Someone who did not key it: the same look, no new lines, their keys (Ctrl+Enter: the scan box).
        $bench = $this->signIn($this->uiUser('goods_in'))->get("/ui/receiving/{$id}");
        self::assertSame(['1', '2', '3'], self::texts($bench, $grid . '/tbody/tr[@data-kg-row]/td[contains(@class, "c-no")]'));
        $g = self::onePage($bench, $id);
        self::assertArrayNotHasKey(DocGrid::FIELD, $g);
        self::assertArrayNotHasKey('form_key', $g);
        self::assertSame(0, self::nodes($bench, '//template[@data-kg-row]'));
        self::assertSame(['Tab', 'Ctrl+Enter', 'Ctrl+S'], self::texts($bench, $hint . '//kbd'));
        self::assertStringContainsString(Words::DOC_GRID['k_scan'], self::texts($bench, $hint)[0] ?? '');

        // The read-only page (someone who only looks): KG10's view look.
        $look = $this->signIn($this->uiUser('stock_controller'))->get("/ui/receiving/{$id}");
        self::assertSame(200, $look->status, self::why($look));
        $view = '//table[contains(@class, "grn-lines") and contains(@class, "kg-view")]';
        self::assertSame(Words::DOC_GRID['no'], self::texts($look, $view . '/thead/tr/th[1]')[0] ?? '');
        self::assertSame(['1', '2', '3'], self::texts($look, $view . '/tbody/tr/td[contains(@class, "c-no")]'));
        self::assertSame(sprintf('CW-%06d', $x['a']), self::texts($look, $view . '/tbody/tr[1]/th/span[@class="kg-code"]')[0] ?? '');
    }

    // ------------------------------------------------------------------------------------------
    // Lines added in the page
    // ------------------------------------------------------------------------------------------

    /** Several new lines, an edited Got and a removed line in ONE post (KG4): saved in order, by Add's rules, at the price Add would use. */
    public function testOneSaveCarriesNewLinesAnEditedGotAndARemovedLine(): void
    {
        $x = $this->setUpDelivery('One Post Ltd');
        $id = $x['id'];
        $f = self::onePage($x['web']->get("/ui/receiving/{$id}"), $id);
        $r = $x['web']->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '4', 'line_2_packs' => '0', 'action' => 'save',
            DocGrid::FIELD => self::grid([['si' => $x['siD'], 'q' => '2', 'p' => ''], ['sku' => $x['e'], 'u' => 6, 'q' => '1', 'p' => '']])] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertStringStartsWith("/ui/receiving/{$id}?notice=saved", (string) $r->location());
        $lines = self::stored($id);
        self::assertSame([[$x['a'], 4], [$x['c'], 1], [$x['d'], 2], [$x['e'], 1]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs']], $lines),
            'line 1 edited, line 2 out, the two new lines after the saved ones');
        self::assertSame([$x['siD'], 10, '3.5000', 'scan'], [(int) $lines[2]['supplier_item_id'], (int) $lines[2]['units_per_pack'], (string) $lines[2]['pack_price'],
            (string) $lines[2]['entry']], 'the supplier\'s pack at its last price (as Add)');
        self::assertSame([null, 6, 'case'], [$lines[3]['supplier_item_id'], (int) $lines[3]['units_per_pack'], (string) $lines[3]['purchase_unit']], 'a case of 6 of an item');
        $page = $x['web']->follow($r);
        self::assertSame(['1', '2', '3', '4'], self::texts($page, '//table[contains(@class, "gi-lines")]/tbody/tr[@data-kg-row]/td[contains(@class, "c-no")]'));
        self::assertSame('', self::onePage($page, $id)[DocGrid::FIELD], 'nothing new left after the save');
    }

    /**
     * An item already on the delivery keeps today's behaviour: a new line of it adds to its line (Add's same-line rule), never a second
     * line; two new lines of one item are one line; the no-script Add of its code adds to it as before; the scan box's choice names its line.
     */
    public function testAnItemAlreadyOnTheDeliveryAddsToItsLine(): void
    {
        $x = $this->setUpDelivery('Same Line Ltd');
        $id = $x['id'];
        $web = $x['web'];
        $f = self::onePage($web->get("/ui/receiving/{$id}"), $id);
        $r = $web->post("/ui/receiving/{$id}/lines", ['action' => 'save', DocGrid::FIELD => self::grid([['si' => $x['siA'], 'q' => '3', 'p' => ''],
            ['si' => $x['siD'], 'q' => '1', 'p' => ''], ['si' => $x['siD'], 'q' => '2', 'p' => '']])] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([[$x['a'], 5], [$x['b'], 3], [$x['c'], 1], [$x['d'], 3]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs']], self::stored($id)),
            'A: 2 + 3 packs on its own line; D twice: one line of 3');

        // The scan box's choices (its own route, GW3): what Add would add, the line it adds to, the packs a scan is, no price.
        $frag = $web->get("/ui/receiving/{$id}/products", ['q' => 'A-5']);
        self::assertSame(200, $frag->status, self::why($frag));
        self::assertSame(1, self::nodes($frag, '//*[@data-gi-fragment]//ul[@data-po-choices]/li'));
        self::assertSame([(string) $x['siA'], '1', '1'], [self::attr($frag, '//li/@data-si'), self::attr($frag, '//li/@data-line'), self::attr($frag, '//li/@data-packs')]);
        self::assertSame(0, self::nodes($frag, '//li[@data-price]'), 'no price: the save sets it, as Add does');
        $none = $web->get("/ui/receiving/{$id}/products", ['q' => 'zzzz-nothing']);
        self::assertSame(0, self::nodes($none, '//li'));
        self::assertSame(Words::say('RECEIPT_ERROR', 'not_found', 'zzzz-nothing'), self::texts($none, '//*[@data-po-says]')[0] ?? '');
        // A case's barcode (10 items) of an item the supplier sells in packs of 5: 2 packs a scan (Add's rule, I173).
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES (?, ?, 10)', ['77' . str_pad((string) $x['a'], 11, '0', STR_PAD_LEFT), $x['a']]);
        $case = $web->get("/ui/receiving/{$id}/products", ['q' => '77' . str_pad((string) $x['a'], 11, '0', STR_PAD_LEFT)]);
        self::assertSame([(string) $x['siA'], '2', '1'], [self::attr($case, '//li/@data-si'), self::attr($case, '//li/@data-packs'), self::attr($case, '//li/@data-line')]);

        // Without the script: Add of its code adds to its line, as before.
        $f = self::onePage($web->follow($r), $id);
        $add = $web->post("/ui/receiving/{$id}/lines", ['q' => 'A-5', 'packs' => '1', 'action' => 'add'] + $f);
        self::assertSame(303, $add->status, self::why($add));
        self::assertStringContainsString('notice=incremented', (string) $add->location());
        self::assertSame([6, 4], [(int) self::stored($id)[0]['packs'], count(self::stored($id))]);
    }

    /**
     * A refused save keeps every typed line (KG6): another supplier's pack, a switched-off pack, a joined item, packs that are not a number:
     * refused in words, nothing saved, the new lines drawn again (the refused one red with its words) and the edits as typed.
     */
    public function testARefusedNewLineKeepsEveryTypedLine(): void
    {
        $x = $this->setUpDelivery('Refused Lines Ltd');
        $id = $x['id'];
        $web = $x['web'];
        $other = (int) $this->supplier('Refused Other Ltd')['id'];
        $siX = $this->pack($other, $x['d'], 5, 'X-5', '1.00');
        $siOff = $this->pack($x['s'], $x['e'], 4, 'E-4', '1.00');
        self::$db->exec('UPDATE supplier_item SET is_active = 0 WHERE id = ?', [$siOff]);
        $joined = $this->dry('Refused joined coil');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$x['c'], $joined]);
        $f = self::onePage($web->get("/ui/receiving/{$id}"), $id);
        $v = self::docVersion($id);
        $before = self::stored($id);
        foreach ([[['si' => $siX, 'q' => '2', 'p' => ''], 'unknown_supplier_item'], [['si' => $siOff, 'q' => '1', 'p' => ''], 'unknown_supplier_item'],
            [['sku' => $joined, 'q' => '1', 'p' => ''], 'unknown_sku'], [['si' => $x['siD'], 'q' => 'two', 'p' => ''], 'grid_packs']] as [$bad, $code]) {
            $sent = [['si' => $x['siD'], 'q' => '1', 'p' => ''], $bad];
            $r = $web->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '7', 'action' => 'save', DocGrid::FIELD => self::grid($sent)] + $f);
            self::assertSame(422, $r->status, $code . ': ' . self::why($r));
            self::assertSame($v, self::docVersion($id), $code . ': nothing saved');
            self::assertSame($before, self::stored($id), $code . ': the lines as they were');
            self::assertStringContainsString($code === 'grid_packs' ? Words::say('RECEIPT_ERROR', 'grid_packs', 5) : Words::RECEIPT_ERROR[$code], $r->text(), $code);
            self::assertSame(2, self::nodes($r, '//table[contains(@class, "gi-lines")]/tbody/tr[@data-kg-new]'), $code . ': both new lines drawn again');
            self::assertSame(1, self::nodes($r, '//table[contains(@class, "gi-lines")]/tbody/tr[@data-kg-new][2][contains(@class, "kg-bad")]//*[contains(@class, "kg-error")]'),
                $code . ': the refusal on its row');
            $again = self::onePage($r, $id);
            self::assertSame('7', $again['line_1_packs'], $code . ': the edit as typed');
            self::assertSame($sent, json_decode($again[DocGrid::FIELD], true), $code . ': the field as sent');
            self::assertSame(['4', '5'], self::texts($r, '//table[contains(@class, "gi-lines")]/tbody/tr[@data-kg-new]/td[contains(@class, "c-no")]'), $code . ': numbered after the lines');
        }
        // Not the field's shape: 400, nothing read.
        $odd = $web->post("/ui/receiving/{$id}/lines", ['action' => 'save', DocGrid::FIELD => '{"si": 1}'] + $f);
        self::assertSame(400, $odd->status, self::why($odd));
        self::assertSame($v, self::docVersion($id));
    }

    /** A version conflict (the delivery changed in another tab) keeps the new lines (KG6): they name their item; nothing of them is saved. */
    public function testAVersionConflictKeepsTheNewLines(): void
    {
        $x = $this->setUpDelivery('Conflict Lines Ltd');
        $id = $x['id'];
        $f = self::onePage($x['web']->get("/ui/receiving/{$id}"), $id);
        // The keyer in another tab: line 3 out.
        $svc = $this->receipts();
        $svc->saveDraft(Caller::staff($x['desk']['id']), $id, self::docVersion($id), [], array_slice($svc->lines($id), 0, 2));
        $r = $x['web']->post("/ui/receiving/{$id}/lines", ['action' => 'save', DocGrid::FIELD => self::grid([['si' => $x['siD'], 'q' => '3', 'p' => '']])] + $f);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame(2, count(self::stored($id)), 'nothing of the new line saved');
        self::assertSame((string) $x['siD'], self::attr($r, '//table[contains(@class, "gi-lines")]/tbody/tr[@data-kg-new]/@data-si'), 'the new line drawn again');
        self::assertSame('3', self::attr($r, '//tr[@data-kg-new]//input[@data-kg-cell="qty"]/@value'));
        self::assertSame([['si' => $x['siD'], 'q' => '3', 'p' => '']], json_decode(self::onePage($r, $id)[DocGrid::FIELD], true));
    }

    /** The same form sent twice (a double click, the back button, a retry) is answered by its first answer: nothing added twice (KG5). */
    public function testTheSameFormTwiceAddsNothingTwice(): void
    {
        $x = $this->setUpDelivery('Twice Lines Ltd');
        $id = $x['id'];
        $f = self::onePage($x['web']->get("/ui/receiving/{$id}"), $id);
        $post = ['action' => 'save', DocGrid::FIELD => self::grid([['si' => $x['siD'], 'q' => '1', 'p' => '']])] + $f;
        $one = $x['web']->post("/ui/receiving/{$id}/lines", $post);
        $two = $x['web']->post("/ui/receiving/{$id}/lines", $post);
        self::assertSame([303, 303], [$one->status, $two->status], self::why($two));
        self::assertSame($one->location(), $two->location(), 'the first answer again');
        self::assertSame(4, count(self::stored($id)), 'one new line, once');
        self::assertNotSame($f['form_key'], self::onePage($x['web']->follow($one), $id)['form_key'], 'the page after a save has a new key');
        // New lines need the form's key (an older page has none and sends none).
        $nokey = $x['web']->post("/ui/receiving/{$id}/lines", ['form_key' => ''] + $post);
        self::assertSame(400, $nokey->status, self::why($nokey));
    }

    /** Someone who did not key the delivery adds no line (the service's creator rule, as Add); the scan box's route is for who receives goods. */
    public function testOnlyTheKeyerAddsLines(): void
    {
        $x = $this->setUpDelivery('Keyer Only Ltd');
        $id = $x['id'];
        $bench = $this->signIn($this->uiUser('goods_in'));
        $g = self::onePage($bench->get("/ui/receiving/{$id}"), $id);
        $v = self::docVersion($id);
        $r = $bench->post("/ui/receiving/{$id}/lines", ['action' => 'save', 'form_key' => str_repeat('a', 32), DocGrid::FIELD => self::grid([['si' => $x['siD'], 'q' => '1', 'p' => '']])] + $g);
        self::assertSame(403, $r->status, self::why($r));
        self::assertSame([$v, 3], [self::docVersion($id), count(self::stored($id))]);
        self::assertSame(403, $this->signIn($this->uiUser('stock_controller'))->get("/ui/receiving/{$id}/products", ['q' => 'A-5'])->status, 'who only looks');
        self::assertSame(0, self::nodes($bench->get("/ui/receiving/{$id}/products", ['q' => 'A-5']), '//li[@data-price]'), 'goods in: no price');
    }

    /** Without the script (no field, no key): Add and Save as before; "Book in" with new lines saves them first and books in only when ready. */
    public function testWithoutTheScriptAsBeforeAndBookInSavesTheNewLinesFirst(): void
    {
        $x = $this->setUpDelivery('No Script Lines Ltd');
        $id = $x['id'];
        $web = $x['web'];
        $f = array_diff_key(self::onePage($web->get("/ui/receiving/{$id}"), $id), [DocGrid::FIELD => 1, 'form_key' => 1]);
        $add = $web->post("/ui/receiving/{$id}/lines", ['q' => 'D-10', 'packs' => '2', 'action' => 'add'] + $f);
        self::assertSame(303, $add->status, self::why($add));
        self::assertStringContainsString('notice=added', (string) $add->location());
        self::assertSame([$x['d'], 2], [(int) self::stored($id)[3]['sku_id'], (int) self::stored($id)[3]['packs']]);
        $f = array_diff_key(self::onePage($web->follow($add), $id), [DocGrid::FIELD => 1, 'form_key' => 1]);
        $save = $web->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '9', 'action' => 'save'] + $f);
        self::assertStringStartsWith("/ui/receiving/{$id}?notice=saved", (string) $save->location(), self::why($save));
        self::assertSame(9, (int) self::stored($id)[0]['packs']);
        // "Book in" with a new line: the line is saved first (the same save), then the booking is refused (its lines are not checked).
        $f = self::onePage($web->follow($save), $id);
        $book = $web->post("/ui/receiving/{$id}/lines", ['action' => 'post', DocGrid::FIELD => self::grid([['si' => $x['siB'], 'q' => '1', 'p' => '']])] + $f);
        self::assertContains($book->status, [409, 422], self::why($book));
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]), 'not booked in: lines to check');
        self::assertSame(4, (int) self::stored($id)[1]['packs'], 'B: 3 + 1 on its own line, saved before the booking');
    }
}
