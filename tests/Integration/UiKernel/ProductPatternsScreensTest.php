<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\BarcodeSync;
use CW\Catalogue\ItemCards;
use CW\Items\Families;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The Products pages on the approved patterns (wave w11, docs/decisions.md W11), as the pages are served: All Products is a board (the
 * products grouped by their card, plain filter inputs in the one Show row and one Clear), Add Item the one-page form (name, brand and barcode
 * first, the legal and the buying details in folds, the bar at the bottom) with the items made by hand as a list of their own, Families a
 * board and a family a record page, Pack items and Pack links boards whose rows open the record, Barcodes an inbox (one record open, its one
 * decision). Layout only: every form posts where and what it did.
 */
final class ProductPatternsScreensTest extends KernelUiTestCase
{
    /** The product card's "not known" answers as a browser sends them (the test client sends "on" for a checked radio with value=""). */
    private const NOT_KNOWN = ['c_duty_liable' => '', 'c_single_use' => ''];

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /** @return list<string> the texts of the nodes $path finds */
    private static function texts(UiResponse $page, string $path): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', (string) $n->textContent)),
            array_values(iterator_to_array(self::xp($page)->query($path) ?: [])));
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    private function submit(KernelBrowser $web, string $page, string $action, array $over = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        return $web->post($action, $over + $p->form($action, true));
    }

    /**
     * The board pattern (1): one Show row and no Filter pop-over or chips; the groups' headings (a toggle and a count) over cards; every row's
     * status cell filled. @return list<string> the groups' names, in order
     */
    private static function assertBoard(UiResponse $page, string $what, bool $search = true): array
    {
        $xp = self::xp($page);
        self::assertSame($search ? 1 : 0, $xp->query('//main//form[contains(@class, "toolbar")]//input[@type="search"]')->length, "{$what}: the search");
        self::assertSame(0, $xp->query('//main//details[contains(@class, "tb-pop") and not(contains(@class, "tb-show"))]')->length, "{$what}: no Filter pop-over (the one Show control only, DC27)");
        self::assertSame(0, $xp->query('//main//*[contains(@class, "filters-on") or contains(@class, "show-tabs") or contains(@class, "row-strips")]')->length, "{$what}: the old parts");
        self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//table[contains(@class, "doc-list")]')->length,
            "{$what}: the groups' cards");
        self::assertSame($xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length,
            $xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr/td[contains(@class, "doc-st")]')->length, "{$what}: every row's status cell is filled");
        self::assertSame(0, $xp->query('//main//div[contains(@class, "doc-board")]//tbody//span[contains(concat(" ", @class, " "), " chip ")][not(parent::td[contains(@class, "doc-st")])]')->length,
            "{$what}: a status is the pill in its cell (RD8), a small fact a soft tag");
        return self::texts($page, '//main//div[contains(@class, "doc-group")]/h3[@class="grp-title"]/button[@class="grp-toggle"]');
    }

    public function testAllProductsIsABoardByCardWithPlainFiltersAndOneClear(): void
    {
        $who = $this->uiUser('stock_controller');
        $cards = new ItemCards(self::$db);
        $high = $this->item('legacy', 50, 'Tank five');
        $low = $this->item('legacy', 5, 'Strong shot');
        $none = $this->item('legacy', 0, 'Nothing yet');
        $cards->save(Caller::staff($who['id']), $high, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no']);
        $cards->confirm(Caller::staff($who['id']), $high, 1, true);
        $cards->save(Caller::staff($who['id']), $low, 0, ['nicotine_mg' => '25']);
        $web = $this->signIn($who);
        $list = $web->get('/ui/items/cards');
        self::assertSame(200, $list->status, $list->describe());
        $xp = self::xp($list);
        self::assertSame([Words::CARD_STATE['blocked'], Words::CARDS['group_check'], Words::CARD_STATE['none']], self::assertBoard($list, 'all products'),
            'blocked first, then the cards to confirm, then no card yet');
        self::assertSame(['blocked', 'needs', 'waiting'], array_map(static fn (\DOMElement $d): string => trim(str_replace(['grp-block', 'doc-group'], '', $d->getAttribute('class'))),
            array_values(iterator_to_array($xp->query('//main//div[contains(@class, "doc-group")]')))));
        // The rows: the number and the name one link that opens the item; the status cell says the card; the rules a soft tag.
        self::assertSame([self::cw($high) . ' Tank five'], self::texts($list, '//div[@id="g-blocked"]//tbody/tr/th/a[contains(@class, "row-link")]'));
        self::assertSame(['/ui/items/' . $high], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-blocked"]//tbody/tr/th/a[contains(@class, "row-link")]')))));
        self::assertSame([Words::CARD_STATE['blocked']], self::texts($list, '//div[@id="g-blocked"]//tbody/tr/td[contains(@class, "doc-st") and contains(@class, "blocked")]'));
        self::assertSame(['tank or pod over 2 ml'], self::texts($list, '//div[@id="g-blocked"]//tbody/tr/th/span[@class="o-sub"]'), 'the rules under the name, not a second "Blocked"');
        self::assertSame([Words::CARD_STATE['unconfirmed']], self::texts($list, '//div[@id="g-check"]//tbody/tr/td[contains(@class, "doc-st") and contains(@class, "needs")]'));
        self::assertSame([Words::say('CARDS', 'warning_line', 'nicotine over 20 mg/ml')], self::texts($list, '//div[@id="g-check"]//tbody/tr/th//span[contains(@class, "tag") and contains(@class, "needs")]'));
        // A card group has the card's columns; the No card group has none: the stock held, and the catalogue team's "Fill in" above the row's link.
        self::assertSame([Words::CARDS['product'], Words::CARDS['state'], Words::CARDS['held'], Words::CARDS['kind'], Words::CARDS['ml'], Words::CARDS['mg'],
            Words::CARDS['duty'], Words::CARDS['single_use'], Words::CARDS['flavour']], self::texts($list, '//div[@id="g-blocked"]//thead/tr/th'));
        self::assertSame([Words::CARDS['product'], Words::CARDS['state'], Words::CARDS['held'], Words::CARDS['fill_col']], self::texts($list, '//div[@id="g-none"]//thead/tr/th'));
        self::assertSame(['/ui/items/' . $none . '/card'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-none"]//tbody/tr/td[contains(@class, "c-next")]/a[contains(@class, "btn")]')))));
        self::assertSame([Words::CARDS['fill_button'] . ' ' . self::cw($none)], self::texts($list, '//div[@id="g-none"]//tbody/tr/td[contains(@class, "c-next")]/a'),
            'each names its product for a screen reader');
        self::assertSame(0, self::xp($this->signIn($this->uiUser('buyer'))->get('/ui/items/cards'))->query('//div[@id="g-none"]//a[contains(@href, "/card")]')->length,
            'someone who only looks has no Fill in');

        // The approved board: no figures strip, no heading of its own over the groups.
        self::assertSame(0, $xp->query('//main//section[contains(@class, "rp-figs")] | //main//h2[contains(@class, "board-title")] | //main//ul[contains(@class, "summary-line")]')->length);

        // The filters in the ONE Show control (DC27: a pop-over of the card, the kind of product and the four ticks, one Show button); no Clear while nothing is on.
        $row = '//form[contains(@class, "toolbar")]/div[@class="tb-state"]';
        self::assertSame(['state', 'type'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('name'), array_values(iterator_to_array($xp->query($row . '//select')))));
        self::assertSame(['stock', 'warnings', 'blocked', 'discontinued'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($xp->query($row . '//label[contains(@class, "tb-check")]/input[@type="checkbox"]')))));
        self::assertSame([Words::CARDS['stock'], Words::CARDS['warnings'], Words::CARDS['blocked'], Words::CARDS['discontinued']], self::texts($list, $row . '//label[contains(@class, "tb-check")]'));
        self::assertSame(1, $xp->query($row . '//button[@type="submit"]')->length);
        self::assertSame(0, $xp->query('//form[contains(@class, "toolbar")]//a[@href="/ui/items/cards"]')->length, 'nothing on: nothing to clear');
        // Filters on: they show in their own inputs, and ONE Clear link takes every filter and the search off.
        $on = $web->get('/ui/items/cards', ['q' => 'tank', 'state' => 'confirmed', 'type' => 'tank', 'stock' => '1', 'blocked' => '1']);
        $oxp = self::xp($on);
        self::assertSame(['confirmed', 'tank'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            array_values(iterator_to_array($oxp->query($row . '//select/option[@selected]')))));
        self::assertSame(['stock', 'blocked'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($oxp->query($row . '//label/input[@checked]')))));
        self::assertSame('tank', (string) $oxp->evaluate('string(//input[@id="tb-q"]/@value)'));
        self::assertSame([Words::CARDS['clear_filters']], self::texts($on, '//form[contains(@class, "toolbar")]//a[@href="/ui/items/cards"]'));
        self::assertSame(1, $oxp->query('//form[contains(@class, "toolbar")]/div[@class="tb-end"]/details[contains(@class, "tb-more")]//a[@href="/ui/items/cards"]')->length,
            'at the right, under the toolbar\'s More (DC27)');
        self::assertSame(0, $oxp->query('//a[contains(@class, "tb-clear")]')->length, 'one Clear, not one per filter');
        self::assertSame([self::cw($high) . ' Tank five'], self::texts($on, '//main//tbody/tr/th/a[contains(@class, "row-link")]'));
    }

    public function testAddItemIsOnePageAndTheItemsMadeByHandAListOfTheirOwn(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/items/new');
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        $form = '//form[@id="new" and @action="/ui/items/new" and contains(@class, "doc-details") and @data-unsaved="1"]';
        self::assertSame(1, $xp->query($form)->length, 'the one form, its typed values guarded');
        // Name, brand and barcode first, in the two-column details; the legal and the buying details in folds, closed at first.
        $first = array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($xp->query($form . '/fieldset[contains(@class, "doc-grid")]//input[@type="text"]'))));
        self::assertSame(['name', 'brand', 'barcode'], array_slice($first, 0, 3));
        foreach (['new-legal' => ['c_product_type', 'c_liquid_ml', 'c_nicotine_mg', 'c_duty_liable', 'c_single_use', 'c_ecid', 'c_manufacturer', 'c_brand', 'c_flavour'],
            'new-buying' => ['pack_units', 'family', 'c_discontinued']] as $fold => $names) {
            $d = $xp->query($form . '/details[@id="' . $fold . '" and contains(@class, "fold")]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $d, $fold);
            self::assertFalse($d->hasAttribute('open'), "{$fold}: closed while nothing in it is typed");
            $in = array_values(array_unique(array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), iterator_to_array($xp->query('.//*[@name]', $d)))));
            self::assertSame($names, $in, $fold);
        }
        self::assertSame([Words::ITEM_NEW['legal'], Words::ITEM_NEW['buying']], self::texts($page, $form . '/details/summary'));
        // The bar at the bottom: Leave without saving, then the one main button (it sends no field of its own).
        $bar = $form . '/div[@class="doc-bar"]';
        self::assertSame('/ui/items/cards', (string) $xp->evaluate('string(' . $bar . '/a[@data-leave]/@href)'));
        self::assertSame([Words::ITEM_NEW['add']], self::texts($page, $bar . '/button[contains(@class, "primary")]'));
        self::assertSame('', (string) $xp->evaluate('string(' . $bar . '/button/@name)'));
        // The items made by hand are a list of their own: a link above the form, no list on it.
        self::assertSame(['/ui/items/new?show=all'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//header[contains(@class, "page-head")]//p[contains(@class, "ph-back")]/a')))), 'the eyebrow (DC3)');
        self::assertSame(0, $xp->query('//main//table')->length, 'the form alone');

        // A refused card opens its fold, with what was typed.
        $r = $web->post('/ui/items/new', ['name' => 'Big tank', 'c_product_type' => 'tank', 'c_liquid_ml' => 'nine'] + self::NOT_KNOWN + $page->form('/ui/items/new', true));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[@id="new-legal" and @open]//input[@name="c_liquid_ml" and @value="nine"]')->length, 'the legal fold open');
        self::assertSame(0, self::xp($r)->query('//details[@id="new-buying" and @open]')->length);
        $r = $web->post('/ui/items/new', ['name' => 'In a family', 'family' => 'No such family'] + self::NOT_KNOWN + $page->form('/ui/items/new', true));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[@id="new-buying" and @open]//input[@name="family" and @value="No such family"]')->length, 'the buying fold open');

        // Two items made; the second check on: the list view is a board by check state, a row opens the item, another person checks it from its row.
        $ok = $web->post('/ui/items/new', ['name' => 'Made one'] + self::NOT_KNOWN + $page->form('/ui/items/new', true));
        self::assertSame(303, $ok->status, $ok->describe());
        self::$db->exec("UPDATE app_setting SET value_json = 'true' WHERE setting_key = 'approvals.item_by_hand_check'");
        try {
            $ok = $web->post('/ui/items/new', ['name' => 'Made two'] + self::NOT_KNOWN + $web->get('/ui/items/new')->form('/ui/items/new', true));
            self::assertSame(303, $ok->status, $ok->describe());
            $two = (int) self::$db->value("SELECT id FROM sku WHERE name = 'Made two'");
            $lead = $this->signIn($this->uiUser('mapping_lead'));
            $list = $lead->get('/ui/items/new', ['show' => 'all']);
            self::assertSame(200, $list->status, $list->describe());
            self::assertSame([Words::BY_HAND_STATE['waiting'], Words::BY_HAND_STATE['not_asked']], self::assertBoard($list, 'made by hand', false));
            $lxp = self::xp($list);
            self::assertSame(0, $lxp->query('//form[@action="/ui/items/new" and @method="post"]')->length, 'the list alone');
            self::assertSame(['all', 'waiting'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
                array_values(iterator_to_array($lxp->query('//form[contains(@class, "toolbar")]/div[@class="tb-state"]/select[@name="show"]/option')))));
            self::assertSame(['/ui/items/' . $two], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
                array_values(iterator_to_array($lxp->query('//div[@id="g-waiting"]//tbody/tr/th/a[contains(@class, "row-link")]')))));
            self::assertSame(1, $lxp->query('//div[@id="g-waiting"]//tbody/tr/td[contains(@class, "c-next")]/form[@action="/ui/items/new/' . $two . '/check"]')->length,
                'the check above the row\'s link');
            self::assertSame(['/ui/items/new#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
                array_values(iterator_to_array($lxp->query('//div[@id="g-waiting"]//tr[contains(@class, "grp-add")]//a[contains(@class, "add-item")]')))));
            $r = $lead->post('/ui/items/new/' . $two . '/check', ['note' => 'right'] + $list->form('/ui/items/new/' . $two . '/check'));
            self::assertSame('/ui/items/new?notice=checked&show=waiting', $r->location(), $r->describe());
            $back = $lead->follow($r);
            self::assertStringContainsString(Words::ITEMS_NOTICE['checked'], $back->text());
            self::assertSame('waiting', (string) self::xp($back)->evaluate('string(//select[@name="show"]/option[@selected]/@value)'), 'the list, still showing those waiting');
            // A refused check comes back on the list, not the form.
            $again = $lead->post('/ui/items/new/' . $two . '/check', ['note' => 'again'] + $list->form('/ui/items/new/' . $two . '/check'));
            self::assertSame(409, $again->status, $again->describe());
            self::assertSame('not_waiting', $again->errorCode());
            self::assertGreaterThan(0, self::xp($again)->query('//main//div[contains(@class, "doc-group")]')->length, 'the list view');
            self::assertSame(0, self::xp($again)->query('//form[@id="new"]')->length, 'not the form');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'approvals.item_by_hand_check'");
        }
    }

    public function testFamiliesAreABoardAndAFamilyARecordPage(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $fam = new Families(self::$db);
        $on = $fam->create($this->staffUser('buyer'), 'Elux Legend')['id'];
        $off = $fam->create($this->staffUser('buyer'), 'Old range')['id'];
        $fam->setActive($this->staffUser('buyer'), $off, 1, false);
        $a = $this->item('legacy', 3, 'Legend Blue');
        $fam->assign($this->staffUser('buyer'), $a, $on, null);

        $list = $web->get('/ui/items/families');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame([Words::FAMILY['on']], self::assertBoard($list, 'families'));
        $xp = self::xp($list);
        self::assertSame(['', '1'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            array_values(iterator_to_array($xp->query('//div[@class="tb-state"]/select[@name="off"]/option')))));
        self::assertSame(['/ui/items/families/' . $on], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-on"]//tbody/tr/th/a[contains(@class, "row-link")]')))));
        self::assertSame(['#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-on"]//tr[contains(@class, "grp-add")]//a[contains(@class, "add-item")]')))));
        self::assertSame(1, $xp->query('//details[@id="new"]//form[@action="/ui/items/families"]/fieldset[contains(@class, "doc-grid")]//input[@name="name"]')->length);
        $all = $web->get('/ui/items/families', ['off' => '1']);
        self::assertSame([Words::FAMILY['on'], Words::FAMILY['off']], self::assertBoard($all, 'families, all'));
        self::assertSame([Words::FAMILY['clear_list']], self::texts($all, '//form[contains(@class, "toolbar")]/div[@class="tb-end"]/a[@href="/ui/items/families"]'));

        // The family: its status block and its bar in the head (Add items the main one, Rename and Switch off under More), the figures, the
        // paper card with its items as the card's lines.
        $path = '/ui/items/families/' . $on;
        $page = $web->get($path);
        self::assertSame(200, $page->status, $page->describe());
        $fx = self::xp($page);
        self::assertSame(1, $fx->query('//div[@class="doc-head"]//h1/span[contains(@class, "chip") and contains(@class, "done")]')->length, 'the status block');
        self::assertSame([Words::FAMILY['bulk']], self::texts($page, '//div[@class="doc-head"]//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-act")]/summary[contains(@class, "primary")]'));
        self::assertSame(1, $fx->query('//div[@class="doc-head"]//div[contains(@class, "doc-actions")]/details[contains(@class, "doc-act")]//form[@action="' . $path . '/add"]')->length);
        foreach (['/rename', '/switch'] as $action) {
            self::assertSame(1, $fx->query('//div[@class="doc-head"]//details[contains(@class, "more")]//form[@action="' . $path . $action . '"]')->length, "{$action} under More");
        }
        self::assertSame([Words::FAMILY['fig_items'], Words::FAMILY['fig_made'], Words::FAMILY['fig_changed']],
            self::texts($page, '//section[contains(@class, "rp-figs")]/div/p[@class="doc-step-label"]'));
        self::assertSame('1', self::texts($page, '//section[contains(@class, "rp-figs")]/div/p[@class="doc-step-big"]')[0]);
        self::assertSame(['Elux Legend'], self::texts($page, '//article[contains(@class, "doc-view")]//p[@class="doc-party-name"]'));
        self::assertSame(1, $fx->query('//article[contains(@class, "doc-view")]//form[@action="' . $path . '/remove"]//table[contains(@class, "doc-list")]//input[@name="pick_' . $a . '"]')->length,
            'its items are the card\'s lines');
        self::assertSame(['/ui/items/' . $a], array_map(static fn (\DOMElement $l): string => $l->getAttribute('href'),
            array_values(iterator_to_array($fx->query('//article[contains(@class, "doc-view")]//tbody/tr/th/a')))));
        // Adding, renaming and switching off post where they did.
        $b = $this->item('legacy', 0, 'Legend Red');
        $r = $this->submit($web, $path, $path . '/add', ['list' => self::cw($b)]);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(sprintf(Words::FAMILY['result_added'], 1), $r->text());
        $r = $this->submit($web, $path, $path . '/switch');
        self::assertSame(303, $r->status, $r->describe());
        $offPage = $web->follow($r);
        self::assertSame(1, self::xp($offPage)->query('//div[@class="doc-head"]//h1/span[contains(@class, "chip") and contains(@class, "off")]')->length);
        self::assertSame([Words::FAMILY['switch_on']], self::texts($offPage, '//div[@class="doc-head"]//div[contains(@class, "doc-actions")]/form[@action="' . $path . '/switch"]/button[contains(@class, "primary")]'),
            'switched off: switching it on is the main action');
        // Someone who only looks: no bar.
        $viewer = $this->signIn($this->uiUser('viewer'))->get($path);
        self::assertSame(0, self::xp($viewer)->query('//div[contains(@class, "doc-actions")]')->length);
        self::assertSame(0, self::xp($viewer)->query('//form[@method="post" and contains(@action, "/ui/items/families/")]')->length);
    }

    public function testPackItemsAndPackLinksAreBoardsWhoseRowsOpenTheRecord(): void
    {
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $site = $this->site('vapeandgo', 'off');
        [$box, $shot] = [$this->item('legacy', 2, 'Box 10 x 10ml'), $this->item('legacy', 30, 'Shot 10ml')];
        $r = $this->submit($lead, '/ui/items/packs', '/ui/items/packs', ['pack' => self::cw($box), 'single' => (string) $shot, 'units' => '10']);
        self::assertSame(303, $r->status, $r->describe());
        $list = $lead->follow($r);
        self::assertSame([Words::PACKS['on']], self::assertBoard($list, 'pack items'));
        $xp = self::xp($list);
        self::assertSame(['/ui/items/' . $box], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-on"]//tbody/tr/th/a[contains(@class, "row-link")]')))), 'a row opens the pack item');
        self::assertSame(['/ui/items/' . $shot], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-on"]//tbody/tr/td[contains(@class, "c-next")]/a[@href="/ui/items/' . $shot . '"]')))), 'the single above the row\'s link');
        self::assertSame(1, $xp->query('//div[@id="g-on"]//tbody/tr/td[contains(@class, "c-next")]/form[contains(@action, "/switch")]')->length);
        self::assertSame(['#new'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            array_values(iterator_to_array($xp->query('//div[@id="g-on"]//tr[contains(@class, "grp-add")]//a[contains(@class, "add-item")]')))));
        self::assertSame(0, $xp->query('//details[contains(concat(" ", @class, " "), " how ")]')->length, 'no help fold (the approved board has none)');
        self::assertSame(1, $xp->query('//details[@id="new"]//form[@action="/ui/items/packs"]/fieldset[contains(@class, "doc-grid")]//input[@name="units"]')->length);
        // Pack links: a board of the variants sold as more than one unit, grouped by whether something needs a look; a row opens the variant.
        $this->profiled($site, 'SHOT10', ['product_title' => 'Nic shot 10 pack'], $shot, 10);
        $this->profiled($site, 'SHOT5', ['product_title' => 'Nic shot 5 pack'], $shot, 5);
        $links = $lead->get('/ui/items/packs/links');
        self::assertSame(200, $links->status, $links->describe());
        self::assertSame([Words::PACKS['group_check'], Words::PACKS['group_fine']], self::assertBoard($links, 'pack links', false));
        $lx = self::xp($links);
        self::assertSame(['Nic shot 10 pack'], self::texts($links, '//div[@id="g-check"]//tbody/tr/th/a[contains(@class, "row-link") and starts-with(@href, "/ui/review/listing/")]'));
        self::assertSame([sprintf(Words::PACK_LINK_FLAG['pack_item_exists'], self::cw($box))], self::texts($links, '//div[@id="g-check"]//tbody/tr/td//span[contains(@class, "tag") and contains(@class, "needs")]'));
        self::assertSame(['Nic shot 5 pack'], self::texts($links, '//div[@id="g-fine"]//tbody/tr/th/a[contains(@class, "row-link")]'));
        self::assertSame(2, $lx->query('//main//tbody/tr/td[contains(@class, "c-next")]/a[@href="/ui/items/' . $shot . '"]')->length, 'the item above the row\'s link');

        // Switched off: its own group.
        $id = (int) self::$db->value('SELECT id FROM item_pack');
        $r = $lead->post('/ui/items/packs/' . $id . '/switch', ['csrf' => $this->token($lead), 'on' => '0']);
        self::assertSame(303, $r->status);
        self::assertSame([Words::PACKS['off']], self::assertBoard($lead->follow($r), 'pack items, switched off'));
    }

    public function testBarcodesIsAnInboxWithOneRecordOpen(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $vpg = $this->site('vpg');
        $mine = $this->item('legacy', 2, 'Barcoded');
        $other = $this->item('legacy', 0, 'Other');
        $third = $this->item('legacy', 0, 'Third');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?), ('96385074', ?)", [$mine, $mine]);
        $this->profiled($vpg, 'V1', ['barcodes' => ['4006381333931']], $other);
        $this->profiled($vpg, 'V2', ['barcodes' => ['96385074']], $third);
        (new BarcodeSync(self::$db))->run(Caller::system('sync_barcodes'), true);
        $ids = array_map('intval', array_column(self::$db->all("SELECT id FROM barcode_review WHERE status = 'open' ORDER BY id"), 'id'));
        self::assertCount(2, $ids);

        $q = $web->get('/ui/items/barcodes');
        self::assertSame(200, $q->status, $q->describe());
        $xp = self::xp($q);
        // The toolbar: the barcode search and the Show menu (waiting, decided).
        self::assertSame(1, $xp->query('//form[contains(@class, "toolbar")]//input[@id="tb-q" and @name="barcode"]')->length);
        self::assertSame(['', 'decided'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            array_values(iterator_to_array($xp->query('//form[contains(@class, "toolbar")]/div[@class="tb-state"]/select[@name="show"]/option')))));
        self::assertSame(0, $xp->query('//nav[contains(@class, "show-tabs")]')->length);
        // The list: grouped by why, each row a link that opens it; the open one has no link.
        self::assertSame([Words::BARCODE_REASON['on_another_item']], self::texts($q, '//section[contains(@class, "ap-list")]//div[contains(@class, "ap-group") and contains(@class, "blocked")]/h2/span[@class="ap-gname"]'));
        $rows = $xp->query('//section[contains(@class, "ap-list")]//a[contains(@class, "ap-row")]');
        self::assertSame(2, $rows->length);
        self::assertSame(1, $xp->query('//a[contains(@class, "ap-row") and contains(@class, "sel") and not(@href) and @aria-current="true"]')->length, 'one open');
        $second = $xp->query('//a[contains(@class, "ap-row") and @href]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $second);
        // One record open: its facts and its one decision (the radios, the units, the note; ONE main button naming the form).
        self::assertSame(1, $xp->query('//section[contains(@class, "ap-record")]')->length);
        self::assertSame(1, $xp->query('//section[contains(@class, "ap-record")]//form[@id="ap-answer" and @method="post" and contains(@action, "/decide")]')->length);
        self::assertSame(['keep_holder', 'move', 'unusable'], $q->radios('decision'));
        self::assertSame([Words::BARCODE['save_decision']], self::texts($q, '//section[contains(@class, "ap-record")]//div[@class="ap-actions"]/button[@form="ap-answer" and contains(@class, "primary")]'));
        self::assertStringContainsString(Words::BARCODE['on_product'] . ' ' . self::cw($mine) . ' Barcoded (2 in stock)', $q->text());
        self::assertSame(1, count(self::texts($q, '//section[contains(@class, "ap-record")]//p[@class="ap-next"]')));
        $openId = (int) preg_replace('#^/ui/items/barcodes/(\d+)/decide$#', '$1', (string) $xp->evaluate('string(//form[@id="ap-answer"]/@action)'));
        self::assertSame($ids[0], $openId, 'the first waiting one opens');

        // The other row opens the other record; deciding it comes back to the list, the first one open.
        $page = $web->get((string) parse_url($second->getAttribute('href'), PHP_URL_PATH), self::query($second->getAttribute('href')));
        self::assertSame(200, $page->status, $page->describe());
        $decide = '/ui/items/barcodes/' . $ids[1] . '/decide';
        self::assertTrue($page->hasForm($decide), 'the record the row opened');
        $none = $web->post($decide, $page->form($decide));
        self::assertSame(400, $none->status, 'no decision chosen: ' . $none->describe());
        self::assertTrue($none->hasForm($decide), 'the refused record stays open, with its refusal');
        self::assertSame(1, self::xp($none)->query('//section[contains(@class, "ap-record")]//p[@class="error"]')->length);
        $r = $web->post($decide, ['decision' => 'keep_holder'] + $none->form($decide));
        self::assertSame('/ui/items/barcodes?notice=decided', $r->location(), $r->describe());
        $after = $web->follow($r);
        self::assertTrue($after->hasForm('/ui/items/barcodes/' . $ids[0] . '/decide'), 'the one left opens');
        $decided = $web->get('/ui/items/barcodes', ['show' => 'decided']);
        self::assertSame(0, self::xp($decided)->query('//form[@id="ap-answer"]')->length, 'a decided record has no form');
        self::assertStringContainsString(Words::BARCODE_DECISION['keep_holder'], $decided->text());
    }

    /**
     * Codex's review of d0f98d9, MEDIUM 1 (docs/decisions.md W11.12): a barcode past the list's first BarcodeReviews::LIST_LIMIT, found by its
     * search and refused (no decision chosen), opens again with its refusal, in the list too; it never opens another barcode.
     */
    public function testARefusedDecisionOpensItsOwnRecordPastTheListsLimit(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sku = $this->item('legacy', 0, 'Many packs');
        $limit = BarcodeReviews::LIST_LIMIT;
        $values = [];
        for ($i = 1; $i <= $limit + 1; $i++) {
            $values[] = sprintf("('%d', 'multipack_listing', %d, 'system:sync_barcodes')", 50_000_000 + $i, $sku);
        }
        self::$db->exec('INSERT INTO barcode_review (barcode, reason, claimant_sku_id, opened_actor) VALUES ' . implode(', ', $values));
        $first = (int) self::$db->value("SELECT MIN(id) FROM barcode_review WHERE status = 'open'");
        $last = (int) self::$db->value("SELECT MAX(id) FROM barcode_review WHERE status = 'open'");
        $barcode = (string) (50_000_000 + $limit + 1);
        $decide = '/ui/items/barcodes/' . $last . '/decide';

        $found = $web->get('/ui/items/barcodes', ['barcode' => $barcode]);
        self::assertTrue($found->hasForm($decide), 'its search finds it: ' . $found->describe());
        $none = $web->post($decide, $found->form($decide));
        self::assertSame(400, $none->status, 'no decision chosen: ' . $none->describe());
        self::assertTrue($none->hasForm($decide), 'the refused record is the one open');
        self::assertFalse($none->hasForm('/ui/items/barcodes/' . $first . '/decide'), 'not the first of the list');
        $xp = self::xp($none);
        self::assertSame(1, $xp->query('//section[contains(@class, "ap-record")]//p[@class="error"]')->length, 'its refusal in it');
        self::assertSame(0, $xp->query('//main//p[@class="error" and not(ancestor::section[contains(@class, "ap-record")])]')->length, 'not over the list');
        self::assertSame([$barcode], self::texts($none, '//section[contains(@class, "ap-list")]//a[contains(@class, "ap-row") and contains(@class, "sel")]/span[@class="ap-t"]'),
            'its row in the list, open');
        self::assertSame(1, $xp->query('//form[@id="ap-answer" and @data-dirty="1"]')->length);

        // Decided meanwhile (by someone else): it is not drawn as waiting; its refusal is said over the list.
        self::$db->exec("UPDATE barcode_review SET status = 'decided', decision = 'dismiss', decided_actor = 'test', decided_at = UTC_TIMESTAMP(6) WHERE id = ?", [$last]);
        $closed = $web->post($decide, ['decision' => 'dismiss'] + $found->form($decide));
        self::assertSame(409, $closed->status, $closed->describe());
        self::assertFalse($closed->hasForm($decide), 'a decided record has no form');
        self::assertSame(1, self::xp($closed)->query('//main//p[@class="error" and not(ancestor::section[contains(@class, "ap-record")])]')->length, 'its refusal over the list');
    }

    /**
     * Codex's review of d0f98d9, MEDIUM 2 (W11.13): what a refused form brings back is guarded as typed (data-dirty), and is shown: Families'
     * new family, Pack items' new pack item, a family's rename (More and Rename open) and its pasted list (Add items open). A page drawn
     * fresh, and a refusal that brings nothing typed back, hold nothing unsaved.
     */
    public function testWhatARefusedFormBringsBackIsGuardedAsTyped(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $fam = new Families(self::$db);
        $legend = $fam->create($this->staffUser('buyer'), 'Elux Legend')['id'];
        $fam->create($this->staffUser('buyer'), 'Old range');
        $fam->assign($this->staffUser('buyer'), $this->item('legacy', 1, 'Legend Blue'), $legend, null);

        self::assertSame(0, self::xp($web->get('/ui/items/families'))->query('//form[@data-dirty]')->length, 'drawn fresh: nothing unsaved');
        $r = $this->submit($web, '/ui/items/families', '/ui/items/families', ['name' => 'Elux Legend', 'note' => 'A new note']);
        self::assertSame(409, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[@id="new" and @open]/form[@action="/ui/items/families" and @data-unsaved="1" and @data-dirty="1"]'
            . '//textarea[@name="note" and normalize-space(.)="A new note"]')->length, 'the new family: the note brought back is guarded');
        $r = $this->submit($web, '/ui/items/families', '/ui/items/families', ['name' => '', 'note' => '']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(0, self::xp($r)->query('//form[@data-dirty]')->length, 'nothing typed, nothing unsaved');

        $path = '/ui/items/families/' . $legend;
        self::assertSame(0, self::xp($web->get($path))->query('//form[@data-dirty]')->length, 'the family drawn fresh: nothing unsaved');
        $r = $this->submit($web, $path, $path . '/rename', ['name' => 'Old range']);
        self::assertSame(409, $r->status, $r->describe());
        $xp = self::xp($r);
        self::assertSame(1, $xp->query('//div[@class="doc-head"]//details[contains(@class, "more") and @open]/div/details[contains(@class, "family-rename") and @open]'
            . '//form[@action="' . $path . '/rename" and @data-unsaved="1" and @data-dirty="1"]//input[@name="name" and @value="Old range"]')->length,
            'the rename: More and Rename open, the name brought back guarded');
        self::assertSame(1, $xp->query('//form[@data-dirty]')->length, 'that form alone');
        $r = $this->submit($web, $path, $path . '/add', ['list' => 'CW-999999', 'form_key' => 'not-a-key']);
        self::assertSame(400, $r->status, $r->describe());
        $xp = self::xp($r);
        self::assertSame(1, $xp->query('//details[contains(@class, "family-add") and @open]//form[@action="' . $path . '/add" and @data-unsaved="1" and @data-dirty="1"]'
            . '//textarea[@name="list" and normalize-space(.)="CW-999999"]')->length, 'the pasted list: Add items open, the list brought back guarded');
        self::assertSame(0, $xp->query('//details[contains(@class, "more") and @open]')->length, 'More stays shut');
        self::assertSame(1, $xp->query('//form[@data-dirty]')->length, 'that form alone');

        $lead = $this->signIn($this->uiUser('mapping_lead'));
        self::assertSame(0, self::xp($lead->get('/ui/items/packs'))->query('//form[@data-dirty]')->length, 'pack items drawn fresh: nothing unsaved');
        $r = $this->submit($lead, '/ui/items/packs', '/ui/items/packs', ['pack' => 'CW-999998', 'single' => 'CW-999999', 'units' => '10', 'note' => 'Box of ten']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[@id="new" and @open]/form[@action="/ui/items/packs" and @data-unsaved="1" and @data-dirty="1"]'
            . '//input[@name="units" and @value="10"]')->length, 'the new pack item: what was typed is guarded');
    }

    /**
     * Codex's review of d0f98d9, LOW 1 (W11.14): "Next" names the barcode the save opens. The save goes back to the waiting list (no search,
     * the first one open), so Next is the first one left there once this one is decided: for the second of three, the first; for one found
     * by its search, the list's first all the same.
     */
    public function testNextNamesTheBarcodeTheSaveOpens(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sku = $this->item('legacy', 0, 'Packs of three');
        self::$db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, opened_actor) VALUES ('40000001', 'multipack_listing', ?, 'system:sync_barcodes'), "
            . "('40000002', 'multipack_listing', ?, 'system:sync_barcodes'), ('40000003', 'multipack_listing', ?, 'system:sync_barcodes')", [$sku, $sku, $sku]);
        [$a, $b, $c] = array_map('intval', array_column(self::$db->all("SELECT id FROM barcode_review WHERE status = 'open' ORDER BY id"), 'id'));
        $next = static fn (UiResponse $p): string => self::texts($p, '//section[contains(@class, "ap-record")]//p[@class="ap-next"]')[0] ?? '';

        self::assertSame(sprintf(Words::BARCODE['next_one'], '40000002'), $next($web->get('/ui/items/barcodes')), 'the first open: the second next');
        $page = $web->get('/ui/items/barcodes', ['open' => (string) $b]);
        self::assertSame(sprintf(Words::BARCODE['next_one'], '40000001'), $next($page), 'the second open: the save opens the first');
        self::assertSame(sprintf(Words::BARCODE['next_one'], '40000001'), $next($web->get('/ui/items/barcodes', ['barcode' => '40000003'])),
            'the third found by its search: the save opens the list\'s first');
        $decide = '/ui/items/barcodes/' . $b . '/decide';
        $after = $web->follow($web->post($decide, ['decision' => 'dismiss'] + $page->form($decide)));
        self::assertTrue($after->hasForm('/ui/items/barcodes/' . $a . '/decide'), 'the save opened the one Next named');
        self::assertSame(sprintf(Words::BARCODE['next_one'], '40000003'), $next($after));
        $after = $web->follow($web->post('/ui/items/barcodes/' . $a . '/decide', ['decision' => 'dismiss'] + $after->form('/ui/items/barcodes/' . $a . '/decide')));
        self::assertTrue($after->hasForm('/ui/items/barcodes/' . $c . '/decide'));
        self::assertSame(Words::BARCODE['last_one'], $next($after), 'the last one');
    }

    /**
     * Codex's review of d0f98d9, LOW 2 (W11.15): typed text is typed whatever it says ("on" too): after a refusal, its fold opens with it.
     * The answers "not known" open nothing.
     */
    public function testTypedTextOnOpensItsFold(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/items/new');
        $r = $web->post('/ui/items/new', ['name' => 'Widget', 'barcode' => 'x', 'c_brand' => 'on'] + self::NOT_KNOWN + $page->form('/ui/items/new', true));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(1, self::xp($r)->query('//details[@id="new-legal" and @open]//input[@name="c_brand" and @value="on"]')->length, 'the legal fold open, "on" in it');
        self::assertSame(0, self::xp($r)->query('//details[@id="new-buying" and @open]')->length, 'nothing typed in the buying fold');
        $r = $web->post('/ui/items/new', ['name' => 'Widget', 'barcode' => 'x'] + self::NOT_KNOWN + $page->form('/ui/items/new', true));
        self::assertSame(422, $r->status, $r->describe());
        self::assertSame(0, self::xp($r)->query('//details[@open]')->length, 'nothing typed in either fold: both shut');
    }

    /** @return array<string, string> the query of a link */
    private static function query(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);
        return array_map('strval', $q);
    }
}
