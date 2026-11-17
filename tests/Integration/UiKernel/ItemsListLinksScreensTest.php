<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Catalogue\ItemCardCsv;
use CW\Catalogue\ItemCardList;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Items as ONE list with a small column per store (the owner, 17 Nov 2026, CW chat: "remove and needs a look section. items pages is to see all
 * products in cw properly ... remove the status columns add 3 small extra column with alt, vpg, vbig should show icons just icons to know if it
 * is connected or not"; docs/decisions.md IL1-IL5), through the real /ui kernel as cw_app: each store's cell is its icon only (a tick where one
 * of the store's website products or more is linked to the item, an empty ring where none is), its words the icon's label and hover title;
 * on hold still counts as linked; a suggestion, a website product not matched and a store with no column never do; a merged item's links are
 * the kept item's; ONE read for the icons of a page, however many rows; the CSV says the same as yes / no; the filters still filter.
 */
final class ItemsListLinksScreensTest extends KernelUiTestCase
{
    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /**
     * A row's three store cells, as a person and a screen reader get them: [on/off, the label, the title, the cell's own text].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function cells(UiResponse $page, int $sku): array
    {
        $xp = self::xp($page);
        $row = $xp->query('//table[contains(@class, "item-cards")]/tbody/tr[th/a[@href="/ui/items/' . $sku . '"]]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row, "the row of {$sku}");
        $out = [];
        foreach ($xp->query('td[contains(@class, "c-link")]', $row) ?: [] as $td) {
            $icon = $xp->query('span[contains(@class, "link-ico")][@role="img"]', $td)->item(0);
            self::assertInstanceOf(\DOMElement::class, $icon, 'an icon in every store cell');
            $state = trim(str_replace('link-ico', '', $icon->getAttribute('class')));
            self::assertSame($state === 'on' ? 1 : 0, $xp->query('span[contains(@class, "ico-check")][@aria-hidden="true"]', $icon)->length, 'the tick only where it is connected');
            $out[] = [$state, $icon->getAttribute('aria-label'), $icon->getAttribute('title'), trim($td->textContent)];
        }
        return $out;
    }

    /** @return list<string> the rows' item names, in order */
    private static function names(UiResponse $page): array
    {
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        return array_map(static fn (\DOMNode $a): string => trim($a->textContent),
            array_values(iterator_to_array(self::xp($page)->query('//table[contains(@class, "item-cards")]/tbody/tr/th/a') ?: [])));
    }

    /** The cell of a store that is linked ($n website products there, $held of them on hold) or not, as cells() reads it. @return array{0: string, 1: string, 2: string, 3: string} */
    private static function cell(string $code, int $n, int $held = 0): array
    {
        $site = Words::SITE[$code];
        $label = $n === 0 ? sprintf(Words::ITEM_LINKS['off'], $site)
            : sprintf(Words::ITEM_LINKS[$held === $n ? 'on_hold' : 'on'], $site, $n === 1 ? Words::ITEM_LINKS['one'] : sprintf(Words::ITEM_LINKS['many'], $n));
        return [$n > 0 ? 'on' : 'off', $label, $label, ''];
    }

    public function testEachStoreIsASmallIconOnlyColumnThatSaysWhetherTheItemIsConnectedThere(): void
    {
        $ef = $this->site('electrofag', 'off');
        $vpg = $this->site('vapeandgo', 'shadow');
        $vb = $this->site('vapebig', 'off');
        $proto = $this->site('vpgproto', 'off');
        $both = $this->item('legacy', 30, 'Two stores');
        $nowhere = $this->item('legacy', 20, 'Nowhere yet');
        $held = $this->item('legacy', 10, 'On hold at Alt');
        $suggested = $this->item('legacy', 5, 'Only a suggestion');
        $this->listing($vpg, 'V1', $both);
        $this->listing($vpg, 'V2', $both); // two pages of one store: one icon, the hover says two
        $this->listing($vb, 'B1', $both);
        $this->listing($ef, 'E1', $held, 1, 'quarantined'); // on hold: linked, held off sale (D32)
        $this->listing($ef, 'E2', null); // a website product not matched yet
        $this->queued($vpg, 'V3', 'Key', $suggested); // a suggestion is not a link
        $this->listing($vb, 'B2', null, 1, 'ignored');
        $this->listing($proto, 'P1', $nowhere); // a store with no column on Items

        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/items/cards');
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);
        // The heads: the owner's short names, in his order (Alt is Electrofag), each store's full name the head's title; then On hand.
        self::assertSame([Words::INV_LIST['col_item'], 'Alt', 'VPG', 'VBig', Words::INV_LIST['col_on_hand']],
            array_map(static fn (\DOMNode $th): string => trim($th->textContent), array_values(iterator_to_array($xp->query('//table[contains(@class, "item-cards")]/thead/tr/th')))));
        self::assertSame(['Electrofag', 'Vape and Go', 'Vape Big'], array_map(static fn (\DOMElement $th): string => $th->getAttribute('title'),
            array_values(iterator_to_array($xp->query('//table[contains(@class, "item-cards")]/thead/tr/th[@class="c-link"]')))));
        self::assertSame(['Two stores', 'Nowhere yet', 'On hold at Alt', 'Only a suggestion'], self::names($page), 'most stock first, as before');

        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 2), self::cell('vapebig', 1)], self::cells($page, $both));
        self::assertSame('Vape and Go: connected (2 website products)', self::cells($page, $both)[1][1], 'the words, in full');
        self::assertSame('Electrofag: not connected', self::cells($page, $both)[0][1]);
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 0), self::cell('vapebig', 0)], self::cells($page, $nowhere), 'the proto store has no column');
        self::assertSame([self::cell('electrofag', 1, 1), self::cell('vapeandgo', 0), self::cell('vapebig', 0)], self::cells($page, $held));
        self::assertSame('Electrofag: connected, on hold (1 website product)', self::cells($page, $held)[0][1]);
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 0), self::cell('vapebig', 0)], self::cells($page, $suggested), 'a suggestion is not connected');
        // On a phone each cell says its store (the label before the icon), and every store cell sits above the link that opens the row.
        self::assertSame(['Alt', 'VPG', 'VBig'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-label'),
            array_values(iterator_to_array($xp->query('//table[contains(@class, "item-cards")]/tbody/tr[1]/td[contains(@class, "c-link")]')))));

        // A link made later shows at once (no stored copy): the suggestion decided.
        $this->relink((int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'V3'"), $suggested);
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 1), self::cell('vapebig', 0)], self::cells($web->get('/ui/items/cards'), $suggested));
    }

    public function testAMergedItemIsNotListedAndItsLinksAreTheKeptItems(): void
    {
        $vpg = $this->site('vapeandgo', 'shadow');
        $vb = $this->site('vapebig', 'off');
        $keep = $this->item('legacy', 10, 'Kept pods');
        $fold = $this->item('legacy', 4, 'Folded pods');
        $this->listing($vb, 'B1', $keep);
        $anchor = $this->profiled($vpg, 'V1', ['product_title' => 'Folded pods'], $fold);
        $web = $this->signIn($this->uiUser('buyer'));
        $before = $web->get('/ui/items/cards');
        self::assertSame(['Kept pods', 'Folded pods'], self::names($before));
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 0), self::cell('vapebig', 1)], self::cells($before, $keep));
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 1), self::cell('vapebig', 0)], self::cells($before, $fold));

        // A matching lead joins the two (M10, M31): every link of the item folded away moves to the kept item, with its stock.
        $r = $this->decide($this->staffUser('mapping_lead'), 'merge_skus', $anchor, ['sku_id' => $keep, 'merge_from_sku_id' => $fold]);
        self::assertSame('applied', $r['state']);
        $after = $web->get('/ui/items/cards');
        self::assertSame(['Kept pods'], self::names($after), 'a merged item is left out of the list');
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 1), self::cell('vapebig', 1)], self::cells($after, $keep), 'the kept item has both links');
        self::assertSame(['14'], array_map(static fn (\DOMNode $td): string => trim($td->textContent),
            array_values(iterator_to_array(self::xp($after)->query('//table[contains(@class, "item-cards")]/tbody/tr/td[contains(@class, "num")]')))));
        // Read straight from the service: the merged item has no link of its own left.
        self::assertSame(['electrofag' => ['linked' => 0, 'on_hold' => 0], 'vapeandgo' => ['linked' => 0, 'on_hold' => 0], 'vapebig' => ['linked' => 0, 'on_hold' => 0]],
            (new ItemCardList(self::$db))->links([$fold])[$fold]);
    }

    /**
     * No read per row (the task: "One query for the link icons per page (no N+1)"): a page reads the links of all its items at once, so a page of
     * many rows makes as many reads as a page of one; the CSV reads them per 1,000 items.
     */
    public function testThePageReadsTheIconsOfAllItsRowsAtOnce(): void
    {
        $vpg = $this->site('vapeandgo', 'shadow');
        $ef = $this->site('electrofag', 'off');
        $web = $this->signIn($this->uiUser('buyer'));
        $first = $this->item('legacy', 50, 'First');
        $this->listing($vpg, 'F1', $first);
        $reads = function (KernelBrowser $web): array {
            $seen = [];
            self::$appDb->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen): void {
                $seen[] = (string) preg_replace('/\s+/', ' ', $sql);
            }]]);
            try {
                $page = $web->get('/ui/items/cards');
                self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
            } finally {
                self::$appDb->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
            }
            return [count(array_filter($seen, static fn (string $s): bool => str_contains($s, 'channel_listing'))),
                count(array_filter($seen, static fn (string $s): bool => str_contains($s, 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id WHERE cl.sku_id IN (')))];
        };
        [$all, $icons] = $reads($web);
        self::assertSame(1, $icons, 'one read for the icons');
        for ($i = 0; $i < 12; $i++) {
            $sku = $this->item('legacy', 40 - $i, "More {$i}");
            $this->listing($i % 2 === 0 ? $vpg : $ef, "M{$i}", $sku);
        }
        self::assertSame([$all, 1], $reads($web), 'thirteen rows: the same reads as one');
        self::assertSame(1_000, ItemCardList::LINKS_CHUNK);
        self::assertSame(100, ItemCardList::PAGE, 'a page is within one read');
    }

    /** The CSV (Download these for Excel) says the same as yes / no, in the page's order of the stores, and an export still imports. */
    public function testTheCsvHasTheThreeStoresAsYesOrNo(): void
    {
        self::assertSame(ItemCardList::STORES, array_keys(ItemCardCsv::STORE_COLUMNS), 'the CSV\'s store columns are the page\'s, in its order');
        $names = array_column(ItemCardCsv::EXPORT_COLUMNS, 0);
        self::assertSame(array_values(ItemCardCsv::STORE_COLUMNS), array_slice($names, (int) array_search('stock_held', $names, true) + 1, 3), 'after stock_held');
        foreach (ItemCardCsv::STORE_COLUMNS as $column) {
            self::assertContains($column, ItemCardCsv::IGNORED, "{$column}: read past by the import, never written");
        }
        $vpg = $this->site('vapeandgo', 'shadow');
        $ef = $this->site('electrofag', 'off');
        $a = $this->item('legacy', 9, 'Alpha');
        $b = $this->item('legacy', 3, 'Beta');
        $this->listing($vpg, 'A1', $a);
        $this->listing($ef, 'A2', $a, 1, 'quarantined');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $csv = $web->get('/ui/items/cards.csv');
        self::assertSame(200, $csv->status, $csv->describe());
        $lines = explode("\r\n", trim(substr($csv->body, 3)));
        self::assertStringStartsWith('"code","name","catalogue_brand","stock_held","alt","vpg","vbig","product_type",', $lines[0]);
        self::assertStringStartsWith(sprintf('"CW-%06d","Alpha","",9,"yes","yes","no",', $a), $lines[1], 'on hold is still connected');
        self::assertStringStartsWith(sprintf('"CW-%06d","Beta","",3,"no","no","no",', $b), $lines[2]);
        // The filters still filter the download as the page: holding stock, a CW number.
        $one = $web->get('/ui/items/cards.csv', ['q' => sprintf('CW-%06d', $b)]);
        self::assertCount(2, explode("\r\n", trim(substr($one->body, 3))));
        // The export goes back in unchanged (the three columns are read past: nothing to change, nothing refused).
        $form = $web->get('/ui/items/cards/import');
        $file = tempnam(sys_get_temp_dir(), 'cw-links-');
        self::assertIsString($file);
        file_put_contents($file, $csv->body);
        try {
            $r = $web->postMultipart('/ui/items/cards/import', $form->form('/ui/items/cards/import'), ['file' => ['path' => $file, 'name' => 'items.csv']]);
        } finally {
            unlink($file);
        }
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString('alt, vpg, vbig', $r->text(), 'named as columns read past');
    }

    /** The filters that still make sense stay (docs/decisions.md IL4): holding stock, the search, the kind of product; the page shows the same icons. */
    public function testTheFiltersAndTheSearchStillFilterTheOneList(): void
    {
        $vpg = $this->site('vapeandgo', 'shadow');
        $tank = $this->item('legacy', 7, 'Glass tank');
        $empty = $this->item('legacy', 0, 'Empty kit');
        $this->listing($vpg, 'T1', $tank);
        $web = $this->signIn($this->uiUser('buyer'));
        self::assertSame(['Glass tank'], self::names($web->get('/ui/items/cards', ['stock' => '1'])));
        self::assertSame(['Empty kit'], self::names($web->get('/ui/items/cards', ['q' => 'kit'])));
        self::assertSame(['Glass tank', 'Empty kit'], self::names($web->get('/ui/items/cards', ['type' => 'none'])), 'no card: kind not set');
        $found = $web->get('/ui/items/cards', ['q' => sprintf('CW-%06d', $tank)]);
        self::assertSame(['Glass tank'], self::names($found));
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 1), self::cell('vapebig', 0)], self::cells($found, $tank));
        self::assertSame([Words::INV_LIST['filtered']], array_map(static fn (\DOMNode $n): string => trim($n->textContent),
            array_values(iterator_to_array(self::xp($web->get('/ui/items/cards', ['type' => 'none']))->query('//details[contains(@class, "show-menu")]//*[@aria-current]')))));
        // Paging: 100 a page, most stock first then the oldest; the second page's icons are its own rows'.
        $vb = $this->site('vapebig', 'off');
        $last = $empty;
        for ($i = 0; $i < ItemCardList::PAGE - 1; $i++) {
            $last = self::makeSku("Filler {$i}");
        }
        $this->listing($vb, 'L1', $last);
        $first = $web->get('/ui/items/cards');
        self::assertCount(ItemCardList::PAGE, self::names($first));
        self::assertSame(['Glass tank', 'Empty kit'], array_slice(self::names($first), 0, 2));
        $second = $web->get('/ui/items/cards', ['page' => '2']);
        self::assertSame(['Filler 98'], self::names($second));
        self::assertSame([self::cell('electrofag', 0), self::cell('vapeandgo', 0), self::cell('vapebig', 1)], self::cells($second, $last));
        self::assertSame([sprintf(Words::CARDS['page'], 2, 2)], array_map(static fn (\DOMNode $n): string => trim($n->textContent),
            array_values(iterator_to_array(self::xp($second)->query('//nav[contains(@class, "pager")]/span')))));
    }
}
