<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\StockOps\StockOps;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Items and Inventory pages on the approved redesign of 30 Oct 2026 (artboards Items, Item, Inventory: Adjustments, Inventory: Stock
 * counts; docs/decisions.md RD-A1+), as the pages are served: every list is the title with More and ONE main button at its right, ONE search
 * and ONE Show choice (a menu of links that works without the script), the artboard's groups and 3-5 columns, no figures strip and no "+ Add"
 * row; the item page is one page in sections with no tab row, everything the tabs held still on it. Layout only: every form posts where and
 * what it did.
 */
final class InventoryRedesignScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /** @return list<string> the texts of the nodes $path finds, spaces squashed */
    private static function texts(UiResponse $page, string $path): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)),
            array_values(iterator_to_array(self::xp($page)->query($path) ?: [])));
    }

    /** @return list<string> the attribute $attr of the nodes $path finds */
    private static function attrs(UiResponse $page, string $path, string $attr): array
    {
        return array_map(static fn (\DOMElement $n): string => $n->getAttribute($attr), array_values(iterator_to_array(self::xp($page)->query($path) ?: [])));
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    /**
     * The simple list (the owner, 30 Oct 2026: "all list make one more simple design"): the title, ONE search at most, ONE Show choice as a menu of
     * links (the shown one no link), no Show row of selects, no Filter pop-over, no figures strip, no "+ Add" row, 3-5 columns in every table, at
     * most ONE main button in the head. @return list<array{0: string, 1: bool}> the Show choices (label, current)
     */
    private static function assertSimpleList(UiResponse $page, string $what, string $title): array
    {
        self::assertSame(200, $page->status, $what . ': ' . $page->describe());
        $xp = self::xp($page);
        self::assertSame([$title], self::texts($page, '//main//header[contains(@class, "page-head")]//h1'), "{$what}: the title");
        self::assertLessThanOrEqual(1, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$what}: one search");
        self::assertSame(1, $xp->query('//main//*[contains(@class, "toolbar")]/details[contains(@class, "show-menu")]')->length, "{$what}: ONE Show choice");
        self::assertSame(0, $xp->query('//main//div[@class="tb-state"] | //main//details[contains(@class, "tb-pop")]')->length, "{$what}: no Show row, no Filter pop-over");
        self::assertSame(0, $xp->query('//main//section[contains(@class, "rp-figs")]')->length, "{$what}: no figures strip on a list");
        self::assertSame(0, $xp->query('//main//tr[contains(@class, "grp-add")]')->length, "{$what}: no \"+ Add\" row (the head's main button)");
        foreach ($xp->query('//main//div[contains(@class, "doc-board")]//table/thead/tr') ?: [] as $tr) {
            $n = $xp->query('th', $tr)->length;
            self::assertTrue($n >= 3 && $n <= 5, "{$what}: {$n} columns");
        }
        self::assertLessThanOrEqual(1, $xp->query('//main//header[contains(@class, "page-head")]//div[contains(@class, "ph-actions")]//*[contains(@class, "btn") and contains(@class, "primary")]')->length,
            "{$what}: ONE main button");
        $pick = '//main//*[contains(@class, "toolbar")]/details[contains(@class, "show-menu")]';
        $current = $xp->query($pick . '/ul[@class="show-list"]/li/*[@aria-current]');
        self::assertSame(1, $current->length, "{$what}: the choice shown");
        self::assertSame('span', $current->item(0)?->nodeName, "{$what}: the choice shown is no link");
        self::assertSame(Words::INV_LIST['show'] . ': ' . trim((string) $current->item(0)?->firstChild?->textContent), trim((string) preg_replace('/\s+/u', ' ',
            (string) $xp->evaluate('string(' . $pick . '/summary)'))), "{$what}: the Show button says the choice shown");
        // rd1's Show (the integration, docs/decisions.md RD-I8): each choice's name (span.show-name; a group page's count beside it).
        return array_map(static fn (\DOMElement $c): array => [trim((string) $c->firstChild?->textContent), $c->hasAttribute('aria-current')],
            array_values(iterator_to_array($xp->query($pick . '/ul[@class="show-list"]/li/*'))));
    }

    /** The groups' headings: [name, count] in order. @return list<array{0: string, 1: string}> */
    private static function groupHeads(UiResponse $page): array
    {
        $xp = self::xp($page);
        $out = [];
        foreach ($xp->query('//main//div[contains(@class, "doc-group")]/h3[contains(@class, "grp-title")]') ?: [] as $h) {
            $out[] = [trim((string) $xp->evaluate('string(.//*[contains(@class, "grp-toggle") or contains(@class, "grp-name")])', $h)),
                trim((string) $xp->evaluate('string(span[contains(@class, "grp-count")])', $h))];
        }
        return $out;
    }

    private function start(KernelBrowser $web, string $kind, array $over = []): string
    {
        $r = $web->post(StockOps::PATHS[$kind], $over + $web->get(StockOps::PATHS[$kind])->form(StockOps::PATHS[$kind], true));
        self::assertSame(303, $r->status, $r->describe());
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    // ------------------------------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------------------------------

    public function testItemsIsTheArtboardsSimpleListOfThreeColumnsInItsThreeGroups(): void
    {
        $who = $this->uiUser('stock_controller');
        $cards = new ItemCards(self::$db);
        $blocked = $this->item('legacy', 50, 'Tank five');
        $check = $this->item('legacy', 20, 'Strong shot');
        $ready = $this->item('legacy', 10, 'Cola ten');
        $none = $this->item('legacy', 0, 'Nothing yet');
        $cards->save(Caller::staff($who['id']), $blocked, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no']);
        $cards->confirm(Caller::staff($who['id']), $blocked, 1, true);
        $cards->save(Caller::staff($who['id']), $check, 0, ['nicotine_mg' => '25']);
        $cards->save(Caller::staff($who['id']), $ready, 0, ['product_type' => 'tank', 'liquid_ml' => '2', 'duty_liable' => 'no']);
        $cards->confirm(Caller::staff($who['id']), $ready, 1, true);
        $web = $this->signIn($who);
        $list = $web->get('/ui/items/cards');
        // The Show says what each choice holds (the fix round, docs/decisions.md RD-F3): Not confirmed (a card not confirmed, or none) and
        // Blocked (the existing blocked filter: a confirmed card can be blocked too); the group Needs a look holds both kinds.
        self::assertSame([[Words::INV_LIST['all_items'], true], [Words::INV_LIST['holding'], false], [Words::INV_LIST['show_unconfirmed'], false],
            [Words::INV_LIST['show_blocked'], false], [Words::INV_LIST['by_hand'], false]], self::assertSimpleList($list, 'items', Words::INV_LIST['items']));
        self::assertSame(['/ui/items/cards?stock=1', '/ui/items/cards?state=unconfirmed', '/ui/items/cards?blocked=1', '/ui/items/new?show=all'],
            self::attrs($list, '//details[contains(@class, "show-menu")]//a', 'href'), 'each choice a link: Made by hand is its own page');
        self::assertSame([Words::INV_LIST['items_search']], self::attrs($list, '//form[contains(@class, "toolbar")]//input[@type="search"]', 'placeholder'));
        // The groups in the artboard's order and colours, each count a figure; three columns; the name opens the item, its CW number under it.
        self::assertSame([[Words::INV_LIST['g_look'], '2'], [Words::INV_LIST['g_none'], '1'], [Words::INV_LIST['g_ready'], '1']], self::groupHeads($list));
        self::assertSame(['needs', 'waiting', 'done'], array_map(static fn (string $c): string => trim(str_replace(['grp-block', 'doc-group'], '', $c)),
            self::attrs($list, '//main//div[contains(@class, "doc-group")]', 'class')));
        self::assertSame([Words::INV_LIST['col_item'], Words::INV_LIST['col_status'], Words::INV_LIST['col_on_hand']], self::texts($list, '//div[@id="g-look"]//thead/tr/th'));
        self::assertSame(['Tank five', 'Strong shot'], self::texts($list, '//div[@id="g-look"]//tbody/tr/th/a[contains(@class, "row-link")]'), 'most stock first');
        self::assertSame([self::cw($blocked), self::cw($check)], self::texts($list, '//div[@id="g-look"]//tbody/tr/th/span[@class="o-sub"][1]'));
        self::assertSame([Words::INV_LIST['p_blocked'], Words::INV_LIST['p_check']], self::texts($list, '//div[@id="g-look"]//tbody/tr/td[contains(@class, "doc-st")]'));
        self::assertSame(['c-status doc-st blocked', 'c-status doc-st needs'], self::attrs($list, '//div[@id="g-look"]//tbody/tr/td[contains(@class, "doc-st")]', 'class'));
        self::assertSame(['tank or pod over 2 ml'], self::texts($list, '//div[@id="g-look"]//tbody/tr[1]/th/span[@class="o-sub"][2]'), 'a block\'s rules stay under the name');
        self::assertSame([Words::INV_LIST['p_none']], self::texts($list, '//div[@id="g-none"]//tbody/tr/td[contains(@class, "doc-st")]'));
        self::assertSame([Words::INV_LIST['p_ready']], self::texts($list, '//div[@id="g-ready"]//tbody/tr/td[contains(@class, "doc-st")]'));
        self::assertSame(['50', '20'], self::texts($list, '//div[@id="g-look"]//tbody/tr/td[contains(@class, "num")]'));
        // The head: More (the filters of before, Import, the download), then "+ New item" for who may make an item by hand.
        $head = '//main//header[contains(@class, "page-head")]//div[contains(@class, "ph-actions")]';
        self::assertSame(['/ui/items/new'], self::attrs($list, $head . '/a[contains(@class, "primary")]', 'href'));
        self::assertSame([Words::INV_LIST['new_item']], self::texts($list, $head . '/a[contains(@class, "primary")]'));
        self::assertSame(['state', 'type'], self::attrs($list, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
        self::assertSame(['stock', 'warnings', 'blocked', 'discontinued'], self::attrs($list, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//input[@type="checkbox"]', 'name'));
        self::assertSame(['/ui/items/cards/import', '/ui/items/cards.csv'], self::attrs($list, $head . '/details[contains(concat(" ", @class, " "), " more ")]/div/a', 'href'));
        $buyer = $this->signIn($this->uiUser('buyer'))->get('/ui/items/cards');
        self::assertSame(0, self::xp($buyer)->query($head . '//a[contains(@class, "primary")] | //a[@href="/ui/items/cards/import"]')->length, 'no New item, no Import');
        // A Show choice and a filter of More: the Show button says which; a search keeps the choice.
        $holding = $web->get('/ui/items/cards', ['stock' => '1']);
        self::assertSame([Words::INV_LIST['holding']], self::texts($holding, '//details[contains(@class, "show-menu")]//*[@aria-current]'));
        self::assertSame(['stock'], self::attrs($holding, '//form[contains(@class, "toolbar")]/input[@type="hidden"]', 'name'));
        self::assertSame(['Tank five', 'Strong shot', 'Cola ten'], self::texts($holding, '//main//tbody/tr/th/a'));
        $look = $web->get('/ui/items/cards', ['state' => 'unconfirmed']);
        self::assertSame([Words::INV_LIST['show_unconfirmed']], self::texts($look, '//details[contains(@class, "show-menu")]//*[@aria-current]'));
        self::assertSame(['Strong shot', 'Nothing yet'], self::texts($look, '//main//tbody/tr/th/a'));
        // Blocked: the confirmed card that breaks a blocking rule, which Not confirmed leaves out, is on its own choice.
        $blockedOnly = $web->get('/ui/items/cards', ['blocked' => '1']);
        self::assertSame([Words::INV_LIST['show_blocked']], self::texts($blockedOnly, '//details[contains(@class, "show-menu")]//*[@aria-current]'));
        self::assertSame(['Tank five'], self::texts($blockedOnly, '//main//tbody/tr/th/a'));
        // Every item of Needs a look is under one of its two choices.
        self::assertSame(['Strong shot', 'Tank five'], array_values(array_intersect(['Strong shot', 'Tank five'],
            [...self::texts($look, '//main//tbody/tr/th/a'), ...self::texts($blockedOnly, '//main//tbody/tr/th/a')])));
        $warned = $web->get('/ui/items/cards', ['warnings' => '1']);
        self::assertSame([Words::INV_LIST['filtered']], self::texts($warned, '//details[contains(@class, "show-menu")]//*[@aria-current]'), 'a filter of More: the choice says so');
        self::assertSame(['warnings'], self::attrs($warned, $head . '/details[contains(concat(" ", @class, " "), " more ")]//details[@open]//input[@checked]', 'name'),
            'More\'s filters open in the menu with the one on');
    }

    // ------------------------------------------------------------------------------------------
    // Adjustments and Transfers
    // ------------------------------------------------------------------------------------------

    public function testAdjustmentsShowTheKindsAsShowChoicesInTheArtboardsGroupsAndFourColumns(): void
    {
        $sku = $this->item('strict', 4, 'Omega pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $draft = $this->start($web, 'in', ['reason_code' => 'found']);
        self::assertSame(303, $web->post($draft . '/add', ['q' => self::cw($sku), 'qty' => '5', 'cost' => '1.50'] + $web->get($draft)->form($draft . '/add', true))->status);
        $done = $this->start($web, 'in', ['reason_code' => 'found']);
        self::assertSame(303, $web->post($done . '/add', ['q' => self::cw($sku), 'qty' => '7', 'cost' => '1.50'] + $web->get($done)->form($done . '/add', true))->status);
        self::assertSame(303, $web->post($done . '/lines', ['action' => 'post'] + $web->get($done)->form($done . '/lines', true))->status);

        $list = $web->get('/ui/stock/in');
        self::assertSame([[Words::INV_LIST['kinds_in'], true], [Words::INV_LIST['kinds_out'], false], [Words::INV_LIST['kinds_adjust'], false], [Words::INV_LIST['kinds_release'], false]],
            self::assertSimpleList($list, 'stock in', Words::INV_LIST['adjustments']));
        self::assertSame(['/ui/stock/out', '/ui/stock/adjustments', '/ui/stock/releases'], self::attrs($list, '//details[contains(@class, "show-menu")]//a', 'href'));
        self::assertSame([[Words::INV_LIST['g_draft'], '1'], [Words::INV_LIST['g_week'], '1']], self::groupHeads($list), 'made final today: Done this week');
        self::assertSame([Words::INV_LIST['col_adjustment'], Words::INV_LIST['col_kind'], Words::INV_LIST['col_reason'], Words::INV_LIST['col_units']],
            self::texts($list, '//div[@id="g-week"]//thead/tr/th'));
        self::assertSame([Words::INV_LIST['kind_in']], self::texts($list, '//div[@id="g-week"]//tbody/tr/td[1]'));
        self::assertSame(['+7'], self::texts($list, '//div[@id="g-week"]//tbody/tr/td[contains(@class, "chg") and contains(@class, "up")]'));
        self::assertSame(['+5'], self::texts($list, '//div[@id="g-draft"]//tbody/tr/td[contains(@class, "chg")]'));
        self::assertSame([$done], self::attrs($list, '//div[@id="g-week"]//tbody/tr/th/a[contains(@class, "row-link")]', 'href'), 'a row opens its record');
        // The head: More (the state and the warehouse of before, the download, Goods received), then "+ New stock in" opening the create fold.
        $head = '//main//header[contains(@class, "page-head")]//div[contains(@class, "ph-actions")]';
        self::assertSame(['/ui/stock/in#new'], self::attrs($list, $head . '/a[contains(@class, "primary")]', 'href'));
        self::assertSame([Words::NEW['stock_in']], self::texts($list, $head . '/a[contains(@class, "primary")]'));
        self::assertSame(['state', 'warehouse'], self::attrs($list, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
        self::assertContains('/ui/receiving', self::attrs($list, $head . '/details[contains(concat(" ", @class, " "), " more ")]/div/a', 'href'));
        self::assertSame(1, self::xp($list)->query('//details[@id="new"]//form[@action="/ui/stock/in"]')->length, 'the create form as before');
        // Corrections: the reasons are on each line.
        $adjust = $this->start($web, 'adjust');
        self::assertSame(303, $web->post($adjust . '/add', ['q' => self::cw($sku), 'qty' => '-1', 'reason' => 'damaged'] + $web->get($adjust)->form($adjust . '/add', true))->status);
        $corr = $web->get('/ui/stock/adjustments');
        self::assertSame([Words::INV_LIST['kinds_adjust']], self::texts($corr, '//details[contains(@class, "show-menu")]//*[@aria-current]'));
        self::assertSame([Words::INV_LIST['kind_adjust']], self::texts($corr, '//div[@id="g-draft"]//tbody/tr/td[1]'));
        self::assertSame([Words::INV_LIST['reason_lines']], self::texts($corr, '//div[@id="g-draft"]//tbody/tr/td[2]'));
        // A state chosen under More: the search keeps it, the choice of kind keeps it too.
        $drafts = $web->get('/ui/stock/in', ['state' => 'draft']);
        self::assertSame(['state'], self::attrs($drafts, '//form[contains(@class, "toolbar")]/input[@type="hidden"]', 'name'));
        self::assertSame('/ui/stock/out?state=draft', self::attrs($drafts, '//details[contains(@class, "show-menu")]//a', 'href')[0]);
        // Transfers: its own page; the state is its Show; From and To.
        $move = $this->start($web, 'transfer', ['warehouse' => (string) self::warehouseId('MAIN'), 'to_warehouse' => (string) self::warehouseId('VERIFY')]);
        self::assertNotSame('', $move);
        $tr = $web->get('/ui/stock/transfers');
        $show = self::assertSimpleList($tr, 'transfers', Words::STOCK_KIND['transfer']['tab']);
        self::assertSame([Words::STOCK_OPS['any_state'], true], $show[0]);
        self::assertSame([Words::INV_LIST['col_transfer'], Words::INV_LIST['col_from'], Words::INV_LIST['col_to'], Words::INV_LIST['col_units']],
            self::texts($tr, '//div[@id="g-draft"]//thead/tr/th'));
        self::assertSame(['warehouse'], self::attrs($tr, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
    }

    // ------------------------------------------------------------------------------------------
    // Stock counts
    // ------------------------------------------------------------------------------------------

    public function testStockCountsIsTheArtboardsTickListWithItsBarAndItsThreeViews(): void
    {
        $a = $this->item('strict', 9, 'Best seller pod');
        $b = $this->item('strict', 4, 'Slow liquid');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/stock/counts');
        self::assertSame([[Words::INV_LIST['view_items'], true], [Words::INV_LIST['view_sheets'], false], [Words::INV_LIST['view_differences'], false]],
            self::assertSimpleList($page, 'stock counts', Words::INV_LIST['counts']));
        self::assertSame([[Words::COUNT_GROUP['never'], '2']], self::groupHeads($page));
        self::assertSame(['grp-block doc-group waiting'], self::attrs($page, '//main//div[contains(@class, "doc-group")]', 'class'), 'Never counted is grey');
        self::assertSame([Words::COUNTS['tick'], Words::COUNTS['item'], Words::COUNTS['last_counted'], Words::COUNTS['sells']], self::texts($page, '//main//thead/tr/th'));
        self::assertSame(0, self::xp($page)->query('//main//td[contains(@class, "doc-st")]')->length, 'no status column');
        self::assertSame([self::cw($a), self::cw($b)], self::texts($page, '//main//tbody/tr/th/span[@class="o-sub"]'));
        // The bar: "N ticked", More (the other ways to count, the shelf, a note), ONE main button "Count the ticked items".
        $bar = '//form[@action="/ui/stock/counts" and @method="post"]/div[contains(@class, "count-bar")]';
        self::assertSame([Words::INV_LIST['count_ticked']], self::texts($page, $bar . '/button[contains(@class, "primary")]'));
        self::assertSame(['ticked'], self::attrs($page, $bar . '/button[contains(@class, "primary")]', 'value'));
        self::assertSame(['next', 'empty'], self::attrs($page, $bar . '/details[contains(@class, "more")]//button[@name="mode"]', 'value'));
        self::assertSame(['note'], self::attrs($page, $bar . '/details[contains(@class, "more")]//input[@type="text"]', 'name'));
        self::assertSame(['ticked'], self::attrs($page, '//form[@action="/ui/stock/counts" and @method="post"]/button[contains(@class, "po-default")]', 'value'),
            'Enter counts the ticked items');
        // The head: Open sheets (N) once one is open; the warehouse and how many to list under More.
        $open = $web->post('/ui/stock/counts', ['mode' => 'ticked', 'pick_' . $a => '1'] + $page->form('/ui/stock/counts'));
        self::assertSame(303, $open->status, $open->describe());
        $again = $web->get('/ui/stock/counts');
        $head = '//main//header[contains(@class, "page-head")]//div[contains(@class, "ph-actions")]';
        self::assertSame([Words::say('COUNTS', 'open_sheets', 1)], self::texts($again, $head . '/a[contains(@class, "btn") and not(contains(@class, "primary"))]'));
        self::assertSame(['warehouse', 'show'], self::attrs($again, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
        // Its other two views: the same title, their Show choice shown.
        $sheets = $web->get('/ui/stock/counts/sheets');
        self::assertSame([Words::INV_LIST['view_sheets'], true], self::assertSimpleList($sheets, 'count sheets', Words::INV_LIST['counts'])[1]);
        self::assertSame([Words::INV_LIST['col_sheet'], Words::INV_LIST['col_where'], Words::INV_LIST['col_lines'], Words::INV_LIST['col_opened_by']],
            self::texts($sheets, '//main//thead/tr/th'));
        self::assertSame(['state', 'warehouse'], self::attrs($sheets, $head . '/details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
        $diff = $web->get('/ui/stock/counts/differences');
        self::assertSame([Words::INV_LIST['view_differences'], true], self::assertSimpleList($diff, 'differences', Words::INV_LIST['counts'])[2]);
    }

    // ------------------------------------------------------------------------------------------
    // The item page
    // ------------------------------------------------------------------------------------------

    public function testTheItemPageIsOnePageInSectionsWithNoTabRow(): void
    {
        $sku = $this->item('strict', 12, 'Elux Legend Mr Blue 10mg');
        self::$db->exec("UPDATE sku SET brand = 'Elux Legend', volume_ml = 10, strength_mg = 10, nic_type = 'salt', line = 'Nic Salt 10ml' WHERE id = ?", [$sku]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?)", [$sku]);
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => 'Vape Wholesale', 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@vapewholesale.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer), (int) $s['id'], $sku, ['units_per_pack' => '10', 'purchase_unit' => 'box', 'supplier_code' => 'EL-1'],
            ['pack_price' => '9.00']);
        self::$db->exec('UPDATE supplier_item SET is_preferred = 1 WHERE id = ?', [(int) $si['id']]);

        $web = $this->signIn($this->uiUser(['stock_controller', 'buyer']));
        $page = $web->get('/ui/items/' . $sku);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")] | //main//*[contains(@class, "it-panel")]')->length, 'no tab row');
        // The head: back to Items, the name and its status label, the fact line, More and the ONE main button (Order more).
        self::assertSame(['/ui/items/cards'], self::attrs($page, '//main//header[contains(@class, "page-head")]//p[contains(@class, "ph-back")]/a', 'href'));
        self::assertSame([Words::ITEM['st_not_selling']], self::texts($page, '//main//header//div[@class="ph-title"]/span[contains(@class, "tag")]'));
        self::assertSame([self::cw($sku) . ' · Elux Legend · 10 ml · 10 mg nic salt'], self::texts($page, '//main//header//p[contains(@class, "ph-meta")]'));
        $bar = '//main//header//div[contains(@class, "item-bar")]';
        self::assertSame([Words::ITEM['order_more']], self::texts($page, $bar . '/a[contains(@class, "primary")]'));
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//form[@action="/ui/stock/counts"]/button[normalize-space() = "' . Words::COUNTS['item_count_it'] . '"]')->length,
            'Count it under More');
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//a[normalize-space() = "' . Words::ITEM['move_stock'] . '"]')->length, 'Move stock under More');
        // The stock card: four figures.
        self::assertSame([Words::ITEM['on_shelf'], Words::ITEM['held_orders'], Words::ITEM['free'], Words::ITEM['coming']],
            self::texts($page, '//section[contains(@class, "item-strip")]/div/p[@class="doc-step-label"]'));
        self::assertSame(['12', '0', '12', '0'], self::texts($page, '//section[contains(@class, "item-strip")]/div/p[contains(@class, "doc-step-big")]'));
        // Details: PRODUCT, BUYING, LEGAL; Change opens the card's form.
        self::assertSame([Words::ITEM_PAGE['g_product'], Words::ITEM_PAGE['g_buying'], Words::ITEM_PAGE['g_legal']],
            self::texts($page, '//section[@id="card"]/div[contains(@class, "facts-group")]/p[@class="facts-title"]'));
        self::assertSame(['/ui/items/' . $sku . '/card'], self::attrs($page, '//section[@id="card"]/div[@class="card-head"]/a', 'href'));
        self::assertSame(['Vape Wholesale', \CW\Ui\Controller\PurchaseOrdersController::pack('box', 10)],
            array_slice(self::texts($page, '//div[contains(@class, "facts-buying")]//dd'), 0, 2), 'the main supplier and the box it is bought in');
        self::assertContains(Words::ITEM_PAGE['card'], self::texts($page, '//div[contains(@class, "facts-legal")]//dt'));
        // The recent stock history and All history.
        self::assertSame([Words::ITEM_PAGE['all_history']], self::texts($page, '//section[contains(@class, "item-moves")]/div[@class="card-head"]/a'));
        self::assertSame(['+12'], self::texts($page, '//table[contains(@class, "item-history")]//td[contains(@class, "chg")]'));
        // The side cards: Store listings, Barcodes (+ Add, each one's Change), Suppliers.
        self::assertSame([Words::ITEM_PAGE['listings'], Words::ITEM_PAGE['barcodes'], Words::ITEM_PAGE['suppliers']],
            self::texts($page, '//aside[contains(@class, "item-side")]/section/div[@class="card-head"]/h2'));
        self::assertSame(['4006381333931 · ' . Words::ITEM_PAGE['single']], self::texts($page, '//section[@id="barcodes"]//li/p[@class="side-line"]'));
        self::assertSame(1, $xp->query('//section[@id="barcodes"]/details[contains(@class, "card-add")]//form[@action="/ui/items/' . $sku . '/barcodes"]')->length);
        self::assertSame(1, $xp->query('//section[@id="barcodes"]//details[contains(@class, "bc-change")]//form[@action="/ui/items/' . $sku . '/barcodes/units"]')->length);
        self::assertSame(['/ui/purchasing/supplier-items/' . $si['id']], self::attrs($page, '//section[contains(@class, "item-suppliers")]//a', 'href'));
        self::assertSame([Words::ITEM_PAGE['main']], self::texts($page, '//section[contains(@class, "item-suppliers")]//span[contains(@class, "tag")]'));
        // What the tabs held: closed folds under the history; an old ?tab= link and a notice open theirs.
        self::assertSame(['it-wh', 'it-selling', 'it-groups', 'it-history'], self::attrs($page, '//div[contains(@class, "item-main")]/details[contains(@class, "it-fold")]', 'id'));
        self::assertSame(0, $xp->query('//details[contains(@class, "it-fold") and @open]')->length);
        self::assertSame(['it-selling'], self::attrs($web->get('/ui/items/' . $sku, ['tab' => 'stores']), '//details[contains(@class, "it-fold") and @open]', 'id'));
        self::assertSame(['it-groups'], self::attrs($web->get('/ui/items/' . $sku, ['notice' => 'family_set']), '//details[contains(@class, "it-fold") and @open]', 'id'));
        self::assertSame(1, $xp->query('//details[@id="it-selling"]//section[@id="selling-mode"]')->length, 'the selling mode stays on the page');
        // Someone who does not see suppliers: no BUYING, no Suppliers card.
        $look = $this->signIn($this->uiUser('mapper'))->get('/ui/items/' . $sku);
        self::assertSame([Words::ITEM_PAGE['g_product'], Words::ITEM_PAGE['g_legal']], self::texts($look, '//section[@id="card"]/div[contains(@class, "facts-group")]/p[@class="facts-title"]'));
        self::assertSame(0, self::xp($look)->query('//section[contains(@class, "item-suppliers")] | //section[@id="card"]/div[@class="card-head"]/a')->length);
    }

    // ------------------------------------------------------------------------------------------
    // The other Items and Inventory pages
    // ------------------------------------------------------------------------------------------

    public function testEveryOtherItemsAndInventoryListIsTheSameSimpleList(): void
    {
        $sku = $this->item('strict', 6, 'Simple pod');
        $web = $this->signIn($this->uiUser(['stock_controller', 'mapping_lead']));
        $this->start($web, 'in', ['reason_code' => 'found']);
        $pages = [
            '/ui/items/families' => Words::MENU['families'],
            '/ui/items/packs' => Words::INV_LIST['packs_on'],
            '/ui/items/packs/links' => Words::INV_LIST['packs_on'],
            '/ui/items/barcodes' => Words::MENU['barcodes'],
            '/ui/items/new?show=all' => Words::INV_LIST['items'],
            '/ui/stock/in' => Words::INV_LIST['adjustments'],
            '/ui/stock/out' => Words::INV_LIST['adjustments'],
            '/ui/stock/releases' => Words::INV_LIST['adjustments'],
            '/ui/stock/repack' => Words::MENU['repack'],
            '/ui/stock/reservations' => Words::MENU['reservations'],
            '/ui/stock/movements' => Words::MENU['movements'],
            '/ui/stock' => Words::MENU['stock'],
        ];
        foreach ($pages as $path => $title) {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
            self::assertSimpleList($web->get((string) parse_url($path, PHP_URL_PATH), array_map('strval', $q)), $path, $title);
        }
        // Multipacks: its pack items and its pack links are one Show choice.
        self::assertSame([[Words::INV_LIST['packs_on'], true], [Words::INV_LIST['packs_all'], false], [Words::PAGE_TITLE['pack_links'], false]],
            self::assertSimpleList($web->get('/ui/items/packs'), 'packs', Words::INV_LIST['packs_on']));
        // Made by hand is Items' Show choice, last (after Not confirmed and Blocked: the fix round, RD-F3).
        self::assertSame([Words::INV_LIST['by_hand'], true], self::assertSimpleList($web->get('/ui/items/new', ['show' => 'all']), 'by hand', Words::INV_LIST['items'])[4]);
        // Stock on hand: five columns, one On hand figure (every warehouse together).
        $stock = $web->get('/ui/stock');
        self::assertSame([Words::STOCK_VIEW['product'], Words::STOCK_VIEW['status'], Words::INV_LIST['col_on_hand'], Words::STOCK_VIEW['reserved'], Words::STOCK['available']],
            self::texts($stock, '//main//div[contains(@class, "doc-board")]//thead/tr/th'));
        self::assertSame(['6'], self::texts($stock, '//main//tbody/tr[th/a[@href="/ui/items/' . $sku . '"]]/td[@data-label="' . Words::INV_LIST['col_on_hand'] . '"]'));
        // Stock history: the kind of change is the Show choice; the warehouse and the figure under More.
        $moves = $web->get('/ui/stock/movements');
        self::assertSame(['warehouse', 'figure'], self::attrs($moves, '//main//header//details[contains(concat(" ", @class, " "), " more ")]//form//select', 'name'));
        self::assertSame(Words::STOCK_VIEW['all_types'], self::assertSimpleList($moves, 'movements', Words::MENU['movements'])[0][0]);
    }
}
