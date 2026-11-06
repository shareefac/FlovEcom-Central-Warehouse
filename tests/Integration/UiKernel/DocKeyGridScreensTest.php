<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Invoices\SupplierInvoices;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\DocGrid;
use CW\Ui\Words;

/**
 * The purchase order's and the supplier invoice's lines as a standard grid (the owner, 6 Nov 2026: "purchase invoice and sales invoice,
 * purchase order view style need to standard feel, need also key shortcut: item new and delete, field switching, all need";
 * docs/decisions.md KG1-KG12), as cw_app through the real kernel. With the script, a line is filled in place and every POST of the page
 * sends ALL lines at once: the saved lines edited as before (line_N_* / p_N, pp_N), the saved lines removed (line_N_remove / x_N) and the
 * new lines in one field (`new_lines`, JSON: si or sku, q, p). The server applies them in the save's own transaction, before the action:
 *  - several new lines + a removal + an edit in ONE post, on a draft and on a new document (its first Save makes it, once: FormOnce);
 *  - a version conflict, the lines limit, another supplier's item or a switched-off one: refused, nothing saved, every typed line kept
 *    (new ones too) and the refusal on the line it concerns;
 *  - without the script the page is as before (Add and the x submit);
 *  - the lines have a No. column, the key hints say their words, a saved invoice's Matched line shows its pill alone.
 */
final class DocKeyGridScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_kg_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/store', 0700, true);
        // Files go to a temporary file store, never to the server's own (docs/dev.md).
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** A dry item (no duty stamp) of $name. */
    private function dryItem(string $name): int
    {
        $sku = self::makeSku($name);
        (new ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        return $sku;
    }

    /** @return array<string, mixed> one of $supplierId's packs of $sku */
    private function pack(int $buyerId, int $supplierId, int $sku, int $upp, string $code, string $price): array
    {
        return (new SupplierItems(self::$db))->create(Caller::staff($buyerId), $supplierId, $sku, ['units_per_pack' => (string) $upp, 'supplier_code' => $code],
            ['pack_price' => $price]);
    }

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    private function invoicesService(): SupplierInvoices
    {
        return new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    /** @param list<array<string, mixed>> $lines @return string the `new_lines` field as the script sends it */
    private static function grid(array $lines): string
    {
        return json_encode($lines, JSON_THROW_ON_ERROR);
    }

    /** What a failed answer says, with the kernel's log (a 500's cause). */
    private static function why(UiResponse $r): string
    {
        return $r->describe() . "\n" . implode("\n", array_slice(self::$log, -3));
    }

    private static function nodes(UiResponse $r, string $xpath): int
    {
        return (new \DOMXPath($r->dom()))->query($xpath)->length;
    }

    /** @return list<string> */
    private static function texts(UiResponse $r, string $xpath): array
    {
        return array_map(static fn (\DOMNode $n): string => trim(preg_replace('/\s+/u', ' ', (string) $n->textContent) ?? ''), iterator_to_array((new \DOMXPath($r->dom()))->query($xpath)));
    }

    /** @return list<array{string, string, string, string}> the new lines the page drew again: [si, sku, qty, price] */
    private static function keptNew(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $tr): array => [$tr->getAttribute('data-si'), $tr->getAttribute('data-sku'),
            (string) $xp->evaluate('string(.//input[@data-kg-cell="qty"]/@value)', $tr), (string) $xp->evaluate('string(.//input[@data-kg-cell="price"]/@value)', $tr)],
            iterator_to_array($xp->query('//table[@data-kg-grid]/tbody/tr[@data-kg-new]')));
    }

    /** @return list<array{int, int, string}> an order's lines: [supplier item (0: none), packs, pack price] in line order */
    private static function poLines(int $id): array
    {
        return array_map(static fn (array $l): array => [(int) $l['supplier_item_id'], (int) $l['packs'], (string) $l['pack_price']],
            self::$db->all('SELECT supplier_item_id, packs, pack_price FROM po_line WHERE document_id = ? ORDER BY line_no', [$id]));
    }

    /** @return list<array{int, int, int, string}> an order's item lines: [item, packs, units per pack, pack price] */
    private static function poItems(int $id): array
    {
        return array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['units_per_pack'], (string) $l['pack_price']],
            self::$db->all('SELECT dl.sku_id, pl.packs, pl.units_per_pack, pl.pack_price FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id '
                . 'AND dl.line_no = pl.line_no WHERE pl.document_id = ? ORDER BY pl.line_no', [$id]));
    }

    /** @return list<array{int, int, int, string, ?string}> an invoice's goods lines: [item, packs, units per pack, pack price, their code] */
    private static function invoiceLines(int $id): array
    {
        return array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['units_per_pack'], (string) $l['pack_price'], $l['supplier_code']],
            self::$db->all('SELECT dl.sku_id, sl.packs, sl.units_per_pack, sl.pack_price, sl.supplier_code FROM sinv_line sl JOIN document_line dl ON dl.document_id = sl.document_id '
                . 'AND dl.line_no = sl.line_no WHERE sl.document_id = ? ORDER BY sl.line_no', [$id]));
    }

    private static function docs(string $type): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM document WHERE doc_type = ?', [$type]);
    }

    private static function idOf(?string $location, string $base): int
    {
        self::assertSame(1, preg_match('#^' . preg_quote($base, '#') . '/(\d+)(?:[?\#]|$)#', (string) $location, $m), (string) $location);
        return (int) $m[1];
    }

    // ------------------------------------------------------------------------------------------
    // The purchase order
    // ------------------------------------------------------------------------------------------

    /** A new order: its first Save makes the draft with every line typed in the grid, once however often the form is sent. */
    public function testANewOrderIsMadeByItsFirstSaveWithEveryLineOnce(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier('Grid Orders Ltd');
        $pod = self::makeSku('Grid pod 2ml');
        $cherry = self::makeSku('Grid liquid cherry');
        $loose = self::makeSku('Grid loose coil');
        $siPod = $this->pack($buyer['id'], (int) $s['id'], $pod, 10, 'GP-10', '12.50');
        $siCherry = $this->pack($buyer['id'], (int) $s['id'], $cherry, 6, 'GC-6', '9.00');
        $web = $this->signIn($buyer);
        $form = $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders');
        self::assertArrayHasKey(DocGrid::FIELD, $form, 'the grid\'s one field is in the form');
        self::assertSame('', $form[DocGrid::FIELD]);

        $send = ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siPod['id'], 'q' => '2', 'p' => '12.50'],
            ['sku' => $loose, 'q' => '3', 'p' => '1.20'],
            ['si' => (int) $siCherry['id'], 'q' => '1', 'p' => ''],
        ])] + $form;
        $a = $web->post('/ui/purchasing/orders', $send);
        self::assertSame(303, $a->status, self::why($a));
        self::assertStringContainsString('notice=created', (string) $a->location());
        $b = $web->post('/ui/purchasing/orders', $send);
        self::assertSame($a->location(), $b->location(), 'sent twice: the same draft (FormOnce)');
        self::assertSame(1, self::docs('PO'));
        $id = self::idOf($a->location(), '/ui/purchasing/orders');
        // In the grid's order; a price left empty is the supplier's last price, as Add does; an item not set up is a line without a pack.
        self::assertSame([[$pod, 2, 10, '12.5000'], [$loose, 3, 1, '1.2000'], [$cherry, 1, 6, '9.0000']], self::poItems($id));
        self::assertSame([(int) $siPod['id'], 0, (int) $siCherry['id']], array_column(self::poLines($id), 0));

        // Add with nothing in the box but lines in the grid saves them (Enter in a field of the page): nothing is refused for "no product".
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'add', DocGrid::FIELD => self::grid([['si' => (int) $siPod['id'], 'q' => '1', 'p' => '12.50']])]
            + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([[$pod, 1, 10, '12.5000']], self::poItems(self::idOf($r->location(), '/ui/purchasing/orders')));
        // Confirm with grid lines: saved and confirmed in one go, as with lines added one by one.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'approve', DocGrid::FIELD => self::grid([['si' => (int) $siCherry['id'], 'q' => '2', 'p' => '9.00']])]
            + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(303, $r->status, self::why($r));
        $confirmed = self::idOf($r->location(), '/ui/purchasing/orders');
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$confirmed]));
        self::assertSame([[$cherry, 2, 6, '9.0000']], self::poItems($confirmed));
        // A malformed field (not the script's): refused, nothing made.
        $before = self::docs('PO');
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => '{"si": 1}']
            + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(400, $r->status, self::why($r));
        self::assertSame($before, self::docs('PO'));
    }

    /** A draft: the saved lines edited, one removed and new lines added, all in one post, in the save's transaction, before the action. */
    public function testADraftTakesEditsARemovalAndNewLinesInOnePost(): void
    {
        $buyer = $this->uiUser('buyer');
        $bid = $buyer['id'];
        $s = $this->supplier('Grid Draft Orders Ltd');
        $pod = self::makeSku('Draft grid pod');
        $cherry = self::makeSku('Draft grid cherry');
        $mango = self::makeSku('Draft grid mango');
        $coil = self::makeSku('Draft grid coil');
        $siPod = $this->pack($bid, (int) $s['id'], $pod, 10, 'DP-10', '12.50');
        $siCherry = $this->pack($bid, (int) $s['id'], $cherry, 6, 'DC-6', '9.00');
        $siMango = $this->pack($bid, (int) $s['id'], $mango, 6, 'DM-6', '8.00');
        $d = $this->pos()->createDraft(Caller::staff($bid), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($bid), $d->id, $d->version, [], [['supplier_item_id' => (int) $siPod['id'], 'packs' => 1],
            ['supplier_item_id' => (int) $siCherry['id'], 'packs' => 2], ['sku_id' => $coil, 'packs' => 1, 'pack_price' => '0.50']]);
        $web = $this->signIn($buyer);
        $page = $web->get("/ui/purchasing/orders/{$d->id}");
        $f = $page->form('/lines');
        self::assertSame(['csrf', 'version', 'line_count', 'lines_editable'], array_slice(array_keys($f), 0, 4), 'the pins of the draft\'s form hold');
        self::assertSame('', $f[DocGrid::FIELD]);

        $send = ['line_1_packs' => '5', 'line_2_remove' => '1', 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siMango['id'], 'q' => '4', 'p' => '7.75'], ['si' => (int) $siCherry['id'], 'q' => '1', 'p' => '9.00']])] + $f;
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([[$pod, 5, 10, '12.5000'], [$coil, 1, 1, '0.5000'], [$mango, 4, 6, '7.7500'], [$cherry, 1, 6, '9.0000']], self::poItems($d->id));
        // The same form sent again (a double click, the back button): answered as the first, nothing added twice (FormOnce with the version).
        $again = $web->post("/ui/purchasing/orders/{$d->id}/lines", $send);
        self::assertSame($r->location(), $again->location(), self::why($again));
        self::assertCount(4, self::poItems($d->id));
        // The draft's page after: the No. column numbers the lines; the grid has no new line left over.
        $ed = $web->follow($r);
        self::assertSame(['1', '2', '3', '4'], self::texts($ed, '//table[@data-kg-grid]/tbody/tr[@data-kg-line]/td[contains(@class, "c-no")]'));
        self::assertSame([], self::keptNew($ed));

        // A removed charge goes the same way (line_N_remove on any line), and a line removed is never read (its packs box emptied).
        $f = $ed->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_2_remove' => '1', 'line_2_packs' => '', 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([$pod, $mango, $cherry], array_column(self::poItems($d->id), 0));

        // Without the script, as before: the row's x is a Save without that row, Add adds the box's product.
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['remove_line' => '3'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=removed#scan", $r->location(), self::why($r));
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['q' => 'DC-6', 'packs' => '2', 'action' => 'add'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=added#scan", $r->location(), self::why($r));
        self::assertSame([[$pod, 5, 10, '12.5000'], [$mango, 4, 6, '7.7500'], [$cherry, 2, 6, '9.0000']], self::poItems($d->id));
    }

    /** A refused save keeps every typed line (the new ones too) and says the refusal on the line it concerns; nothing is saved. */
    public function testARefusedOrderKeepsEveryTypedLineAndPointsAtTheLine(): void
    {
        $buyer = $this->uiUser('buyer');
        $bid = $buyer['id'];
        $s = $this->supplier('Grid Refusals Ltd');
        $other = $this->supplier('Grid Other Ltd');
        $pod = self::makeSku('Refusal grid pod');
        $cherry = self::makeSku('Refusal grid cherry');
        $siPod = $this->pack($bid, (int) $s['id'], $pod, 10, 'RP-10', '12.50');
        $siOff = $this->pack($bid, (int) $s['id'], $cherry, 6, 'RC-6', '9.00');
        $siOther = $this->pack($bid, (int) $other['id'], $cherry, 12, 'OT-12', '15.00');
        self::$db->exec('UPDATE supplier_item SET is_active = 0 WHERE id = ?', [(int) $siOff['id']]);
        $web = $this->signIn($buyer);

        // A switched-off pack, on the new page: refused, no draft, every line kept, the refusal on the second line.
        $before = self::docs('PO');
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'save', 'external_ref' => 'Q-77', DocGrid::FIELD => self::grid([
            ['si' => (int) $siPod['id'], 'q' => '2', 'p' => '12.50'], ['si' => (int) $siOff['id'], 'q' => '3', 'p' => '9.00']])]
            + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame($before, self::docs('PO'));
        self::assertSame([[(string) $siPod['id'], '', '2', '12.50'], [(string) $siOff['id'], '', '3', '9.00']], self::keptNew($r));
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][2][contains(@class, "kg-bad")]//*[contains(@class, "kg-error")]'), self::why($r));
        self::assertSame(0, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][1][contains(@class, "kg-bad")]'));
        self::assertSame(['Q-77', self::grid([['si' => (int) $siPod['id'], 'q' => '2', 'p' => '12.50'], ['si' => (int) $siOff['id'], 'q' => '3', 'p' => '9.00']])],
            [$r->form('/ui/purchasing/orders')['external_ref'], $r->form('/ui/purchasing/orders')[DocGrid::FIELD]], 'what was typed is in the form again');
        self::assertSame(1, self::nodes($r, '//p[@class="error"][@role="alert"]'), 'said at the top too');
        // Another supplier's pack (picked before the supplier box changed): moved to this supplier (KG30, which replaced the refusal of KG11):
        // its item, as this supplier has no pack of it switched on, its units and price kept.
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siOther['id'], 'q' => '3', 'p' => '15.00']])] + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        $moved = self::idOf($r->location(), '/ui/purchasing/orders');
        self::assertSame([[$cherry, 3, 12, '15.0000']], self::poItems($moved), self::why($r));
        self::assertSame([[0, 3, '15.0000']], self::poLines($moved));

        // A switched-off pack, on a draft: refused, nothing saved, the edits and the new line kept.
        $d = $this->pos()->createDraft(Caller::staff($bid), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($bid), $d->id, $d->version, [], [['supplier_item_id' => (int) $siPod['id'], 'packs' => 1]]);
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_1_packs' => '7', 'action' => 'save', DocGrid::FIELD => self::grid([['si' => (int) $siOff['id'], 'q' => '1', 'p' => '9.00']])] + $f);
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame([[(int) $siPod['id'], 1, '12.5000']], self::poLines($d->id), 'nothing saved');
        self::assertSame([[(string) $siOff['id'], '', '1', '9.00']], self::keptNew($r));
        self::assertSame('7', $r->form('/lines')['line_1_packs'], 'the edit is kept');
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][1][contains(@class, "kg-bad")]'));

        // A version conflict (the draft changed in another tab): refused, nothing saved, the new lines kept (the saved lines as they are now).
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $this->pos()->saveDraft(Caller::staff($bid), $d->id, (int) $f['version'], [], [['supplier_item_id' => (int) $siPod['id'], 'packs' => 3]]);
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['action' => 'save', DocGrid::FIELD => self::grid([['sku' => $cherry, 'q' => '2', 'p' => '4.00']])] + $f);
        self::assertSame(409, $r->status, self::why($r));
        self::assertStringContainsString(Words::BUY_ERROR['version_conflict'], $r->text());
        self::assertSame(1, self::nodes($r, '//form[@id="po-form"][@data-kg-conflict]'), 'the script posts it plainly, the head drawn again (KG32, Codex finding 6)');
        self::assertSame([['', (string) $cherry, '2', '4.00']], self::keptNew($r));
        self::assertSame([[(int) $siPod['id'], 3, '12.5000']], self::poLines($d->id));
        $now = $r->form('/lines');
        self::assertSame((string) (new Documents(self::$db, DocumentHandlers::all(self::$db)))->get($d->id)->version, $now['version'], 'drawn with the version now');
        self::assertSame(self::grid([['sku' => $cherry, 'q' => '2', 'p' => '4.00']]), $now[DocGrid::FIELD]);
        // Sent again from that page, the lines are saved.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['action' => 'save'] + $now);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([[$pod, 3, 10, '12.5000'], [$cherry, 2, 1, '4.0000']], self::poItems($d->id));

        // The lines limit: an order has at most PurchaseOrders::MAX_LINES lines; more are refused with every typed line kept.
        $many = array_fill(0, PurchaseOrders::MAX_LINES + 1, ['si' => (int) $siPod['id'], 'q' => '1', 'p' => '12.50']);
        $r = $web->post('/ui/purchasing/orders', ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => self::grid($many)]
            + $web->get('/ui/purchasing/orders/new')->form('/ui/purchasing/orders'));
        self::assertSame(422, $r->status, self::why($r));
        self::assertStringContainsString(Words::say('BUY_ERROR', 'too_many_lines', PurchaseOrders::MAX_LINES), $r->text());
        self::assertSame(PurchaseOrders::MAX_LINES + 1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new]'));
        self::assertSame((string) PurchaseOrders::MAX_LINES, (string) (new \DOMXPath($r->dom()))->evaluate('string(//table[@data-kg-grid]/@data-kg-max)'));
    }

    /** The order's page: the No. column, compact rows, the empty line, the key hints in their words; the saved order's No. column. */
    public function testTheOrdersGridHasItsNumbersAndItsKeyHints(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier('Grid Look Ltd');
        $pod = self::makeSku('Look grid pod');
        $siPod = $this->pack($buyer['id'], (int) $s['id'], $pod, 10, 'LG-10', '12.50');
        $d = $this->pos()->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['supplier_item_id' => (int) $siPod['id'], 'packs' => 2]]);
        $web = $this->signIn($buyer);
        foreach ([['/ui/purchasing/orders/new', ['supplier' => $s['id']]], ["/ui/purchasing/orders/{$d->id}", []]] as [$path, $query]) {
            $page = $web->get($path, $query);
            self::assertSame(200, $page->status, self::why($page));
            $grid = '//form[contains(@class, "po-editor")]//table[@data-kg-grid]';
            self::assertSame(1, self::nodes($page, $grid . '[contains(@class, "doc-lines")]'), $path);
            self::assertSame(Words::DOC_GRID['no'], self::texts($page, $grid . '/thead/tr/th[1]')[0] ?? null, $path);
            self::assertSame(1, self::nodes($page, $grid . '/tbody/tr[last()][contains(@class, "grp-add")][@data-kg-empty]//input[@name="q"][@data-po-pick]'), 'the empty line last: the product box');
            self::assertSame(1, self::nodes($page, '//form[contains(@class, "po-editor")]//template[@data-kg-row]'), 'the new line\'s row');
            // The key hints: one short line, its keys as kbd chips with their words (Ctrl, and Cmd for a Mac, from the page), then "All keys (?)",
            // the panel of every key (KG19).
            $keys = '//p[contains(@class, "kg-keys")]';
            self::assertSame(1, self::nodes($page, $keys), $path);
            // As the demo the owner approved (KG31): Tab next, ↑↓ line, Ctrl+Enter new line, Alt+R delete, Alt+U undo, Ctrl+D copy above, Ctrl+S save.
            self::assertSame(array_map(static fn (string $k): string => Words::DOC_GRID['kbd_' . $k], ['tab', 'updown', 'new', 'del', 'undo', 'copy', 'save']),
                self::texts($page, $keys . '//kbd'));
            foreach (['k_tab', 'k_updown', 'k_new', 'k_del', 'k_undo', 'k_copy', 'k_save'] as $w) {
                self::assertStringContainsString(Words::DOC_GRID[$w], implode(' ', self::texts($page, $keys)), $w);
            }
            self::assertSame(5, self::nodes($page, $keys . '//kbd[@data-mac]'), 'Cmd and Option on a Mac');
            self::assertSame(1, self::nodes($page, '//div[contains(@class, "kg-head-bar")]/button[@data-kb-open][@data-kg-jsonly]'), 'the head\'s Keys (?)');
            self::assertSame([Words::DOC_GRID['all_keys']], self::texts($page, $keys . '//button[@data-kb-open][@aria-controls="kb-panel"]'));
            self::assertSame(1, self::nodes($page, '//div[@id="kb-panel"][@data-kb-panel][@hidden][@role="dialog"]'), 'the panel of every key, in the layout');
            // ERPNext's head as the demo the owner approved (KG26, KG31): the actions on the right of the title, for the editor form; Confirm the main one.
            self::assertSame(['approve'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
                iterator_to_array((new \DOMXPath($page->dom()))->query('//div[contains(@class, "kg-head-bar")]/button[contains(concat(" ", @class, " "), " primary ")][@form="po-form"]'))), $path);
            self::assertSame(1, self::nodes($page, '//form[@id="po-form"][starts-with(@data-kg-editor, "po:")]'), $path);
            foreach (['data-kg-add-row', 'data-kg-several-open', 'data-kg-delete'] as $tool) {
                self::assertSame(1, self::nodes($page, '//div[contains(@class, "kg-tools")]//button[@' . $tool . '][@hidden]'), $tool . ': drawn hidden, the script shows it');
            }
            self::assertSame(1, self::nodes($page, '//*[@aria-live="polite"]/*[@data-kg-undo-line][@hidden]//button[@type="button"][. = "' . Words::DOC_GRID['undo'] . '"]'));
            self::assertSame(1, self::nodes($page, '//input[@name="q"][@aria-keyshortcuts]'));
        }
        $ed = $web->get("/ui/purchasing/orders/{$d->id}");
        $row = '//table[@data-kg-grid]/tbody/tr[@data-kg-line="1"]';
        self::assertSame(['1'], self::texts($ed, $row . '/td[contains(@class, "c-no")]'));
        self::assertSame(1, self::nodes($ed, $row . '//input[@name="line_1_packs"][@data-kg-cell="qty"][@aria-keyshortcuts]'));
        self::assertSame(1, self::nodes($ed, $row . '//input[@name="line_1_price"][@data-kg-cell="price"]'));
        self::assertSame(1, self::nodes($ed, $row . '//button[@name="remove_line"][@value="1"][@data-kg-x]'), 'the x: a submit without the script');
        self::assertSame(1, self::nodes($ed, $row . '/th[contains(@class, "c-item")]//*[contains(@class, "kg-code")][. = "' . sprintf('CW-%06d', $pod) . '"]'), 'the CW code beside the name');
        // The products route carries what fills a line in place: the item's name and CW number, the pack, the supplier's price.
        $frag = $web->get('/ui/purchasing/orders/products', ['supplier' => $s['id'], 'q' => 'LG-10']);
        $li = (new \DOMXPath($frag->dom()))->query('//ul[@data-po-choices]/li')->item(0);
        self::assertInstanceOf(\DOMElement::class, $li);
        self::assertSame([(string) $siPod['id'], 'Look grid pod', sprintf('CW-%06d', $pod), '10', 'LG-10', '12.50'], [$li->getAttribute('data-si'), $li->getAttribute('data-name'),
            $li->getAttribute('data-code'), $li->getAttribute('data-upp'), $li->getAttribute('data-their'), $li->getAttribute('data-price')]);
        self::assertNotSame('', $li->getAttribute('data-pack'));

        // The saved order: a No. column first, the code beside the name, the rest as before.
        $this->pos()->approve(Caller::staff($buyer['id']), $d->id, (new Documents(self::$db, DocumentHandlers::all(self::$db)))->get($d->id)->version);
        $view = $web->get("/ui/purchasing/orders/{$d->id}");
        self::assertSame([Words::DOC_GRID['no'], Words::ORDER['col_item'], Words::ORDER['col_ordered'], Words::ORDER['col_received'], Words::ORDER['col_cost'],
            Words::ORDER['col_line_total']], self::texts($view, '//table[contains(@class, "doc-lines")]/thead/tr/th'));
        self::assertSame(['1'], self::texts($view, '//table[contains(@class, "doc-lines")]/tbody/tr/td[contains(@class, "c-no")]'));
        self::assertSame([sprintf('CW-%06d', $pod)], self::texts($view, '//table[contains(@class, "doc-lines")]/tbody/tr/th//*[contains(@class, "kg-code")]'));
    }

    // ------------------------------------------------------------------------------------------
    // The supplier invoice
    // ------------------------------------------------------------------------------------------

    /** A new invoice: its first Save makes the draft with the grid's lines, once; a draft takes edits, a removal and new lines in one post. */
    public function testTheInvoiceTakesEveryLineInOnePost(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $bid = $this->uiUser('buyer')['id'];
        $s = $this->supplier('Grid Invoices Ltd');
        $pod = $this->dryItem('Invoice grid pod');
        $coil = $this->dryItem('Invoice grid coil');
        $mesh = $this->dryItem('Invoice grid mesh');
        $siPod = $this->pack($bid, (int) $s['id'], $pod, 6, 'IP-6', '9.00');
        $web = $this->signIn($desk);
        $form = $web->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true);
        self::assertSame('', $form[DocGrid::FIELD] ?? null);

        $send = ['supplier_id' => (string) $s['id'], 'invoice_number' => 'GRID-1', 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siPod['id'], 'q' => '2', 'p' => '9.00'], ['sku' => $coil, 'q' => '5', 'p' => '1.10'], ['si' => (int) $siPod['id'], 'q' => '1', 'p' => '']])] + $form;
        $a = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame(303, $a->status, self::why($a));
        $b = $web->post('/ui/purchasing/invoices', $send);
        self::assertSame($a->location(), $b->location(), 'sent twice: the same draft');
        self::assertSame(1, self::docs('SINV'));
        $id = self::idOf($a->location(), '/ui/purchasing/invoices');
        self::assertSame([[$pod, 2, 6, '9.0000', 'IP-6'], [$coil, 5, 1, '1.1000', null], [$pod, 1, 6, '9.0000', 'IP-6']], self::invoiceLines($id), 'an empty price: the pack\'s last');

        // The draft: line 1 edited, line 2 removed (its box), two new lines, all in one post.
        $ed = $web->follow($a);
        $f = $ed->form("/ui/purchasing/invoices/{$id}/lines", true);
        self::assertSame(['csrf', 'version', 'line_count', 'form_key'], array_slice(array_keys($f), 0, 4), 'the pins of the draft\'s form hold');
        self::assertSame(['1', '2', '3'], self::texts($ed, '//table[@data-kg-grid]/tbody/tr[@data-kg-line]/td[contains(@class, "c-no")]'));
        $send = ['p_1' => '4', 'pp_1' => '8.50', 'x_2' => '1', 'action' => 'save', DocGrid::FIELD => self::grid([['sku' => $mesh, 'q' => '10', 'p' => '0.35'],
            ['si' => (int) $siPod['id'], 'q' => '3', 'p' => '9.25']])] + $f;
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", $send);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([[$pod, 4, 6, '8.5000', 'IP-6'], [$pod, 1, 6, '9.0000', 'IP-6'], [$mesh, 10, 1, '0.3500', null], [$pod, 3, 6, '9.2500', 'IP-6']], self::invoiceLines($id));
        $again = $web->post("/ui/purchasing/invoices/{$id}/lines", $send);
        self::assertSame($r->location(), $again->location(), 'sent twice: once');
        self::assertCount(4, self::invoiceLines($id));

        // Without the script, as before: the row's x, and Add with the box.
        $f = $web->get("/ui/purchasing/invoices/{$id}")->form("/ui/purchasing/invoices/{$id}/lines", true);
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['remove_line' => '2'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        $f = $web->get("/ui/purchasing/invoices/{$id}")->form("/ui/purchasing/invoices/{$id}/lines", true);
        $r = $web->post("/ui/purchasing/invoices/{$id}/lines", ['q' => sprintf('CW-%06d', $coil), 'packs' => '2', 'price' => '1.00', 'action' => 'add'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([$pod, $mesh, $pod, $coil], array_column(self::invoiceLines($id), 0));
    }

    /** A refused invoice keeps every typed line and points at the line; the lines limit; a version conflict. */
    public function testARefusedInvoiceKeepsEveryTypedLineAndPointsAtTheLine(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $bid = $this->uiUser('buyer')['id'];
        $s = $this->supplier('Grid Invoice Refusals Ltd');
        $other = $this->supplier('Grid Invoice Other Ltd');
        $pod = $this->dryItem('Invoice refusal pod');
        $siPod = $this->pack($bid, (int) $s['id'], $pod, 6, 'IR-6', '9.00');
        $siOther = $this->pack($bid, (int) $other['id'], $pod, 12, 'IO-12', '15.00');
        $siOff = $this->pack($bid, (int) $s['id'], $pod, 24, 'IR-24', '30.00');
        self::$db->exec('UPDATE supplier_item SET is_active = 0 WHERE id = ?', [(int) $siOff['id']]);
        $web = $this->signIn($desk);

        // A switched-off pack: refused, nothing made, the lines kept, the refusal on that line.
        $before = self::docs('SINV');
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'REF-1', 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siPod['id'], 'q' => '1', 'p' => '9.00'], ['si' => (int) $siOff['id'], 'q' => '2', 'p' => '30.00']])]
            + $web->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true));
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame($before, self::docs('SINV'));
        self::assertSame([[(string) $siPod['id'], '', '1', '9.00'], [(string) $siOff['id'], '', '2', '30.00']], self::keptNew($r));
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][2][contains(@class, "kg-bad")]//*[contains(@class, "kg-error")]'), self::why($r));
        self::assertSame('REF-1', $r->form('/ui/purchasing/invoices', true)['invoice_number']);
        // Another supplier's pack (picked before the supplier box changed): moved to this supplier's pack of the item (KG30, which replaced
        // the refusal of KG11): 2 of 12 are 4 of this supplier's 6, at its price.
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => self::grid([['si' => (int) $siOther['id'], 'q' => '2', 'p' => '15.00']])]
            + $web->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true));
        self::assertSame([[$pod, 4, 6, '9.0000', 'IR-6']], self::invoiceLines(self::idOf($r->location(), '/ui/purchasing/invoices')), self::why($r));
        $before = self::docs('SINV');

        // A version conflict on a draft: refused, the new lines kept.
        $si = $this->invoicesService();
        $doc = $si->createDraft(Caller::staff($desk['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'REF-2']);
        $f = $web->get("/ui/purchasing/invoices/{$doc->id}")->form("/ui/purchasing/invoices/{$doc->id}/lines", true);
        $si->saveHeader(Caller::staff($desk['id']), $doc->id, (int) $f['version'], ['note' => 'changed in another tab']);
        $r = $web->post("/ui/purchasing/invoices/{$doc->id}/lines", ['action' => 'save', DocGrid::FIELD => self::grid([['si' => (int) $siPod['id'], 'q' => '2', 'p' => '9.00']])] + $f);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame([[(string) $siPod['id'], '', '2', '9.00']], self::keptNew($r));
        self::assertSame([], self::invoiceLines($doc->id));

        // The lines limit (SupplierInvoices::MAX_LINES): refused with every typed line kept.
        $before = self::docs('SINV');
        $many = array_fill(0, SupplierInvoices::MAX_LINES + 1, ['si' => (int) $siPod['id'], 'q' => '1', 'p' => '9.00']);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'action' => 'save', DocGrid::FIELD => self::grid($many)]
            + $web->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true));
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame($before, self::docs('SINV'));
        self::assertSame(SupplierInvoices::MAX_LINES + 1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new]'));
        self::assertSame((string) SupplierInvoices::MAX_LINES, (string) (new \DOMXPath($r->dom()))->evaluate('string(//table[@data-kg-grid]/@data-kg-max)'));
    }

    /** The invoice's page: the No. column and the key hints; the saved invoice's lines: No. first, a Matched line its pill alone. */
    public function testTheInvoicesGridAndItsSavedLinesFollowTheStandardLook(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Grid Invoice Look Ltd');
        $web = $this->signIn($desk);
        $page = $web->get('/ui/purchasing/invoices/new', ['supplier' => $s['id']]);
        $grid = '//form[contains(@class, "pi-editor")]//table[@data-kg-grid]';
        self::assertSame([Words::DOC_GRID['no'], Words::PI_ONE['item'], Words::PI_ONE['qty'], Words::PI_ONE['price'], Words::PI_ONE['amount']],
            array_slice(self::texts($page, $grid . '/thead/tr/th'), 0, 5));
        self::assertSame(1, self::nodes($page, $grid . '/tbody/tr[last()][@data-kg-empty]//input[@name="q"]'));
        self::assertSame(1, self::nodes($page, '//p[contains(@class, "kg-keys")]'));
        self::assertSame(1, self::nodes($page, '//form[@id="pi-form"][@data-kg-editor="pi:new"]'));
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "doc-totals")]//dd[@data-kg-total="sum"]'));
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "doc-totals")]//dd[@data-kg-total="total"]'));

        // A delivery of 10 at £3.00, invoiced at £3.00 with the paper's totals: every line Matched.
        $sku = $this->dryItem('Matched grid pod');
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $grns = new GoodsReceipts(self::$db, $docs, null, fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($this->dir . '/store')));
        $c = Caller::staff($desk['id']);
        $g = $grns->createDraft($c, (int) $s['id'], ['invoice_number' => 'MG-1']);
        $g = $grns->saveDraft($c, $g->id, $g->version, [], [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '3.00']]);
        $pdf = $this->dir . '/invoice.pdf';
        file_put_contents($pdf, (new \CW\Output\PdfWriter('Invoice MG-1', 'test', true))->output());
        $grns->attach($c, $g->id, $pdf, 'invoice.pdf', 'supplier_invoice');
        $g = $grns->bench(Caller::staff($this->uiUser('goods_in')['id']), $g->id, $docs->get($g->id)->version, ['paperwork_ok' => '1'],
            [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $g = $grns->post($c, $g->id, $docs->get($g->id)->version);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'from' => 'grn:' . $g->id, 'invoice_number' => 'MG-1',
            'net_total' => '30.00', 'vat_total' => '6.00', 'action' => 'post'] + $web->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true));
        self::assertSame(303, $r->status, self::why($r));
        $id = self::idOf($r->location(), '/ui/purchasing/invoices');
        self::assertSame('matched', self::$db->value('SELECT match_state FROM supplier_invoice WHERE document_id = ?', [$id]));
        $view = $web->get("/ui/purchasing/invoices/{$id}");
        $t = '//article[contains(@class, "doc-view")]//table[contains(@class, "doc-lines")]';
        self::assertSame(Words::DOC_GRID['no'], self::texts($view, $t . '/thead/tr/th[1]')[0] ?? null, self::why($view));
        self::assertSame(['1'], self::texts($view, $t . '/tbody/tr/td[contains(@class, "c-no")]'));
        self::assertSame([Words::MATCH_LINE['matched']], self::texts($view, $t . '/tbody/tr/td[contains(@class, "c-status")]/span[contains(@class, "chip")]'));
        self::assertSame(0, self::nodes($view, $t . '/tbody/tr/td[contains(@class, "c-status")]/span[@class="o-sub"]'), 'Matched: the pill alone');
    }

    /** "+ Add new item" by the script (KG21): its own route makes the item and the supplier's pack, never the document; who may; FormOnce. */
    public function testTheNewItemRouteMakesTheItemNeverTheDocument(): void
    {
        $manager = $this->uiUser('purchasing_manager');
        $s = $this->supplier('Grid New Items Ltd');
        $web = $this->signIn($manager);
        $web->get('/ui/purchasing/orders/new');
        $before = [self::docs('PO'), (int) self::$db->value('SELECT COUNT(*) FROM sku')];
        $send = ['csrf' => $this->token($web), 'form_key' => str_repeat('a1', 16), 'supplier_id' => (string) $s['id'], 'item_name' => 'Grid fresh juice 10ml',
            'item_code' => 'GF-6', 'item_upp' => '6', 'item_price' => '4.50'];
        $r = $web->post('/ui/purchasing/orders/new-item', $send);
        self::assertSame(200, $r->status, self::why($r));
        $xp = new \DOMXPath($r->dom());
        $li = $xp->query('//*[@data-ni-fragment]//ul[@data-po-choices]/li')->item(0);
        self::assertInstanceOf(\DOMElement::class, $li, $r->body);
        $si = (int) $li->getAttribute('data-si');
        self::assertSame([(int) $s['id'], 6, 'GF-6', '4.50'], [(int) self::$db->value('SELECT supplier_id FROM supplier_item WHERE id = ?', [$si]), (int) $li->getAttribute('data-upp'),
            $li->getAttribute('data-their'), $li->getAttribute('data-price')]);
        self::assertSame('Grid fresh juice 10ml', $li->getAttribute('data-name'));
        self::assertNotSame('', (string) $xp->evaluate('string(//*[@data-ni-fragment]/@data-next-key)'), 'the next key for the panel');
        self::assertSame([$before[0], $before[1] + 1], [self::docs('PO'), (int) self::$db->value('SELECT COUNT(*) FROM sku')], 'the item, never an order');
        // Sent again (the same key and fields): the same answer, one item.
        $again = $web->post('/ui/purchasing/orders/new-item', $send);
        self::assertSame((string) $si, (string) (new \DOMXPath($again->dom()))->evaluate('string(//*[@data-ni-fragment]//li/@data-si)'));
        self::assertSame($before[1] + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        // A barcode on another item: refused in words, that item offered ("Use this one"), nothing made.
        $other = self::makeSku('Grid barcode owner');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES ('5012345099996', ?, 1)", [$other]);
        $r = $web->post('/ui/purchasing/orders/new-item', ['form_key' => str_repeat('b2', 16), 'item_name' => 'Grid other juice', 'item_barcode' => '5012345099996'] + $send);
        self::assertSame(200, $r->status, self::why($r));
        $xp = new \DOMXPath($r->dom());
        self::assertNotSame('', trim((string) $xp->evaluate('string(//*[@data-ni-error])')));
        self::assertSame((string) $other, (string) $xp->evaluate('string(//ul[@data-ni-others]/li/@data-sku)'));
        self::assertSame($before[1] + 2, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'nothing more made');
        // The invoice's route: the same, for the invoice desk's page.
        $r = $web->post('/ui/purchasing/invoices/new-item', ['form_key' => str_repeat('c3', 16), 'item_name' => 'Grid invoice juice', 'item_code' => '', 'item_upp' => '1', 'item_price' => ''] + $send);
        self::assertSame(200, $r->status, self::why($r));
        self::assertNotSame('', (string) (new \DOMXPath($r->dom()))->evaluate('string(//*[@data-ni-fragment]//li/@data-sku)'), 'no pack typed: the item alone');
        // Who may not add items: refused before anything; without the token: refused.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $r = $buyer->post('/ui/purchasing/orders/new-item', ['csrf' => $this->token($buyer), 'form_key' => str_repeat('d4', 16)] + $send);
        self::assertSame(403, $r->status, self::why($r));
        $r = $web->post('/ui/purchasing/orders/new-item', ['csrf' => 'wrong', 'form_key' => str_repeat('e5', 16)] + $send);
        self::assertSame(403, $r->status);
        self::assertSame($before[1] + 3, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
    }

    /** A saved line's item changed in place (KG22): the new line takes its place; the old line goes. */
    public function testASavedLinesItemChangedInPlaceTakesItsPlace(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier('Grid Swap Ltd');
        $a = self::makeSku('Swap first');
        $b = self::makeSku('Swap second');
        $c = self::makeSku('Swap third');
        $siA = $this->pack($buyer['id'], (int) $s['id'], $a, 1, 'SW-A', '1.00');
        $siB = $this->pack($buyer['id'], (int) $s['id'], $b, 1, 'SW-B', '2.00');
        $siC = $this->pack($buyer['id'], (int) $s['id'], $c, 1, 'SW-C', '3.00');
        $d = $this->pos()->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['supplier_item_id' => (int) $siA['id'], 'packs' => 1],
            ['supplier_item_id' => (int) $siB['id'], 'packs' => 2]]);
        $web = $this->signIn($buyer);
        // Refused (another new line names a switched-off pack): the changed line is drawn again in its place, as the new item with every
        // detail the script checks (KG32; the Codex review of 04421ad, findings 3 and 4); the saved line 1 hidden with its remove field.
        $off = $this->pack($buyer['id'], (int) $s['id'], self::makeSku('Swap off'), 1, 'SW-OFF', '1.00');
        self::$db->exec('UPDATE supplier_item SET is_active = 0 WHERE id = ?', [(int) $off['id']]);
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_1_remove' => '1', 'action' => 'save', DocGrid::FIELD => self::grid([
            ['si' => (int) $siC['id'], 'line' => 1, 'q' => '4', 'p' => '3.00'], ['si' => (int) $off['id'], 'q' => '1', 'p' => '1.00']])] + $f);
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-line="1"][@hidden]/following-sibling::tr[1][@data-kg-new][@data-kg-replace="1"]'
            . '[@data-si="' . $siC['id'] . '"][@data-name="Swap third"][@data-code][@data-pack][@data-their="SW-C"]'), self::why($r));
        self::assertSame(1, self::nodes($r, '//table[@data-kg-grid]/tbody/tr[@data-kg-new][not(@data-kg-replace)][@data-si="' . $off['id'] . '"][@data-name="Swap off"]'));
        self::assertSame([[$a, 1, 1, '1.0000'], [$b, 2, 1, '2.0000']], self::poItems($d->id), 'nothing saved');
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_1_remove' => '1', 'action' => 'save',
            DocGrid::FIELD => self::grid([['si' => (int) $siC['id'], 'line' => 1, 'q' => '4', 'p' => '3.00']])] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([[$c, 4, 1, '3.0000'], [$b, 2, 1, '2.0000']], self::poItems($d->id), 'the new item on line 1, where the old one was');
        // A removed line sent with its remove field alone (the script switches its boxes off: PHP's max_input_vars, Codex finding 10).
        $f = $web->get("/ui/purchasing/orders/{$d->id}")->form('/lines');
        unset($f['line_2_packs'], $f['line_2_price']);
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['line_2_remove' => '1', 'action' => 'save'] + $f);
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([[$c, 4, 1, '3.0000']], self::poItems($d->id));
        // The invoice: the same.
        $desk = $this->uiUser('purchasing_desk');
        $si = $this->invoicesService();
        $doc = $si->createDraft(Caller::staff($desk['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'SW-1']);
        $doc = $si->saveLines(Caller::staff($desk['id']), $doc->id, $doc->version, [$si->goodsLine((int) $s['id'], 1, '1.00', null, (int) $siA['id']),
            $si->goodsLine((int) $s['id'], 2, '2.00', null, (int) $siB['id'])]);
        $dw = $this->signIn($desk);
        $f = $dw->get("/ui/purchasing/invoices/{$doc->id}")->form("/ui/purchasing/invoices/{$doc->id}/lines", true);
        $r = $dw->post("/ui/purchasing/invoices/{$doc->id}/lines", ['x_2' => '1', 'action' => 'save', DocGrid::FIELD => self::grid([['si' => (int) $siC['id'], 'line' => 2, 'q' => '5', 'p' => '3.00']])] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame([[$a, 1, 1, '1.0000', 'SW-A'], [$c, 5, 1, '3.0000', 'SW-C']], self::invoiceLines($doc->id));
    }

    /**
     * The supplier invoice's posting time (KG27, KG32, KG33; the Codex reviews of 04421ad and 48dc75b): its date and its time, two fields of
     * the editor's own form. The main button with both saves the draft and sets the time in ONE request (pack J's rules); a time gone, half a
     * time (the date cleared, the time left) or one that is not one is refused under them, checked before anything is applied, and nothing
     * is saved or made final; only both empty make it final now; "Make final now" is post_now. Kept through a version conflict and "+ Add new
     * supplier". A new invoice has them too.
     */
    public function testThePostingTimeIsTheTimedRoute(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Grid Posting Ltd');
        $sku = $this->dryItem('Posting pod');
        $si = $this->invoicesService();
        $doc = $si->createDraft(Caller::staff($desk['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'POST-1']);
        $si->saveLines(Caller::staff($desk['id']), $doc->id, $doc->version, [$si->goodsLine((int) $s['id'], 1, '1.00', $sku)]);
        $web = $this->signIn($desk);
        $path = "/ui/purchasing/invoices/{$doc->id}";
        $page = $web->get($path);
        self::assertSame(200, $page->status, self::why($page));
        self::assertSame(1, self::nodes($page, '//form[@id="pi-form"]//fieldset[contains(@class, "kg-posting")]//input[@type="date"][@name="submit_date"][not(@form)]'
            . '[@aria-label="' . Words::DOC_GRID['posting_date'] . '"]/following-sibling::input[@type="time"][@name="submit_time"][@aria-label="' . Words::DOC_GRID['posting_time'] . '"]'),
            'two fields of the editor\'s own form');
        self::assertSame(1, self::nodes($page, '//div[contains(@class, "kg-head-bar")]/button[@value="post"][@data-kg-final][contains(@class, "primary")]'), 'make final: the main button');
        $status = static fn (): string => (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$doc->id]);
        $nothing = function (string $what) use ($status, $doc): void {
            self::assertSame(['draft', null, null], [$status(), self::$db->value('SELECT submit_at FROM supplier_invoice WHERE document_id = ?', [$doc->id]),
                self::$db->value('SELECT note FROM document WHERE id = ?', [$doc->id])], $what . ': nothing saved, nothing final');
        };
        $london = new \DateTimeZone('Europe/London');
        $tomorrow = (new \DateTimeImmutable('tomorrow', $london))->format('Y-m-d');
        // A time gone, with the main button: refused under the fields, what was typed kept.
        $form = $page->form($path . '/lines', true);
        $yesterday = (new \DateTimeImmutable('yesterday', $london))->format('Y-m-d');
        $r = $web->post($path . '/lines', ['action' => 'post', 'note' => 'typed with it', 'submit_date' => $yesterday, 'submit_time' => '10:00'] + $form);
        self::assertSame(422, $r->status, self::why($r));
        self::assertSame([Words::ARRIVAL_ERROR['bad_submit_at']], self::texts($r, '//fieldset[contains(@class, "kg-posting")]//p[@id="kg-posting-error"]'));
        self::assertSame(1, self::nodes($r, '//input[@name="submit_date"][@aria-invalid="true"][@aria-describedby="kg-posting-error"][@value="' . $yesterday . '"]'));
        self::assertSame(1, self::nodes($r, '//input[@name="submit_time"][@value="10:00"]'));
        $nothing('a time gone');
        // Half a time (the date cleared, the time left), or a date that is not one: refused, never made final now (finding 1).
        foreach ([['', '18:00', 'partial_submit_at'], [$tomorrow, '', 'partial_submit_at'], ['2026-02-30', '18:00', 'bad_submit_at'], [$tomorrow, '25:00', 'bad_submit_at']] as [$d, $t, $code]) {
            $r = $web->post($path . '/lines', ['action' => 'post', 'note' => 'typed with it', 'submit_date' => $d, 'submit_time' => $t] + $form);
            self::assertSame(422, $r->status, "{$d} {$t}: " . self::why($r));
            self::assertSame([Words::ARRIVAL_ERROR[$code]], self::texts($r, '//p[@id="kg-posting-error"]'), "{$d} {$t}");
            $nothing("{$d} {$t}");
        }
        // Checked before anything is applied (finding 5: no file stored for a refused time): a time gone is said before a line's refusal.
        $r = $web->post($path . '/lines', ['action' => 'post', 'p_1' => 'two', 'submit_date' => $yesterday, 'submit_time' => '10:00'] + $form);
        self::assertSame([Words::ARRIVAL_ERROR['bad_submit_at']], self::texts($r, '//p[@id="kg-posting-error"]'), 'the time first');
        // A version conflict keeps the date, the time and the clock typed (finding 2): the retry is never "make final now".
        $si->saveHeader(Caller::staff($desk['id']), $doc->id, (int) $form['version'], ['note' => 'changed in another tab']);
        $r = $web->post($path . '/lines', ['action' => 'post', 'submit_date' => $tomorrow, 'submit_time' => '18:00', 'submit_clock' => 'GMT'] + $form);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame([$tomorrow, '18:00'], [$r->form($path . '/lines', true)['submit_date'] ?? null, $r->form($path . '/lines', true)['submit_time'] ?? null]);
        self::assertSame('draft', $status());
        // A time still to come, with the main button: saved and timed in one request; the head says when its stock goes to the websites.
        $form = $web->get($path)->form($path . '/lines', true);
        $r = $web->post($path . '/lines', ['action' => 'post', 'note' => 'saved with the time', 'submit_date' => $tomorrow, 'submit_time' => '18:00'] + $form);
        self::assertSame($path . '?notice=timed#lines', $r->location(), self::why($r));
        self::assertSame(['draft', 'saved with the time'], [$status(), self::$db->value('SELECT note FROM document WHERE id = ?', [$doc->id])]);
        self::assertNotNull(self::$db->value('SELECT submit_at FROM supplier_invoice WHERE document_id = ?', [$doc->id]));
        $page = $web->follow($r);
        self::assertSame([$tomorrow, '18:00'], [$page->form($path . '/lines', true)['submit_date'], $page->form($path . '/lines', true)['submit_time']], 'its time shown');
        self::assertStringStartsWith((string) strstr(Words::DOC_GRID['goes_live'], '%s', true), implode(' ', self::texts($page, '//header[contains(@class, "page-head")]//span[contains(@class, "chip")]')));
        self::assertSame([Words::DOC_GRID['final_now'], Words::DOC_GRID['take_off']], self::texts($page, '//div[contains(@class, "kg-head-bar")]//details[contains(@class, "kg-head-more")]//button'));
        self::assertSame(['post_now'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//details[contains(@class, "kg-head-more")]//button[@form="pi-form"]'))), '"Make final now": the time left aside');
        // The timed route itself still works (pack J).
        $form = $page->form($path . '/schedule');
        $form['submit_at'] = $tomorrow . 'T19:00';
        self::assertSame(303, $web->post($path . '/schedule', $form)->status);

        // A new invoice: the posting fields from the start; Save and make final with both: the draft made and timed, never final.
        $new = $web->get('/ui/purchasing/invoices/new', ['supplier' => (string) $s['id']]);
        self::assertSame(1, self::nodes($new, '//form[@id="pi-form"]//fieldset[contains(@class, "kg-posting")][not(@hidden)]//input[@name="submit_date"]'));
        $form = $new->form('/ui/purchasing/invoices', true);
        $r = $web->post('/ui/purchasing/invoices', ['supplier_id' => (string) $s['id'], 'invoice_number' => 'POST-NEW', 'action' => 'post', 'submit_date' => $tomorrow,
            'submit_time' => '20:00', DocGrid::FIELD => self::grid([['sku' => $sku, 'q' => '2', 'p' => '1.00']])] + $form);
        $id = self::idOf($r->location(), '/ui/purchasing/invoices');
        self::assertStringContainsString('notice=timed', (string) $r->location(), self::why($r));
        self::assertSame(['draft', 1], [(string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]),
            (int) self::$db->value('SELECT COUNT(*) FROM supplier_invoice WHERE document_id = ? AND submit_at IS NOT NULL', [$id])]);
        // "+ Add new supplier" on a new invoice carries the posting time back (finding 3).
        $manager = $this->signIn($this->uiUser('purchasing_manager'));
        $form = $manager->get('/ui/purchasing/invoices/new')->form('/ui/purchasing/invoices', true);
        $r = $manager->post('/ui/purchasing/invoices', ['action' => 'new_supplier', 'new_supplier_name' => 'Posting Carry Ltd', 'submit_date' => $tomorrow, 'submit_time' => '21:00']
            + $form);
        self::assertSame(303, $r->status, self::why($r));
        $back = $manager->follow($r);
        self::assertSame([$tomorrow, '21:00'], [$back->form('/ui/purchasing/invoices', true)['submit_date'] ?? null, $back->form('/ui/purchasing/invoices', true)['submit_time'] ?? null]);
    }

    /** The site's keys (KG13-KG20): the panel on every page; Ctrl+B's new document where the person may make one; the lists' rows; the PDF. */
    public function testThePagesCarryWhatTheKeysUse(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier('Grid Keys Ltd');
        $d = $this->pos()->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($buyer['id']), $d->id, $d->version, [], [['sku_id' => self::makeSku('Keys pod'), 'packs' => 1]]);
        $this->pos()->approve(Caller::staff($buyer['id']), $d->id, (new Documents(self::$db, DocumentHandlers::all(self::$db)))->get($d->id)->version);
        $web = $this->signIn($buyer);
        $list = $web->get('/ui/purchasing/orders');
        self::assertSame(1, self::nodes($list, '//div[@data-kb-panel]//a[@data-key-new][@href="/ui/purchasing/orders/new"][@aria-keyshortcuts="Control+B"]'), 'a buyer: a new order');
        self::assertGreaterThan(0, self::nodes($list, '//table[@data-kb-rows]//a[contains(@class, "row-link")]'));
        self::assertSame(1, self::nodes($list, '//form[@class="toolbar"]//input[@id="tb-q"][@aria-keyshortcuts]'));
        foreach (array_keys(Words::KEYS_KBD) as $id) {
            if ($id !== 'new_doc') {
                self::assertStringContainsString(Words::KEYS[$id], $list->body, $id);
            }
        }
        self::assertSame(1, self::nodes($web->get("/ui/purchasing/orders/{$d->id}"), '//a[@data-key-print][contains(@href, "/pdf")]'), 'the saved order\'s PDF');
        // Who may not make an invoice: no new invoice under Ctrl+B.
        self::assertSame(0, self::nodes($web->get('/ui/purchasing/invoices'), '//a[@data-key-new]'));
        self::assertSame(0, self::nodes($web->get('/ui/'), '//a[@data-key-new]'), 'nothing elsewhere');
    }

    /**
     * The supplier of a draft order changed in its own box (the owner, 6 Nov 2026: "also in draft and new easily supplier change option";
     * KG30): in the same save, each line moves to the new supplier's pack of the same item (its price, unless one was typed: that stays,
     * per unit), an item it does not sell stays as a line of the item, said once; never a line dropped. A stale form or another buyer:
     * refused, nothing changed.
     */
    public function testADraftOrdersSupplierChangesWithItsLines(): void
    {
        $buyer = $this->uiUser('buyer');
        $bid = $buyer['id'];
        $a = $this->supplier('Grid Change From Ltd');
        $b = $this->supplier('Grid Change To Ltd');
        $x = self::makeSku('Change x pod');
        $y = self::makeSku('Change y pod');
        $z = self::makeSku('Change z pod');
        $siAx = $this->pack($bid, (int) $a['id'], $x, 1, 'AX', '1.00');
        $siAy = $this->pack($bid, (int) $a['id'], $y, 1, 'AY', '2.00');
        $siAz = $this->pack($bid, (int) $a['id'], $z, 1, 'AZ', '3.00');
        $siBx = $this->pack($bid, (int) $b['id'], $x, 1, 'BX', '1.50');
        $siBy = $this->pack($bid, (int) $b['id'], $y, 6, 'BY-6', '10.00');
        $d = $this->pos()->createDraft(Caller::staff($bid), (int) $a['id'], []);
        $d = $this->pos()->saveDraft(Caller::staff($bid), $d->id, $d->version, [], [['supplier_item_id' => (int) $siAx['id'], 'packs' => 2],
            ['supplier_item_id' => (int) $siAy['id'], 'packs' => 12, 'pack_price' => '1.80'], ['supplier_item_id' => (int) $siAz['id'], 'packs' => 1]]);
        $web = $this->signIn($buyer);
        $page = $web->get("/ui/purchasing/orders/{$d->id}");
        // The box on the draft as on a new order, with the page's question for a change with lines.
        self::assertSame(1, self::nodes($page, '//form[@id="po-form"]//select[@name="supplier_id"][@data-po-supplier][@data-change-ask="' . Words::DOC_GRID['change_ask'] . '"]'
            . '/option[@value="' . $a['id'] . '"][@selected]'));
        self::assertSame(0, self::nodes($page, '//p[contains(@class, "po-fixed")]'));
        $f = $page->form('/lines');
        $stale = $f;
        // Another buyer: refused, nothing changed.
        $other = $this->signIn($this->uiUser('buyer'));
        $r = $other->post("/ui/purchasing/orders/{$d->id}/lines", ['csrf' => $this->token($other), 'supplier_id' => (string) $b['id'], 'action' => 'save'] + $f);
        self::assertSame(403, $r->status, self::why($r));
        self::assertSame((int) $a['id'], (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        // The buyer's change, with a new line picked from the old supplier's packs before the box changed.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['supplier_id' => (string) $b['id'], 'action' => 'save', 'line_2_price' => '£1.80',
            DocGrid::FIELD => self::grid([['si' => (int) $siAx['id'], 'q' => '1', 'p' => '1.00']])] + $f); // £: read as the save reads it (Codex finding 8)
        self::assertSame("/ui/purchasing/orders/{$d->id}?notice=supplier_changed&unpriced=1#scan", $r->location(), self::why($r));
        self::assertSame((int) $b['id'], (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        self::assertSame([[(int) $siBx['id'], 2, '1.5000'], [(int) $siBy['id'], 2, '10.8000'], [0, 1, '3.0000'], [(int) $siBx['id'], 1, '1.5000']], self::poLines($d->id),
            'x at B\'s price; y in B\'s 6-pack, 12 units kept, the typed 1.80 a unit kept; z (B sells none) kept; the new line moved too');
        self::assertSame([$x, $y, $z, $x], array_column(self::poItems($d->id), 0), 'never a line dropped');
        self::assertNull(self::$db->value('SELECT supplier_code FROM po_line WHERE document_id = ? AND line_no = 3', [$d->id]), 'the old supplier\'s code gone');
        $ed = $web->follow($r);
        self::assertSame([Words::say('DOC_GRID', 'unpriced_one', 'Grid Change To Ltd')], self::texts($ed, '//p[contains(@class, "kg-unpriced")]'));
        self::assertSame(0, self::nodes($web->get("/ui/purchasing/orders/{$d->id}"), '//p[contains(@class, "kg-unpriced")]'), 'said once');
        // The form drawn before the change, sent now: refused as changed meanwhile; nothing changes.
        $r = $web->post("/ui/purchasing/orders/{$d->id}/lines", ['supplier_id' => (string) $a['id'], 'action' => 'save', 'form_key' => str_repeat('f7', 16)] + $stale);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame((int) $b['id'], (int) self::$db->value('SELECT supplier_id FROM purchase_order WHERE document_id = ?', [$d->id]));
        self::assertCount(4, self::poLines($d->id));
    }

    /**
     * The supplier of a draft invoice changed in its box (KG30): its lines move as an order's do and lose the old supplier's order links
     * ("From"); the due date follows the new terms while it is still the old terms' date; the invoice number is checked for the new
     * supplier; stale forms and other people refused.
     */
    public function testADraftInvoicesSupplierChangesWithItsLines(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $did = $desk['id'];
        $buyer = $this->uiUser('buyer');
        $a = $this->supplier('Grid Invoice From Ltd');
        $b = $this->supplier('Grid Invoice To Ltd');
        self::$db->exec('UPDATE supplier SET payment_terms_days = 30 WHERE id = ?', [(int) $a['id']]);
        self::$db->exec('UPDATE supplier SET payment_terms_days = 14 WHERE id = ?', [(int) $b['id']]);
        $x = $this->dryItem('Invoice change x');
        $z = $this->dryItem('Invoice change z');
        $siAx = $this->pack($buyer['id'], (int) $a['id'], $x, 1, 'IAX', '1.00');
        $this->pack($buyer['id'], (int) $a['id'], $z, 1, 'IAZ', '3.00');
        $this->pack($buyer['id'], (int) $b['id'], $x, 1, 'IBX', '1.25');
        // An order of the old supplier, its line on the invoice ("From" it).
        $po = $this->pos()->createDraft(Caller::staff($buyer['id']), (int) $a['id'], []);
        $po = $this->pos()->saveDraft(Caller::staff($buyer['id']), $po->id, $po->version, [], [['supplier_item_id' => (int) $siAx['id'], 'packs' => 2]]);
        $this->pos()->approve(Caller::staff($buyer['id']), $po->id, $po->version);
        $si = $this->invoicesService();
        $doc = $si->createDraft(Caller::staff($did), 'invoice', (int) $a['id'], ['invoice_number' => 'SC-1', 'invoice_date' => '2026-10-01']);
        self::assertSame('2026-10-31', (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$doc->id]));
        $doc = $si->addOrder(Caller::staff($did), $doc->id, $doc->version, $po->id);
        $doc = $si->appendLines(Caller::staff($did), $doc->id, $doc->version, [$si->goodsLine((int) $a['id'], 1, '5.00', $z)]);
        self::assertNotNull(self::$db->value('SELECT po_document_id FROM sinv_line WHERE document_id = ? AND line_no = 1', [$doc->id]));
        $web = $this->signIn($desk);
        $path = "/ui/purchasing/invoices/{$doc->id}";
        $page = $web->get($path);
        self::assertSame(1, self::nodes($page, '//form[@id="pi-form"]//select[@name="supplier_id"][@data-change-ask]/option[@value="' . $a['id'] . '"][@selected]'), self::why($page));
        self::assertSame(1, self::nodes($page, '//input[@name="due_date"][@data-auto="1"]'), 'the terms\' date: it follows another supplier');
        $f = $page->form($path . '/lines');
        // The number already on the new supplier's draft: refused in words, nothing changed.
        $si->createDraft(Caller::staff($did), 'invoice', (int) $b['id'], ['invoice_number' => 'SC-1']);
        $r = $web->post($path . '/lines', ['supplier_id' => (string) $b['id'], 'action' => 'save'] + $f);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame((int) $a['id'], (int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [$doc->id]));
        // Another number with it: moved.
        $r = $web->post($path . '/lines', ['supplier_id' => (string) $b['id'], 'invoice_number' => 'SC-2', 'action' => 'save', 'form_key' => str_repeat('c1', 16),
            'pp_1' => '£1.00', 'pp_2' => '£ 5.00'] + $f); // prices as people type them (Codex finding 8)
        self::assertSame($path . '?notice=supplier_changed&unpriced=1#lines', $r->location(), self::why($r));
        self::assertSame([(int) $b['id'], '2026-10-15'], [(int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [$doc->id]),
            (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$doc->id])], 'the due date: B\'s 14 days');
        self::assertSame([[$x, 2, 1, '1.2500', 'IBX'], [$z, 1, 1, '5.0000', null]], self::invoiceLines($doc->id), 'x at B\'s price; z kept as typed, without A\'s code');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sinv_line WHERE document_id = ? AND po_document_id IS NOT NULL', [$doc->id]), 'the old supplier\'s order: no longer');
        self::assertSame([Words::say('DOC_GRID', 'unpriced_one', 'Grid Invoice To Ltd')], self::texts($web->follow($r), '//p[contains(@class, "kg-unpriced")]'));
        // Stale: refused. A buyer (no invoices): refused.
        $r = $web->post($path . '/lines', ['supplier_id' => (string) $a['id'], 'action' => 'save', 'form_key' => str_repeat('c2', 16)] + $f);
        self::assertSame(409, $r->status, self::why($r));
        $bw = $this->signIn($buyer);
        $r = $bw->post($path . '/lines', ['csrf' => $this->token($bw), 'supplier_id' => (string) $a['id'], 'action' => 'save', 'form_key' => str_repeat('c3', 16)] + $f);
        self::assertSame(403, $r->status, self::why($r));
        self::assertSame((int) $b['id'], (int) self::$db->value('SELECT supplier_id FROM supplier_invoice WHERE document_id = ?', [$doc->id]));
    }
}
