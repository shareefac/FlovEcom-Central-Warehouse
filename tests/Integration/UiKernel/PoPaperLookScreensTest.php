<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Invoices\SupplierInvoices;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\DocGrid;
use CW\Ui\Words;

/**
 * One look for a purchase order (live drafts, step 2; docs/decisions.md LP1-LP8; the owner, 18 Nov 2026, of the read-only order page: "I
 * liked how it looks on Shareef's window, the design"), as cw_app through the real kernel:
 *  - a draft opened by anyone who may write orders (not only its starter) is the saved order's paper (purchase_order.php: the supplier,
 *    where it delivers, the dates, the lines, the note and the totals) with real boxes in place (class kg-flat): every data-kg-* of the
 *    grid's contract is there, so app.js's one engine runs on it; Received is not shown before the order is confirmed;
 *  - a person who may only look gets the same paper with words instead of boxes;
 *  - saving is unchanged: the whole form (no script), its version (409 keeps the new lines typed), Save and Confirm in the head;
 *  - a new order is the paper too; Cancel draft is under More;
 *  - the setting po.draft_look = form draws the old one-page form again (the way back for one release);
 *  - the supplier invoice's page is the same whatever the setting (the paper's CSS is scoped to .kg-paper).
 */
final class PoPaperLookScreensTest extends KernelUiTestCase
{
    protected function tearDown(): void
    {
        self::look('paper');
        parent::tearDown();
    }

    private static function look(string $value): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'po.draft_look'", [json_encode($value)]);
    }

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '4 Bridge Street', 'city' => 'Birmingham', 'postcode' => 'B1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    private function pos(): PurchaseOrders
    {
        return new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    private static function docVersion(int $id): int
    {
        return (new Documents(self::$db, DocumentHandlers::all(self::$db)))->get($id)->version;
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

    private static function why(UiResponse $r): string
    {
        return $r->describe() . "\n" . implode("\n", array_slice(self::$log, -3));
    }

    /**
     * A draft of three lines like the UNIVERSAL order the owner looked at (one of them a box of 10), started by $starter.
     *
     * @return array{id: int, supplier: array<string, mixed>, si: list<int>, skus: list<int>}
     */
    private function draft(int $starter, string $name): array
    {
        $s = $this->supplier($name);
        $skus = [];
        $sis = [];
        $lines = [];
        foreach ([['Spearmint Nic Salt E-Liquid 10ml - 10mg', 1, 'HPM-SPM-10', '0.92', 50], ['Prefilled Pods Strawberry Watermelon - 0mg', 10, 'HPMP-6K-SW', '29.10', 3],
            ['Eliquid 70/30 Juicy Peach 100ml', 1, 'HPM-JP-100', '2.80', 10]] as [$item, $upp, $code, $price, $packs]) {
            $sku = self::makeSku($name . ' ' . $item);
            $si = (new SupplierItems(self::$db))->create(Caller::staff($starter), (int) $s['id'], $sku, ['units_per_pack' => (string) $upp, 'supplier_code' => $code],
                ['pack_price' => $price]);
            $skus[] = $sku;
            $sis[] = (int) $si['id'];
            $lines[] = ['supplier_item_id' => (int) $si['id'], 'packs' => $packs];
        }
        $d = $this->pos()->createDraft(Caller::staff($starter), (int) $s['id'], []);
        $this->pos()->saveDraft(Caller::staff($starter), $d->id, $d->version, [], $lines);
        return ['id' => $d->id, 'supplier' => $s, 'si' => $sis, 'skus' => $skus];
    }

    public function testAnotherBuyerOpensTheDraftAsThePaperWithItsBoxesInPlace(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Paper One Ltd');
        $path = "/ui/purchasing/orders/{$d['id']}";
        $shareef = $this->signIn($this->uiUser('buyer')); // not the starter: anyone who may write orders (DA1)
        $page = $shareef->get($path);
        self::assertSame(200, $page->status, self::why($page));

        // The paper (LP1): the saved order's page, inside the one editor form.
        $form = '//form[@id="po-form"][contains(concat(" ", @class, " "), " kg-paper ")][starts-with(@data-kg-editor, "po:")][@method="post"][contains(@action, "/lines")]';
        self::assertSame(1, self::nodes($page, $form . '/article[contains(@class, "doc-view")][contains(@class, "po-paper")]'), 'the paper inside the form');
        self::assertSame(0, self::nodes($page, '//section[contains(@class, "kg-details")] | //table[contains(@class, "kg-taxes")] | //details[contains(@class, "kg-more")]'),
            'not the grey form');
        $head = $form . '//div[contains(@class, "po-paper-head")][@data-kg-swap-part="header"]';
        self::assertSame(1, self::nodes($page, $head . '/section[contains(@class, "doc-party")]//select[@name="supplier_id"][@data-po-supplier]'), 'the supplier box in the supplier block');
        self::assertSame(1, self::nodes($page, $head . '/section[contains(@class, "po-deliver")]'), 'where it delivers');
        self::assertSame(1, self::nodes($page, $head . '//dl[contains(@class, "po-dates")]//input[@name="expected_date"][@type="date"][contains(@class, "kg-flat")]'));
        self::assertSame(1, self::nodes($page, $head . '//dl[contains(@class, "po-dates")]//input[@name="external_ref"][contains(@class, "kg-flat")]'));
        self::assertSame([Words::ORDER['order_date'], Words::ORDER['expected_short'], Words::ORDER['terms'], Words::ORDER['their_ref']],
            self::texts($page, $head . '//dl[contains(@class, "po-dates")]/dt'), 'the paper\'s facts, in its order');
        self::assertSame(1, self::nodes($page, $head . '//dd[@data-kg-swap-part="terms"]'), 'the payment terms follow a supplier changed on Save');
        self::assertSame(1, self::nodes($page, $form . '//div[contains(@class, "doc-foot")][@data-kg-swap-part="summary"]//input[@name="note"][contains(@class, "kg-flat")]'),
            'the note to the supplier at the foot');

        // The lines: the paper's columns (no Received before it is confirmed) and the grid's whole contract (docs/dev.md, KG).
        $grid = $form . '//table[@data-kg-grid]';
        self::assertSame([Words::DOC_GRID['no'], Words::ORDER['col_item'], Words::DOC_GRID['stock'], Words::ORDER['col_ordered'], Words::ORDER['col_cost'],
            Words::ORDER['col_line_total'], Words::PO_ONE['remove_col']], self::texts($page, $grid . '/thead/tr/th'));
        foreach (['data-kg-max', 'data-kg-max-qty', 'data-kg-min-qty', 'data-kg-remove', 'data-kg-vat', 'data-kg-rates', 'data-kg-removed', 'data-kg-too-many', 'data-kg-saving',
            'data-kg-saved', 'data-kg-save-failed', 'data-kg-v-qty', 'data-kg-v-price', 'data-kg-coming'] as $a) {
            self::assertSame(1, self::nodes($page, $grid . '[@' . $a . ']'), $a);
        }
        self::assertSame('line_%d_remove', (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $grid . '/@data-kg-remove)'));
        $row = $grid . '/tbody/tr[@data-kg-row][@data-kg-line="2"]';
        foreach (['data-si', 'data-sku', 'data-u', 'data-name', 'data-code', 'data-pack', 'data-their', 'data-stock', 'data-coming', 'data-kg-upp', 'data-kg-vat',
            'data-kg-rate', 'data-kg-e2'] as $a) {
            self::assertSame(1, self::nodes($page, $row . '[@' . $a . ']'), 'a saved line: ' . $a);
        }
        self::assertSame(['2'], self::texts($page, $row . '/td[@data-kg-no]'));
        self::assertSame(1, self::nodes($page, $row . '//input[@name="line_2_packs"][@data-kg-cell="qty"][contains(@class, "kg-flat")][@aria-label="'
            . Words::say('ORDER', 'line', 2) . ': ' . Words::ORDER['packs'] . '"]'), 'a real box, labelled "Line 2: Packs"');
        self::assertSame(1, self::nodes($page, $row . '//input[@name="line_2_price"][@data-kg-cell="price"][contains(@class, "kg-flat")]'));
        self::assertSame(1, self::nodes($page, $row . '//*[@data-kg-amount]'));
        self::assertSame(1, self::nodes($page, $row . '//button[@type="submit"][@name="remove_line"][@value="2"][@data-kg-x]'), 'the x: a submit without the script');
        // The pack figures as the paper says them: "3 × box of 10 = 30" under the packs (app.js works it out again as typed), "per box of 10".
        $pack = Words::say('SUPPLIER_ITEMS', 'pack_of', 'each', 10);
        self::assertSame([Words::say('ORDER', 'packs_units', '3', $pack, '30')], self::texts($page, $row . '//*[@data-kg-units]'));
        self::assertSame(Words::ORDER['packs_units'], (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $row . '//*[@data-kg-units]/@data-words)'));
        self::assertSame([Words::say('ORDER', 'per', $pack)], self::texts($page, $row . '/td//*[@data-kg-per][@data-words="' . Words::ORDER['per'] . '"]'),
            'the pack\'s price says its pack, again as the item changes (Codex round 1)');
        self::assertSame(1, self::nodes($page, $form . '//template[@data-kg-row]/tr//*[@data-kg-per]'), 'a new line\'s too');
        // Their code and the pack in the item's line (the paper has no columns for them); a line of single items says nothing more under its packs.
        self::assertSame(['HPMP-6K-SW'], self::texts($page, $row . '/th//*[@data-kg-their]'));
        self::assertSame([$pack], self::texts($page, $row . '/th//*[@data-kg-pack]'));
        self::assertSame([Words::say('ORDER', 'vat_code', 'S')], self::texts($page, $row . '/th//*[@data-kg-vat-code][@data-words="' . Words::ORDER['vat_code'] . '"]'),
            'the VAT words app.js says again when the item changes (Codex round 2)');
        // The item's cell (LP9; the coordinator's look on staging): the name on its own line with its whole name as its title, then ONE sub-line
        // "CW-… · their code · pack · VAT S".
        $item = self::texts($page, $row . '/th/a[contains(@class, "kg-name")]');
        self::assertSame(1, count($item));
        self::assertSame($item[0], (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $row . '/th/a[contains(@class, "kg-name")]/@title)'));
        self::assertSame([sprintf('CW-%06d', $d['skus'][1]), 'HPMP-6K-SW', $pack, Words::say('ORDER', 'vat_code', 'S')],
            self::texts($page, $row . '/th/span[contains(@class, "kg-sub")]/span'), 'one sub-line, the code first');
        self::assertSame(0, self::nodes($page, $row . '/th/span[contains(@class, "kg-code")]'), 'never beside the name');
        // The empty boxes are real boxes; their "—" only a placeholder, never a value (nothing is saved for them).
        foreach (['external_ref', 'note', 'charge_amount'] as $name) {
            self::assertSame(1, self::nodes($page, $form . '//input[@name="' . $name . '"][@value=""][@placeholder="' . Words::DOC_LIST['none'] . '"][contains(@class, "kg-flat")][not(@readonly)][not(@disabled)]'), $name);
        }
        self::assertSame([''], self::texts($page, $grid . '/tbody/tr[@data-kg-line="1"]//*[@data-kg-units]'));
        // The empty line last (the product box), the new line's template, the one field for new lines, the tools, the keys and Undo.
        self::assertSame(1, self::nodes($page, $grid . '/tbody/tr[last()][@data-kg-empty]//input[@name="q"][@data-po-pick]'));
        $tpl = $form . '//template[@data-kg-row]/tr[@data-kg-row][@data-kg-new]';
        foreach (['/td[@data-kg-no]', '//*[@data-kg-name]', '//*[@data-kg-code]', '//*[@data-kg-their]', '//*[@data-kg-pack]', '//*[@data-kg-stock]', '//input[@data-kg-cell="qty"][contains(@class, "kg-flat")]',
            '//input[@data-kg-cell="price"][contains(@class, "kg-flat")]', '//*[@data-kg-amount]', '//button[@data-kg-x]', '//*[@data-kg-units]'] as $part) {
            self::assertSame(1, self::nodes($page, $tpl . $part), 'the template: ' . $part);
        }
        // The coordinator's walkthrough (LP11): an empty price box shows "0.00", an empty packs box "0", so staff see where to type; a new line
        // of an item not set up with the supplier shows its items per pack at once (the template's row: hidden until such an item is chosen,
        // out of the keys' boxes while hidden).
        self::assertSame(1, self::nodes($page, $row . '//input[@data-kg-cell="price"][@placeholder="0.00"]'));
        self::assertSame(1, self::nodes($page, $row . '//input[@data-kg-cell="qty"][@placeholder="0"]'));
        self::assertSame(1, self::nodes($page, $tpl . '//input[@data-kg-cell="price"][@placeholder="0.00"]'));
        self::assertSame(1, self::nodes($page, $tpl . '//label[@data-kg-upp-line][@hidden]/input[@type="number"][not(@data-kg-cell)][not(@name)]'));
        self::assertStringContainsString('%s', (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $tpl . '/@data-kg-pack-of)'));
        self::assertSame(1, self::nodes($page, $form . '/input[@type="hidden"][@name="' . DocGrid::FIELD . '"][@data-kg-field]'));
        self::assertSame(1, self::nodes($page, $form . '/div[@data-kg-look][@hidden]'));
        foreach (['data-kg-add-row', 'data-kg-several-open', 'data-kg-delete'] as $tool) {
            self::assertSame(1, self::nodes($page, $form . '//div[contains(@class, "kg-tools")]//button[@' . $tool . '][@hidden]'), $tool);
        }
        // "+ New item" for who may add an item (items.create), else the line naming whom to ask (as on the old form).
        self::assertSame(1, self::nodes($page, $form . '//div[contains(@class, "kg-tools")]//button[@data-kg-new-item][@hidden]')
            + self::nodes($page, $form . '//section[@id="scan"]/p[contains(@class, "po-ask")]'), '+ New item, or whom to ask');
        self::assertSame(1, self::nodes($page, $form . '//p[contains(@class, "kg-keys")]'), 'the key hints');
        self::assertSame(1, self::nodes($page, $form . '//*[@aria-live="polite"]/*[@data-kg-undo-line][@hidden]'));
        self::assertSame(1, self::nodes($page, $form . '//section[@id="scan"]//table[@data-kg-grid]'), 'the save comes back to the lines (#scan)');
        self::assertSame(1, self::nodes($page, $form . '//*[@data-kg-swap-part="notes"]'));
        // The totals: worked out again as typed, the server's on Save; a VAT code a row; Delivery £ typed in place.
        $totals = $form . '//div[@data-kg-swap-part="summary"]//section[contains(@class, "doc-totals")]/dl[@data-kg-vat-rows][@data-words="' . Words::ORDER['vat_line'] . '"]';
        foreach (['units', 'count', 'net', 'vat', 'gross'] as $key) {
            self::assertSame(1, self::nodes($page, $totals . '//*[@data-kg-total="' . $key . '"]'), 'total ' . $key);
        }
        self::assertSame(1, self::nodes($page, $totals . '/div[@data-kg-vat-row]/dt'), 'one VAT code, one row');
        self::assertSame(1, self::nodes($page, $totals . '/dd/input[@name="charge_amount"][@data-kg-delivery][contains(@class, "kg-flat")]'));
        self::assertSame(1, self::nodes($page, $totals . '/dt[@data-kg-vat-end]'), 'the VAT rows are drawn before Delivery');
        self::assertSame([PoMath::money(PoMath::e2('46.00') + PoMath::e2('87.30') + PoMath::e2('28.00'))], self::texts($page, $totals . '//*[@data-kg-total="net"]'));

        // The head (LP3): the title, the Draft chip and the line under it as the read-only page draws them; the bar of the saved order's page
        // with the editor's Save and Confirm (for the form), Print, Keys and More (Cancel draft under it).
        self::assertStringContainsString(Words::say('ORDER', 'title_draft', 'Paper One Ltd'), $page->text());
        $bar = '//div[contains(@class, "doc-actions")][contains(@class, "po-edit-bar")]';
        self::assertSame(['add', 'save', 'approve'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query($bar . '/button[@type="submit"][@form="po-form"]'))), 'the unseen Add first (Enter is Add), then Save and Confirm');
        self::assertSame(1, self::nodes($page, $bar . '/button[@value="approve"][contains(concat(" ", @class, " "), " primary ")]'), 'Confirm: the main button');
        self::assertSame(1, self::nodes($page, $bar . '/button[@value="save"][@aria-keyshortcuts="Control+S"]'));
        self::assertSame(1, self::nodes($page, $bar . '/a[@data-leave][@href="/ui/purchasing/orders"]'), 'Leave without saving');
        self::assertSame(1, self::nodes($page, $bar . '/button[@data-print][@hidden]'), 'Print');
        self::assertSame(1, self::nodes($page, $bar . '/button[@data-kb-open][@data-kg-jsonly]'), 'Keys (?)');
        $more = $bar . '/details[contains(@class, "more")]//div[contains(@class, "more-menu")]';
        self::assertSame(1, self::nodes($page, $more . '//form[@action="' . $path . '/cancel"]//select[@name="reason_code"][@required]'), 'Cancel draft under More');
        self::assertSame(1, self::nodes($page, $more . '//a[@href="' . $path . '/pdf"]'), 'the draft PDF');
        self::assertSame(1, self::nodes($page, $more . '//form[@action="' . $path . '/copy"][@data-leaves]'), 'Copy: it leaves the typed page, the guard asks first (Codex round 1)');
        foreach (['lines.xlsx', 'lines.csv'] as $file) {
            self::assertSame(1, self::nodes($page, $more . '//a[@href="' . $path . '/' . $file . '"][@download]'), $file . ': a download, never leaving the typed page');
        }
        self::assertSame(0, self::nodes($page, $bar . '//form[@action="' . $path . '/approve"]'), 'Confirm sends the typed form, not the version alone');
        // Related and History stay under the paper.
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "po-related")]'));
        self::assertSame(1, self::nodes($page, '//section[contains(@class, "po-history")]'));
        // No inline style anywhere (the CSP): the look is app.css's alone.
        self::assertSame(0, self::nodes($page, '//main//*[@style]'));
    }

    public function testAPersonWhoMayOnlyLookGetsThePaperWithWordsInsteadOfBoxes(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Paper Look Ltd');
        $path = "/ui/purchasing/orders/{$d['id']}";
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $desk->get($path);
        self::assertSame(200, $page->status, self::why($page));
        self::assertFalse($page->hasForm($path . '/lines'));
        self::assertSame(1, self::nodes($page, '//article[contains(@class, "po-paper")]'));
        self::assertSame(0, self::nodes($page, '//main//input[contains(@class, "kg-flat")] | //main//*[@data-kg-grid] | //main//*[@data-kg-editor]'), 'words, no boxes');
        self::assertSame([Words::DOC_GRID['no'], Words::ORDER['col_item'], Words::DOC_GRID['stock'], Words::ORDER['col_ordered'], Words::ORDER['col_cost'],
            Words::ORDER['col_line_total']], self::texts($page, '//table[contains(@class, "doc-lines")]/thead/tr/th'), 'no Received before it is confirmed');
        self::assertSame(3, self::nodes($page, '//table[contains(@class, "doc-lines")]/tbody/tr'));
    }

    public function testTheWholeFormSavesWithoutTheScriptAndConfirmTakesWhatIsTyped(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Paper Save Ltd');
        $path = "/ui/purchasing/orders/{$d['id']}";
        $shareef = $this->signIn($this->uiUser('buyer'));
        $f = $shareef->get($path)->form('/lines');
        foreach (['csrf', 'version', 'line_count', 'lines_editable', 'form_key', DocGrid::FIELD, 'supplier_id', 'expected_date', 'external_ref', 'note', 'q', 'pick_si',
            'pick_sku', 'packs', 'charge_amount', 'line_1_packs', 'line_1_price', 'line_2_packs', 'line_2_price', 'line_3_packs', 'line_3_price'] as $field) {
            self::assertArrayHasKey($field, $f, $field);
        }
        $r = $shareef->post("{$path}/lines", ['line_2_packs' => '5', 'line_3_price' => '2.75', 'expected_date' => '2026-12-01', 'external_ref' => 'Q-77',
            'note' => 'Deliver before noon', 'charge_amount' => '7.50', 'action' => 'save'] + $f);
        self::assertSame("{$path}?notice=saved#scan", $r->location(), self::why($r));
        self::assertSame([[1, 50, '0.9200'], [2, 5, '29.1000'], [3, 10, '2.7500'], [4, 1, '7.5000']], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['packs'],
            (string) $l['pack_price']], self::$db->all('SELECT line_no, packs, pack_price FROM po_line WHERE document_id = ? ORDER BY line_no', [$d['id']])), 'the delivery: a charge line');
        self::assertSame(['Q-77', 'Deliver before noon'], array_values(array_map('strval', (array) self::$db->one('SELECT external_ref, note FROM document WHERE id = ?', [$d['id']]))));
        // Saved again as drawn with the reference and the note emptied: nothing stored for them, never the "—" the empty boxes show.
        $r = $shareef->post("{$path}/lines", ['external_ref' => '', 'note' => '', 'action' => 'save'] + $shareef->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame(['', ''], array_values(array_map('strval', (array) self::$db->one('SELECT external_ref, note FROM document WHERE id = ?', [$d['id']]))));
        self::assertSame(1, self::nodes($shareef->get($path), '//input[@name="external_ref"][@value=""][@placeholder="' . Words::DOC_LIST['none'] . '"]'));
        self::assertSame('2026-12-01', (string) self::$db->value('SELECT expected_date FROM purchase_order WHERE document_id = ?', [$d['id']]));
        // ONE history on the order's page (the coordinator's second look): the History card names who started it and each person who changed
        // it (DA2's changes), with no second "History of changes" list.
        $page = $shareef->get($path);
        $events = self::texts($page, '//section[contains(@class, "po-history")]//ol[contains(@class, "po-events")]/li');
        self::assertStringContainsString(Words::say('ORDER', 'ev_started', (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$fazil['id']])), implode(' | ', $events));
        $shareefName = (string) self::$db->value("SELECT u.display_name FROM staff_user u JOIN audit_log a ON a.staff_user_id = u.id WHERE a.entity_type = 'document' AND a.entity_id = ? "
            . 'ORDER BY a.id DESC LIMIT 1', [(string) $d['id']]);
        self::assertStringContainsString(Words::say('ORDER', 'ev_changed', $shareefName), $events[0], 'newest first: the change just saved');
        self::assertSame(0, self::nodes($page, '//section[contains(@class, "doc-changes")]'), 'no second history list');

        // A product added without the script: the product box, its packs and Add (Enter).
        $page = $shareef->get($path);
        $f = $page->form('/lines');
        self::assertSame(1, self::nodes($page, '//table[@data-kg-grid]/tbody/tr[@data-kg-line="4"][@data-kg-charge]//input[@name="line_4_price"][contains(@class, "kg-flat")]'),
            'the charge line in the paper');
        $r = $shareef->post("{$path}/lines", ['q' => 'HPM-JP-100', 'packs' => '2', 'action' => 'add'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame(12, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND supplier_item_id = ?', [$d['id'], $d['si'][2]]), 'the same product: packs added');

        // Confirm from the paper sends the typed form: saved and confirmed in one go.
        $f = $shareef->get($path)->form('/lines');
        $r = $shareef->post("{$path}/lines", ['line_1_packs' => '40', 'action' => 'approve'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame('posted', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$d['id']]));
        self::assertSame(40, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$d['id']]));
        // The confirmed order: the read-only paper with its Received column.
        $view = $shareef->get($path);
        self::assertContains(Words::ORDER['col_received'], self::texts($view, '//table[contains(@class, "doc-lines")]/thead/tr/th'));
        // The same ONE sub-line under the name as on the draft (the coordinator's third look: "CW-007523  each · VAT S" had no dot after the code).
        $sub = '//table[contains(@class, "doc-lines")]/tbody/tr[2]/th/span[contains(@class, "kg-sub")]/span';
        self::assertSame([sprintf('CW-%06d', $d['skus'][1]), 'HPMP-6K-SW', \CW\Ui\Controller\PurchaseOrdersController::pack('each', 10), Words::say('ORDER', 'vat_code', 'S')],
            self::texts($view, $sub));
        self::assertSame(0, self::nodes($view, '//table[contains(@class, "doc-lines")]/tbody/tr/th/span[contains(@class, "kg-code")]'), 'never beside the name');
        self::assertSame(0, self::nodes($view, '//main//input[contains(@class, "kg-flat")]'));
        // Confirmed, not sent (the coordinator's walkthrough, LP11): "I have sent it…" is the head's main button, a link to its form, which is a
        // card under the head (it opened in the head's bar and pushed the title down).
        self::assertSame([Words::ORDER['send']], self::texts($view, '//div[contains(@class, "doc-head")]//div[contains(@class, "doc-actions")]/a[contains(concat(" ", @class, " "), " primary ")][@href="#po-send"]'));
        self::assertSame(0, self::nodes($view, '//div[contains(@class, "doc-head")]//form[contains(@action, "/send")]'), 'no form in the head');
        self::assertSame(1, self::nodes($view, '//section[@id="po-send"][preceding-sibling::div[contains(@class, "doc-head")]]//form[contains(@action, "/send")]//select[@name="via"]'));
    }

    public function testAPageDrawnBeforeSomeoneElsesSaveIsRefusedWithTheNewLinesKept(): void
    {
        $fazilUser = $this->uiUser('buyer');
        $d = $this->draft($fazilUser['id'], 'Paper Clash Ltd');
        $path = "/ui/purchasing/orders/{$d['id']}";
        $shareef = $this->signIn($this->uiUser('buyer'));
        $stale = $shareef->get($path)->form('/lines');
        $fazil = $this->signIn($fazilUser);
        $r = $fazil->post("{$path}/lines", ['line_1_packs' => '60', 'action' => 'save'] + $fazil->get($path)->form('/lines'));
        self::assertSame(303, $r->status, self::why($r));
        $new = json_encode([['sku' => $d['skus'][0], 'q' => '4', 'p' => '1.00']], JSON_THROW_ON_ERROR);
        $r = $shareef->post("{$path}/lines", ['line_2_packs' => '9', 'action' => 'save', DocGrid::FIELD => $new] + $stale);
        self::assertSame(409, $r->status, self::why($r));
        self::assertSame(1, self::nodes($r, '//form[@id="po-form"][contains(@class, "kg-paper")][@data-kg-conflict]'), 'the paper drawn again, marked for the script');
        self::assertStringContainsString(' changed this draft at ', $r->text());
        $xp = new \DOMXPath($r->dom());
        self::assertSame(1, $xp->query('//table[@data-kg-grid]/tbody/tr[@data-kg-new][@data-sku="' . $d['skus'][0] . '"]//input[@data-kg-cell="qty"][@value="4"][contains(@class, "kg-flat")]')->length,
            'the typed new line kept, in the paper\'s line');
        self::assertSame($new, $r->form('/lines')[DocGrid::FIELD]);
        self::assertSame((string) self::docVersion($d['id']), $r->form('/lines')['version'], 'drawn with the version now');
        self::assertSame(60, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$d['id']]), 'nothing overwritten');
        self::assertSame(3, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 2', [$d['id']]));
    }

    public function testANewOrderIsThePaperTooAndCancelDraftIsUnderMore(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier('Paper New Ltd');
        $web = $this->signIn($buyer);
        $page = $web->get('/ui/purchasing/orders/new', ['supplier' => $s['id']]);
        self::assertSame(200, $page->status, self::why($page));
        $form = '//form[@id="po-form"][@action="/ui/purchasing/orders"][@data-kg-editor="po:new"][contains(@class, "kg-paper")]';
        self::assertSame(1, self::nodes($page, $form . '/article[contains(@class, "po-paper")]//div[contains(@class, "po-paper-head")]//select[@name="supplier_id"]/option[@selected][@value="'
            . $s['id'] . '"]'));
        self::assertSame(1, self::nodes($page, $form . '//table[@data-kg-grid]/tbody/tr[@data-kg-empty]//input[@name="q"][@data-po-pick]'));
        $bar = '//div[contains(@class, "po-edit-bar")]';
        self::assertSame(['add', 'save', 'approve'], array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query($bar . '/button[@type="submit"][@form="po-form"]'))));
        self::assertSame(0, self::nodes($page, $bar . '//details | ' . $bar . '//button[@data-print]'), 'nothing saved yet: no More, no Print');
        self::assertSame(0, self::nodes($page, '//section[contains(@class, "po-related")]'));
        $f = $page->form('/ui/purchasing/orders');
        $r = $web->post('/ui/purchasing/orders', ['action' => 'save', 'external_ref' => 'NEW-1'] + $f);
        self::assertSame(303, $r->status, self::why($r));
        self::assertSame(1, preg_match('#^/ui/purchasing/orders/(\d+)#', (string) $r->location(), $m));
        $id = (int) $m[1];

        // Cancel draft: under More, its reason asked for; without one it is refused and the fold opens again.
        $page = $web->get("/ui/purchasing/orders/{$id}");
        $cancel = $page->form("/ui/purchasing/orders/{$id}/cancel");
        self::assertArrayHasKey('version', $cancel);
        $bad = $web->post("/ui/purchasing/orders/{$id}/cancel", ['reason_code' => ''] + $cancel);
        self::assertSame(400, $bad->status, self::why($bad));
        self::assertSame(1, self::nodes($bad, $bar . '/details[contains(@class, "more")][@open]//details[contains(@class, "doc-act")][@open]//select[@name="reason_code"][@aria-invalid="true"]'));
        self::assertSame(1, self::nodes($bad, '//form[@id="po-form"][contains(@class, "kg-paper")]'), 'still the paper');
        $ok = $web->post("/ui/purchasing/orders/{$id}/cancel", ['reason_code' => 'not_needed'] + $cancel);
        self::assertSame(303, $ok->status, self::why($ok));
        self::assertSame('cancelled', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
    }

    public function testTheSettingFormDrawsTheOldOnePageFormAgain(): void
    {
        $fazil = $this->uiUser('buyer');
        $d = $this->draft($fazil['id'], 'Paper Back Ltd');
        $path = "/ui/purchasing/orders/{$d['id']}";
        $web = $this->signIn($this->uiUser('buyer'));
        self::look('form');
        foreach ([$path, '/ui/purchasing/orders/new'] as $p) {
            $page = $web->get($p);
            self::assertSame(200, $page->status, self::why($page));
            self::assertSame(0, self::nodes($page, '//*[contains(@class, "kg-paper")] | //input[contains(@class, "kg-flat")]'), $p);
            self::assertSame(1, self::nodes($page, '//form[@id="po-form"][contains(@class, "po-editor")]//section[contains(@class, "kg-details")]'), $p . ': the old form');
            self::assertSame(1, self::nodes($page, '//div[contains(@class, "kg-head-bar")]/button[@value="save"][@form="po-form"]'), $p);
        }
        // It saves as before.
        $r = $web->post("{$path}/lines", ['line_1_packs' => '7', 'action' => 'save'] + $web->get($path)->form('/lines'));
        self::assertSame("{$path}?notice=saved#scan", $r->location(), self::why($r));
        self::look('paper');
        self::assertSame(1, self::nodes($web->get($path), '//form[@id="po-form"][contains(@class, "kg-paper")]'));
        // Only its two values: anything else is refused on the Settings page's own rule.
        self::assertSame(['in' => ['paper', 'form']], \CW\Settings::RULES['po.draft_look']);
        self::assertArrayHasKey('po.draft_look', Words::SETTING);
        self::assertArrayHasKey('po.draft_look', Words::SETTING_HELP);
    }

    public function testTheSupplierInvoicesPageIsTheSameWhateverTheLook(): void
    {
        $desk = $this->uiUser('purchasing_desk');
        $s = $this->supplier('Paper Invoice Ltd');
        $inv = new SupplierInvoices(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $doc = $inv->createDraft(Caller::staff($desk['id']), 'invoice', (int) $s['id'], ['invoice_number' => 'PI-1']);
        $web = $this->signIn($desk);
        // The tokens a page draws fresh each time (its forms' keys) set aside; everything else byte for byte.
        $same = static fn (string $html): string => (string) preg_replace('/(name="(?:csrf|form_key|new_item_key)" value=")[0-9a-f]+"/', '$1"', $html);
        foreach (["/ui/purchasing/invoices/{$doc->id}", '/ui/purchasing/invoices/new'] as $p) {
            $paper = $web->get($p);
            self::assertSame(200, $paper->status, self::why($paper));
            self::look('form');
            $form = $web->get($p);
            self::look('paper');
            self::assertSame($same($form->body), $same($paper->body), $p);
            self::assertStringNotContainsString('kg-paper', $paper->body, $p);
            self::assertStringNotContainsString('kg-flat', $paper->body, $p);
        }
    }
}
