<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
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
        self::assertSame(0, $xp->query('//main//details[contains(@class, "tb-pop")]')->length, "{$what}: no Filter pop-over");
        self::assertSame(0, $xp->query('//main//*[contains(@class, "filters-on") or contains(@class, "show-tabs") or contains(@class, "row-strips")]')->length, "{$what}: the old parts");
        self::assertGreaterThan(0, $xp->query('//main//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//table[contains(@class, "doc-list")]')->length,
            "{$what}: the groups' cards");
        self::assertSame($xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr[not(contains(@class, "grp-add"))]')->length,
            $xp->query('//main//div[contains(@class, "doc-board")]//tbody/tr/td[contains(@class, "doc-st")]')->length, "{$what}: every row's status cell is filled");
        self::assertSame(0, $xp->query('//main//div[contains(@class, "doc-board")]//tbody//span[contains(concat(" ", @class, " "), " chip ")]')->length,
            "{$what}: a status is the solid cell, a small fact a soft tag");
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

        // Plain filter inputs in the one Show row: the card, the kind of product and the four ticks, one Show button; no Clear while nothing is on.
        $row = '//form[contains(@class, "toolbar")]/div[@class="tb-state"]';
        self::assertSame(['state', 'type'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('name'), array_values(iterator_to_array($xp->query($row . '/select')))));
        self::assertSame(['stock', 'warnings', 'blocked', 'discontinued'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($xp->query($row . '/label[contains(@class, "tb-check")]/input[@type="checkbox"]')))));
        self::assertSame([Words::CARDS['stock'], Words::CARDS['warnings'], Words::CARDS['blocked'], Words::CARDS['discontinued']], self::texts($list, $row . '/label[contains(@class, "tb-check")]'));
        self::assertSame(1, $xp->query($row . '/button[@type="submit"]')->length);
        self::assertSame(0, $xp->query('//form[contains(@class, "toolbar")]//a[@href="/ui/items/cards"]')->length, 'nothing on: nothing to clear');
        // Filters on: they show in their own inputs, and ONE Clear link takes every filter and the search off.
        $on = $web->get('/ui/items/cards', ['q' => 'tank', 'state' => 'confirmed', 'type' => 'tank', 'stock' => '1', 'blocked' => '1']);
        $oxp = self::xp($on);
        self::assertSame(['confirmed', 'tank'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            array_values(iterator_to_array($oxp->query($row . '/select/option[@selected]')))));
        self::assertSame(['stock', 'blocked'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            array_values(iterator_to_array($oxp->query($row . '/label/input[@checked]')))));
        self::assertSame('tank', (string) $oxp->evaluate('string(//input[@id="tb-q"]/@value)'));
        self::assertSame([Words::CARDS['clear_filters']], self::texts($on, '//form[contains(@class, "toolbar")]//a[@href="/ui/items/cards"]'));
        self::assertSame(1, $oxp->query('//form[contains(@class, "toolbar")]/div[@class="tb-end"]/a[@href="/ui/items/cards"]')->length, 'at the right');
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
            array_values(iterator_to_array($xp->query('//p[@class="crumbs"]/a')))));
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
        self::assertSame(0, $xp->query('//details[contains(@class, "how")]')->length, 'no help fold (the approved board has none)');
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

    /** @return array<string, string> the query of a link */
    private static function query(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);
        return array_map('strval', $q);
    }
}
