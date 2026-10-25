<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Mapping\OneAtATime;
use CW\Output\CsvWriter;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\UiResponse;
use CW\Ui\ByProductContext;
use CW\Ui\Controller\ByProductController;
use CW\Ui\Html;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * Products › By Item (docs/decisions.md U113-U122, M54), through the real /ui kernel as cw_app. In the owner's words (U122) the
 * screen lists warehouse ITEMS, what a store sells is a VARIANT (the "website product" of the other matching screens), and
 * "Product:" names a variant's parent page. Covered: the tab next to Store Products, one row per item and one column per store of
 * the channel table (a fourth store is a fourth column), each cell's state (matched with "+N more variants" and "1 sale = N",
 * waiting for a second OK, suggested with its strength, not on the store), the item's parent product, who sees "Match…", the
 * coverage filters with their counts, the search (also one with no usable word), the pager, the CSV (each variant with its own
 * note), the picker (suggested first; the search of the store's waiting variants, which a plain opening runs only when nothing is
 * suggested; its pager, also for an emptied search; the barcode mark), "Choose" opening the existing variant's page with the item
 * picked, Back from there to the picker as the person left it, the decision made THERE leading back to By Item with its notice,
 * and the odd addresses answered in words.
 *
 * The quick match (the owner's request of 20 Oct 2026: "directly add dropdown with full details, need search all attribute for
 * product"): "Match…" on a cell of a store that has no variant for the item is a fold with a search box. Without app.js its Search
 * opens the picker (a GET form of its own, outside the list's bulk form); with it the fold shows the read-only fragment of
 * `…/map/options` (the suggestions for the item first, then the search, each variant with its details and a "Match to …" form that
 * posts to the variant's own decide route). The search of the store's waiting variants (the picker's and the fold's) reads every
 * word in the names, the brand, the attributes, the web address, the website's numbers, the barcodes and the features.
 */
final class ByProductScreenTest extends KernelUiTestCase
{
    use KeyFixtures;

    /** Three stores named as the channel table names them, each with one variant nobody matched. @return array{a: Caller, b: Caller, c: Caller} */
    private function stores(): array
    {
        $out = [];
        foreach (['a' => ['alt', 'Alt Store'], 'b' => ['vbig', 'Big Store'], 'c' => ['cee', 'Cee Store']] as $key => [$code, $name]) {
            $out[$key] = $this->site($code, 'shadow');
            self::$db->exec('UPDATE channel SET name = ? WHERE code = ?', [$name, $code]);
            $this->profiled($out[$key], 'first-' . $code, ['product_title' => 'First of ' . $name]);
        }
        return $out;
    }

    private function proposalOf(int $listing): int
    {
        return (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]);
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    /** Opens a link of a page (its path and query). */
    private static function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $web->get((string) parse_url($href, PHP_URL_PATH), $query);
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** @return list<string> the list's column heads */
    private static function heads(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        // (the tick column of a person who may decide is not one of the list's own columns: ByItemBulkTest has it)
        return array_map(static fn (\DOMNode $n): string => self::squash($n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "by-product")]/thead/tr/th[not(contains(@class, "c-pick"))]')));
    }

    /** @return list<int> the products of the list's rows, in order */
    private static function ids(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2), iterator_to_array($xp->query('//table[contains(@class, "by-product")]/tbody/tr')));
    }

    /** A product's row: store name (the cell's phone label) => the cell's text. @return array<string, string> */
    private static function cells(UiResponse $r, int $sku): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//tr[@id="p-' . $sku . '"]/td[not(contains(@class, "c-pick"))]') as $td) {
            /** @var \DOMElement $td */
            $out[$td->getAttribute('data-label')] = self::squash($td->textContent);
        }
        return $out;
    }

    /** The links of one cell: link text => href. @return array<string, string> */
    private static function links(UiResponse $r, int $sku, string $store): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//tr[@id="p-' . $sku . '"]/td[@data-label="' . $store . '"]//a') as $a) {
            /** @var \DOMElement $a */
            $out[self::squash($a->textContent)] = $a->getAttribute('href');
        }
        return $out;
    }

    /** @return list<string> the filter entries with their counts ("All 55") */
    private static function pills(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMNode $a): string => self::squash($a->textContent), iterator_to_array($xp->query('//nav[contains(@class, "state-pills")]/a')));
    }

    /** The picker's rows of one list (`suggested` or `found`): [name, option line, state, barcode mark, Choose link]. @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private static function picks(UiResponse $r, string $list): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//table[contains(@class, "pick-' . $list . '")]/tbody/tr') as $tr) {
            $out[] = [self::squash($xp->evaluate('string(th/a[@class="o-name"])', $tr)), self::squash($xp->evaluate('string(th/span[@class="o-sub"][last()])', $tr)),
                self::squash($xp->evaluate('string(td[1])', $tr)), self::squash($xp->evaluate('string(td[2])', $tr)), (string) $xp->evaluate('string(td[last()]/a/@href)', $tr)];
        }
        return $out;
    }

    /** The quick match fold of one cell ("Match…" on a store that has no variant for the item), or null. */
    private static function fold(UiResponse $r, int $sku, string $store): ?\DOMElement
    {
        $n = (new \DOMXPath($r->dom()))->query('//tr[@id="p-' . $sku . '"]/td[@data-label="' . $store . '"]//details[@data-quick-pick]')->item(0);
        return $n instanceof \DOMElement ? $n : null;
    }

    /** The fragment the fold shows (GET of its data-options, with the box's words when the person typed). */
    private static function options(KernelBrowser $web, \DOMElement $fold, ?string $q = null): UiResponse
    {
        return self::open($web, $fold->getAttribute('data-options') . ($q === null ? '' : '&q=' . rawurlencode($q)));
    }

    /**
     * The fragment of one item on one store as app.js asks for it (no q: the opening). A store that suggests a variant for the item
     * makes its cell "Suggested", which has no fold; the route answers for any item and store all the same, as the picker does.
     */
    private static function optionsOf(KernelBrowser $web, int $sku, string $channel, ?string $q = null): UiResponse
    {
        return $web->get("/ui/review/products/{$sku}/map/options", ['channel' => $channel] + ($q === null ? [] : ['q' => $q]));
    }

    /** The fragment's rows, in order: listing id => the list it is in (`suggested` or `found`). @return array<int, string> */
    private static function quickRows(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//div[@data-qp-fragment]//ul[contains(@class, "qp-list")]/li[@data-listing-id]') as $li) {
            /** @var \DOMElement $li */
            $list = $li->parentNode instanceof \DOMElement && str_contains($li->parentNode->getAttribute('class'), 'qp-suggested') ? 'suggested' : 'found';
            $out[(int) $li->getAttribute('data-listing-id')] = $list;
        }
        return $out;
    }

    /** One row of the fragment: its text, its facts (label => value), its Match form's fields (null: no form) and its links. @return array{text: string, facts: array<string, string>, match: ?array<string, string>, links: array<string, string>, li: \DOMElement} */
    private static function quickRow(UiResponse $r, int $listing): array
    {
        $xp = new \DOMXPath($r->dom());
        $li = $xp->query('//div[@data-qp-fragment]//li[@data-listing-id="' . $listing . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $li, "variant {$listing} is a row of the fragment");
        $facts = [];
        foreach ($xp->query('.//dl[contains(@class, "qp-facts")]/dt', $li) as $dt) {
            $dd = $dt->nextSibling;
            while ($dd !== null && !($dd instanceof \DOMElement)) {
                $dd = $dd->nextSibling;
            }
            $facts[self::squash($dt->textContent)] = self::squash($dd?->textContent);
        }
        $links = [];
        foreach ($xp->query('.//a', $li) as $a) {
            /** @var \DOMElement $a */
            $links[self::squash($a->textContent)] = $a->getAttribute('href');
        }
        $form = $xp->query('.//form[contains(@class, "qp-match")]', $li)->item(0);
        return ['text' => self::squash($li->textContent), 'facts' => $facts, 'links' => $links, 'li' => $li,
            'match' => $form instanceof \DOMElement ? $r->form('/ui/review/listing/' . $listing . '/decide') : null];
    }

    public function testEveryWarehouseItemHasOneCellPerStoreAndAFourthStoreIsAFourthColumn(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $charlie = $this->item('legacy', 0, 'Charlie kit');
        $gone = $this->item('legacy', 0, 'Old kit');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$alpha, $gone]);
        self::$db->exec("UPDATE sku SET brand = 'Alpha Co' WHERE id = ?", [$alpha]);
        // Alpha: matched on every store; two variants on Big (the best seller is named, the other is "+1 more variant"); a 10-pack on Cee.
        $a1 = $this->profiled($a, 'A1', ['product_title' => 'Alpha on Alt', 'variant_title' => 'Blue'], $alpha);
        $this->profiled($b, 'B1', ['product_title' => 'Alpha on Big', 'units_30d' => 2], $alpha);
        $b2 = $this->profiled($b, 'B2', ['product_title' => 'Alpha big seller', 'units_30d' => 9, 'units_365d' => 30], $alpha);
        $this->profiled($c, 'C1', ['product_title' => 'Alpha ten pack'], $alpha, 10);
        // Bravo: suggested on Alt, a match waiting for a second OK on Big, nothing on Cee. Charlie: on no store.
        $a2 = $this->queued($a, 'A2', 'Key', $bravo, ['product_title' => 'Bravo on Alt']);
        $b3 = $this->queued($b, 'B3', 'Check', $bravo, ['product_title' => 'Bravo on Big']);
        self::assertSame('pending_second', $this->decide($lead, 'link', $b3, ['sku_id' => $bravo, 'units_per_item' => 6, 'proposal_id' => $this->proposalOf($b3)])['state']);

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame('By Item', self::currentTab($page));
        self::assertSame(['All Products', 'Simple matching', 'Mapping', 'Store Products', 'By Item', 'New Variants', 'Duplicates', 'Families', 'Multipacks'], self::tabLabels($page),
            'next to Store Products');
        self::assertSame(Words::MENU['by_product'], self::squash((new \DOMXPath($page->dom()))->evaluate('string(//main//h1)')));
        self::assertSame([Words::BY_PRODUCT['item'], 'Alt Store', 'Big Store', 'Cee Store', Words::BY_PRODUCT['stores_matched']], self::heads($page),
            'one column per store, by name: the channel table says which');
        self::assertSame([$alpha, $bravo, $charlie], self::ids($page), 'by CW number; a product joined into another one is not listed');

        $xp = new \DOMXPath($page->dom());
        self::assertSame('/ui/items/' . $alpha, $xp->evaluate('string(//tr[@id="p-' . $alpha . '"]/th/a[@class="o-name"]/@href)'));
        self::assertSame('Alpha kit', self::squash($xp->evaluate('string(//tr[@id="p-' . $alpha . '"]/th/a[@class="o-name"])')));
        self::assertSame(self::cw($alpha) . ' · Alpha Co', self::squash($xp->evaluate('string(//tr[@id="p-' . $alpha . '"]/th/span[@class="o-sub"][1])')));
        // Under the item, its parent product: the product name of its best-selling matched variant (U122).
        self::assertSame(Words::say('BY_PRODUCT', 'parent', 'Alpha big seller'), self::squash($xp->evaluate('string(//tr[@id="p-' . $alpha . '"]/th/span[contains(@class, "parent")])')));
        self::assertSame(0, $xp->query('//tr[@id="p-' . $bravo . '"]/th/span[contains(@class, "parent")]')->length, 'no variant is matched to Bravo: no "Product:" line');
        $cells = self::cells($page, $alpha);
        self::assertSame(['Alt Store', 'Big Store', 'Cee Store', Words::BY_PRODUCT['stores_matched']], array_keys($cells), 'on a phone each cell is labelled with its store');
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'] . ' Alpha on Alt Blue', $cells['Alt Store']);
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'] . ' Alpha big seller ' . Words::BY_PRODUCT['more_one'], $cells['Big Store']);
        self::assertStringNotContainsString('1 sale', $cells['Big Store'], 'one for one is not said');
        self::assertStringContainsString(Words::PRODUCT_STORE['matched'] . ' Alpha ten pack ' . Words::saleUses(10), $cells['Cee Store']);
        self::assertSame(Words::say('BY_PRODUCT', 'summary', 3, 3), $cells[Words::BY_PRODUCT['stores_matched']]);
        self::assertSame('/ui/review/listing/' . $a1, self::links($page, $alpha, 'Alt Store')['Alpha on Alt Blue']);
        self::assertSame('/ui/review/listing/' . $b2, self::links($page, $alpha, 'Big Store')['Alpha big seller']);
        self::assertSame('done', $xp->evaluate('string(//tr[@id="p-' . $alpha . '"]/@class)'), 'on every store: the row\'s strip is green');

        $cells = self::cells($page, $bravo);
        self::assertStringStartsWith(Words::PRODUCT_STORE['suggested'] . ' Bravo on Alt ' . Words::BAND['Key'], $cells['Alt Store']);
        self::assertStringStartsWith(Words::PRODUCT_STORE['waiting'] . ' Bravo on Big ' . Words::saleUses(6), $cells['Big Store']);
        self::assertStringStartsWith(Words::PRODUCT_STORE['none'], $cells['Cee Store']);
        self::assertSame(Words::say('BY_PRODUCT', 'summary', 0, 3), $cells[Words::BY_PRODUCT['stores_matched']]);
        self::assertSame('/ui/review/listing/' . $b3, self::links($page, $bravo, 'Big Store')['Bravo on Big']);
        // (the quick match's fold: "Match…", its Search and the way to the matching page; its search box holds no text node)
        self::assertSame(array_fill_keys(['Alt Store', 'Big Store', 'Cee Store'], Words::PRODUCT_STORE['none'] . ' ' . Words::BY_PRODUCT['map'] . ' ' . Words::UI['search']
            . ' ' . Words::BY_PRODUCT['quick_page']), array_slice(self::cells($page, $charlie), 0, 3));
        self::assertSame(['suggested', 'suggested'], [$this->link($a2)['status'], $this->link($b3)['status']], 'looking decides nothing');

        // A fourth row in the channel table is a fourth column, with no code change: also before its product list is loaded.
        $this->site('dee', 'shadow');
        self::$db->exec("UPDATE channel SET name = 'Dee Store' WHERE code = 'dee'");
        $four = $web->get('/ui/review/products');
        self::assertSame([Words::BY_PRODUCT['item'], 'Alt Store', 'Big Store', 'Cee Store', 'Dee Store', Words::BY_PRODUCT['stores_matched']], self::heads($four));
        $cells = self::cells($four, $alpha);
        self::assertStringStartsWith(Words::PRODUCT_STORE['none'], $cells['Dee Store']);
        self::assertSame(Words::say('BY_PRODUCT', 'summary', 3, 4), $cells[Words::BY_PRODUCT['stores_matched']]);
        self::assertSame('needs', (new \DOMXPath($four->dom()))->evaluate('string(//tr[@id="p-' . $alpha . '"]/@class)'));
        self::assertContains(Words::say('BY_PRODUCT', 'missing', 'Dee Store') . ' 3', self::pills($four));
    }

    /**
     * "Product: …" (U122): the product name of the item's matched variant that sold most in a year, on any store (the same number
     * twice: the lowest listing id); left out when no variant is matched, when that name is empty and when it only repeats the
     * item's own name. The CSV has it as the column `product`.
     */
    public function testTheParentProductIsTheBestSellingMatchedVariantsAndIsLeftOutWhenItSaysNothingNew(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $tie = $this->item('legacy', 0, 'Tie kit');
        $this->profiled($a, 'T1', ['product_title' => 'Made first', 'variant_title' => 'Red', 'units_365d' => 5], $tie);
        $this->profiled($b, 'T2', ['product_title' => 'Made second', 'units_365d' => 5], $tie);
        $best = $this->item('legacy', 0, 'Best kit');
        $this->profiled($a, 'T3', ['product_title' => 'Slow page', 'units_30d' => 50, 'units_365d' => 1], $best);
        $this->profiled($b, 'T4', ['product_title' => 'Fast page', 'variant_title' => 'Blue', 'units_365d' => 2], $best);
        $blank = $this->item('legacy', 0, 'Blank kit');
        $this->profiled($a, 'T5', ['product_title' => '', 'variant_title' => 'Only an option', 'units_365d' => 9], $blank);
        $this->profiled($b, 'T6', ['product_title' => 'Not the best seller', 'units_365d' => 1], $blank);
        $same = $this->item('legacy', 0, 'Same Kit');
        $this->profiled($a, 'T7', ['product_title' => ' same kit ', 'units_365d' => 9], $same);
        $unmatched = $this->item('legacy', 0, 'Suggested kit');
        $this->queued($a, 'T8', 'Key', $unmatched, ['product_title' => 'Only suggested', 'units_365d' => 99]);
        // A variant with no profile at all (its list was loaded, its details were not): the item is matched, and has no parent.
        $bare = $this->item('legacy', 0, 'Bare kit');
        $this->listing($a, 'T9', $bare);

        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        $xp = new \DOMXPath($page->dom());
        $parent = static fn (int $sku): string => self::squash($xp->evaluate('string(//tr[@id="p-' . $sku . '"]/th/span[contains(@class, "parent")])'));
        self::assertSame([Words::say('BY_PRODUCT', 'parent', 'Made first'), Words::say('BY_PRODUCT', 'parent', 'Fast page'), '', '', '', ''],
            array_map($parent, [$tie, $best, $blank, $same, $unmatched, $bare]), 'the year decides, not the last 30 days; the product\'s name, not the option\'s');
        self::assertSame(2, $xp->query('//table[contains(@class, "by-product")]//span[contains(@class, "parent")]')->length);
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'], self::cells($page, $bare)['Alt Store']);

        $lines = explode("\r\n", rtrim(substr($web->get('/ui/review/products.csv')->body, strlen(CsvWriter::BOM)), "\r\n"));
        $products = [];
        foreach (array_slice($lines, 1) as $line) {
            $cols = str_getcsv($line, ',', '"', '');
            $products[$cols[1]] = $cols[2];
        }
        self::assertSame(['Tie kit' => 'Made first', 'Best kit' => 'Fast page', 'Blank kit' => '', 'Same Kit' => '', 'Suggested kit' => '', 'Bare kit' => ''], $products);
        self::assertSame('product', str_getcsv($lines[0], ',', '"', '')[2]);
    }

    public function testOnlyPeopleWhoMayDecideSeeMatchAndTheOthersSeeTheState(): void
    {
        ['a' => $a, 'c' => $c] = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $this->profiled($a, 'A1', ['product_title' => 'Alpha on Alt'], $alpha);
        $a2 = $this->queued($a, 'A2', 'Check', $bravo, ['product_title' => 'Bravo on Alt']);
        $this->profiled($c, 'C9', ['product_title' => 'Something on Cee']);

        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Alpha on Alt' => '/ui/review/listing/' . self::$db->value('SELECT id FROM channel_listing WHERE external_variant_id = ?', ['A1']),
            Words::BY_PRODUCT['map_more'] => "/ui/review/products/{$alpha}/map?channel=alt"], self::links($page, $alpha, 'Alt Store'),
            'a matched item can get one more variant of the store');
        self::assertSame([Words::BY_PRODUCT['quick_page'] => "/ui/review/products/{$alpha}/map?channel=cee"], self::links($page, $alpha, 'Cee Store'),
            'the quick match\'s fold keeps the plain way to the picker');
        $review = "/ui/review/listing/{$a2}?via=product&product={$bravo}&channel=alt";
        self::assertSame(['Bravo on Alt' => $review, Words::BY_PRODUCT['review'] => $review, Words::BY_PRODUCT['map_other'] => "/ui/review/products/{$bravo}/map?channel=alt"],
            self::links($page, $bravo, 'Alt Store'), 'a suggestion is answered on its own page, which leads back here');
        self::assertStringNotContainsString('You can look', $page->text());

        // Someone who can only look: the same cells, no "Match…", no "Review"; the picker is not theirs.
        $viewer = $this->signIn($this->uiUser('viewer'));
        $look = $viewer->get('/ui/review/products');
        self::assertSame(200, $look->status, $look->describe());
        self::assertStringContainsString(sprintf(Words::UI['look_only'], Words::whoCan('mapping.decide')), $look->text());
        self::assertSame(self::ids($page), self::ids($look));
        self::assertSame(['Bravo on Alt' => '/ui/review/listing/' . $a2], self::links($look, $bravo, 'Alt Store'));
        self::assertSame([], self::links($look, $alpha, 'Cee Store'));
        self::assertSame(Words::PRODUCT_STORE['none'], self::cells($look, $alpha)['Cee Store']);
        foreach ($look->hrefs() as $href) {
            self::assertStringNotContainsString('/map', $href);
            self::assertStringNotContainsString('via=product', $href);
        }
        $refused = $viewer->get("/ui/review/products/{$alpha}/map", ['channel' => 'cee']);
        self::assertSame([403, 'role_not_allowed'], [$refused->status, $refused->errorCode()]);
        self::assertStringContainsString(Words::ERROR['decide_required'], $refused->text());
        self::assertSame(200, $viewer->get('/ui/review/products.csv')->status, 'the CSV is the list: everyone who sees one sees the other');
        // A variant's page opened with "from By Item" by someone who cannot decide is the ordinary page.
        $plain = $viewer->get('/ui/review/listing/' . $a2, ['via' => 'product', 'product' => (string) $bravo, 'channel' => 'alt']);
        self::assertSame(200, $plain->status);
        self::assertFalse($plain->hasForm('/decide'));
        self::assertStringNotContainsString('/ui/review/products/', implode(' ', $plain->hrefs()));

        // Without the matching screens: none of it.
        $buyer = $this->signIn($this->uiUser('buyer'));
        foreach (['/ui/review/products', '/ui/review/products.csv', "/ui/review/products/{$alpha}/map"] as $path) {
            self::assertSame(403, $buyer->get($path, ['channel' => 'cee'])->status, $path);
        }
        // Admin alone decides nothing (I12): the list, but no "Match…".
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertSame([], self::links($admin->get('/ui/review/products'), $alpha, 'Cee Store'));
        self::assertSame(403, $admin->get("/ui/review/products/{$alpha}/map", ['channel' => 'cee'])->status);
    }

    public function testTheCoverageFiltersTheirCountsTheSearchAndThePager(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo pods');
        $charlie = $this->item('legacy', 0, 'Charlie kit');
        self::$db->exec("UPDATE sku SET brand = 'Zeta', flavour = 'Mango Ice' WHERE id = ?", [$charlie]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('5056168812345', ?)", [$charlie]);
        foreach ([$a, $b, $c] as $i => $site) {
            $this->profiled($site, 'AL' . $i, ['product_title' => 'Alpha ' . $i], $alpha);
        }
        $this->profiled($a, 'CH1', ['product_title' => 'Charlie on Alt'], $charlie);
        $this->queued($b, 'BR1', 'Key', $bravo, ['product_title' => 'Bravo on Big']);
        $fillers = [];
        for ($i = 1; $i <= 52; $i++) {
            $fillers[] = self::makeSku(sprintf('Filler %02d', $i));
        }
        $last = $fillers[51];
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame([Words::BY_PRODUCT['all'] . ' 55', Words::BY_PRODUCT['every'] . ' 1', Words::say('BY_PRODUCT', 'missing', 'Alt Store') . ' 53',
            Words::say('BY_PRODUCT', 'missing', 'Big Store') . ' 54', Words::say('BY_PRODUCT', 'missing', 'Cee Store') . ' 54', Words::BY_PRODUCT['suggested'] . ' 1'],
            self::pills($page), 'each entry with its count; one "Missing on" per store');
        self::assertCount(Queries::PER_PAGE, self::ids($page));
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'shown', 55, 50), $page->text());
        self::assertStringContainsString(Words::say('QUEUE', 'page', 1, 2), $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame('/ui/review/products?page=2', $xp->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'));
        $second = $web->get('/ui/review/products', ['page' => '2']);
        self::assertSame(array_slice($fillers, 47), self::ids($second));
        self::assertSame('/ui/review/products?page=1', (new \DOMXPath($second->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="prev"]/@href)'));

        // The coverage filters.
        self::assertSame([$alpha], self::ids($web->get('/ui/review/products', ['cov' => 'every'])));
        self::assertSame([$bravo], self::ids($web->get('/ui/review/products', ['cov' => 'suggested'])));
        $missing = $web->get('/ui/review/products', ['cov' => 'missing-alt']);
        self::assertSame([$bravo, ...array_slice($fillers, 0, 49)], self::ids($missing), 'Alpha and Charlie are on Alt');
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'shown', 53, 50), $missing->text());
        self::assertSame('/ui/review/products?cov=missing-alt&page=2', (new \DOMXPath($missing->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'),
            'the pager keeps the filter');
        self::assertSame('page', (new \DOMXPath($missing->dom()))->evaluate('string(//nav[contains(@class, "state-pills")]/a[3]/@aria-current)'), 'its entry is marked');
        self::assertSame([$bravo, $charlie, ...array_slice($fillers, 0, 48)], self::ids($web->get('/ui/review/products', ['cov' => 'missing-cee'])));

        // The search: words of the name, brand or flavour, a CW number, a barcode; with a filter too.
        self::assertSame([$bravo], self::ids($web->get('/ui/review/products', ['q' => 'bravo'])));
        self::assertSame([$alpha, $charlie], self::ids($web->get('/ui/review/products', ['q' => 'kit'])));
        self::assertSame([$charlie], self::ids($web->get('/ui/review/products', ['q' => 'mango zeta'])), 'every word, in any of them');
        self::assertSame([$charlie], self::ids($web->get('/ui/review/products', ['q' => self::cw($charlie)])));
        self::assertSame([$charlie], self::ids($web->get('/ui/review/products', ['q' => strtolower(self::cw($charlie))])));
        self::assertSame([$charlie], self::ids($web->get('/ui/review/products', ['q' => '05056168812345'])), 'a scanned barcode, leading zero or not');
        self::assertSame([$alpha], self::ids($web->get('/ui/review/products', ['q' => 'kit', 'cov' => 'every'])));
        $none = $web->get('/ui/review/products', ['q' => 'no such thing', 'cov' => 'every']);
        self::assertSame([], self::ids($none));
        self::assertStringContainsString(Words::BY_PRODUCT['empty_filter'], $none->text());
        $found = $web->get('/ui/review/products', ['q' => 'Filler', 'cov' => 'missing-vbig', 'page' => '2']);
        self::assertSame(array_slice($fillers, 50), self::ids($found));
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'shown', 52, 2), $found->text());
        self::assertSame(['cov' => 'missing-vbig', 'q' => 'Filler'], $found->form('/ui/review/products'), 'the search keeps the filter and what was typed');
        self::assertSame('/ui/review/products.csv?cov=missing-vbig&q=Filler', (new \DOMXPath($found->dom()))->evaluate('string(//form[@class="toolbar"]//a[contains(@href, ".csv")]/@href)'),
            'Export takes the filter and the search');

        // Odd values are answered in words or read as "none", never as a failure.
        foreach ([['page' => '-3'], ['page' => 'abc'], ['page' => '0'], ['page' => '1.5'], ['page' => str_repeat('9', 30)], ['cov' => 'nonsense'], ['at' => 'x'], ['at' => '999999'],
            ['q' => str_repeat('é', 500)], ['notice' => 'decided_link', 'prev' => '<b>'], ['notice' => '<script>']] as $query) {
            $r = $web->get('/ui/review/products', $query);
            self::assertSame(200, $r->status, json_encode($query) . ' ' . $r->describe());
            self::assertContains($alpha, isset($query['q']) ? [$alpha] : self::ids($r), json_encode($query));
        }
        // A search with no usable word (white space that is not ASCII, a form feed, bytes that are not UTF-8) finds nothing: on
        // the list, under every filter, coming back from a decision, and in the CSV. It was a syntax error in the SQL (a 500).
        foreach (["\u{00A0}", "\u{3000}", "\x0C", "\xFF", "caf\xE9", "\u{00A0}\u{3000} \x0C"] as $odd) {
            foreach ([[], ['cov' => 'every'], ['cov' => 'missing-alt'], ['cov' => 'suggested'], ['at' => (string) $alpha], ['page' => '2']] as $with) {
                $r = $web->get('/ui/review/products', ['q' => $odd] + $with);
                self::assertSame(200, $r->status, bin2hex($odd) . ' ' . json_encode($with) . ' ' . $r->describe() . implode("\n", self::$log));
                self::assertSame(isset($with['at']) ? [$alpha] : [], self::ids($r), bin2hex($odd) . ' ' . json_encode($with));
            }
            self::assertStringContainsString(Words::BY_PRODUCT['empty_filter'], $web->get('/ui/review/products', ['q' => $odd])->text(), bin2hex($odd));
            foreach ([[], ['cov' => 'missing-alt'], ['cov' => 'suggested']] as $with) {
                $csv = $web->get('/ui/review/products.csv', ['q' => $odd] + $with);
                self::assertSame([200, 1], [$csv->status, substr_count($csv->body, "\r\n")], bin2hex($odd) . ' ' . json_encode($with) . ': the head, no row');
            }
        }
        $q = new Queries(self::$db);
        self::assertSame([], $q->productIds(null, null, [], "\u{00A0}"));
        self::assertSame([], $q->productIds('suggested', null, [], "caf\xE9", 10, 0));
        self::assertSame(0, $q->productsBefore(null, null, [], "\xFF", $last));
        // A word next to such white space is still a word.
        self::assertSame([$bravo], self::ids($web->get('/ui/review/products', ['q' => "\u{3000}bravo\u{00A0}"])));
        foreach ([['page' => ['2']], ['cov' => ['every']], ['q' => ['a', 'b']], ['at' => [(string) $last]]] as $query) {
            $r = $web->send('GET', '/ui/review/products', $query, []);
            self::assertSame(200, $r->status, json_encode($query) . ' ' . $r->describe());
            self::assertSame($alpha, self::ids($r)[0], 'a list where one value is expected is no value');
        }
        self::assertSame(array_slice($fillers, 47), self::ids($web->get('/ui/review/products', ['page' => '999999999999999999'])), 'past the end: the last page');
        foreach (['/ui/review/products', '/ui/review/products.csv'] as $path) {
            $r = $web->get($path, ['cov' => 'missing-nosuch']);
            self::assertSame([404, 'unknown_store'], [$r->status, $r->errorCode()], $path);
            self::assertStringContainsString(Words::ERROR['unknown_store'], $r->text());
        }

        // Back from a variant's page (`at`): the page that holds the item, its row marked; when the filter no longer
        // holds it, it is shown once, first, and says so.
        $at = $web->get('/ui/review/products', ['at' => (string) $last]);
        self::assertSame(array_slice($fillers, 47), self::ids($at));
        $xp = new \DOMXPath($at->dom());
        // (`here`, not the lists' `picked`: since U124 that one is a ticked row, and app.js sets and clears it with the tick)
        self::assertSame('waiting here', $xp->evaluate('string(//tr[@id="p-' . $last . '"]/@class)'));
        self::assertSame(1, $xp->query('//tr[contains(@class, "here")]')->length);
        self::assertStringContainsString(Words::BY_PRODUCT['back_here'], $at->text());
        self::assertStringNotContainsString(Words::BY_PRODUCT['pinned'], $at->text());
        $pinned = $web->get('/ui/review/products', ['cov' => 'suggested', 'at' => (string) $alpha]);
        self::assertSame([$alpha, $bravo], self::ids($pinned));
        self::assertStringContainsString(Words::BY_PRODUCT['pinned'], $pinned->text());
        $paged = $web->get('/ui/review/products', ['at' => (string) $last, 'page' => '1']);
        self::assertSame([$alpha, 50, 0], [self::ids($paged)[0], count(self::ids($paged)), (new \DOMXPath($paged->dom()))->query('//tr[contains(@class, "here")]')->length],
            'a page asked for is that page, as the list is');
    }

    /**
     * The filter entries' counts are the totals of their lists (a list without a text is not counted again), with a few stores (a
     * product's stores read as bits of one number) and with more stores than a number has bits (read as a list of their ids).
     */
    public function testTheFilterCountsAreTheTotalsOfTheirListsWithAFewStoresAndWithVeryMany(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $skus = [];
        for ($i = 0; $i < 12; $i++) {
            $skus[] = self::makeSku('Kit ' . $i);
        }
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$skus[0], $skus[11]]);
        foreach ($skus as $i => $sku) {
            if ($i === 11) {
                continue;
            }
            if ($i % 2 === 0) {
                $this->profiled($a, 'A' . $i, [], $sku);
            }
            if ($i % 3 === 0) {
                $this->profiled($b, 'B' . $i, [], $sku);
                $this->profiled($b, 'B' . $i . 'x', [], $sku);
            }
            if ($i % 6 === 0) {
                $this->profiled($c, 'C' . $i, [], $sku);
            }
            if ($i % 4 === 1) {
                $this->queued($c, 'S' . $i, 'Check', $sku);
            }
        }
        // A suggestion on a variant with a decision waiting is not "a suggestion waiting"; one of a joined-away item neither.
        $w = $this->queued($a, 'W1', 'Key', $skus[7]);
        $this->decide($lead, 'link', $w, ['sku_id' => $skus[7], 'units_per_item' => 4, 'proposal_id' => $this->proposalOf($w)]);
        $this->queued($a, 'G1', 'Key', $skus[11]);
        $q = new Queries(self::$db);
        $check = function () use ($q, $skus): array {
            $stores = array_map(static fn (array $s): int => (int) $s['id'], $q->everyStore());
            $counts = $q->productCoverage($stores);
            self::assertSame(count($q->productIds(null, null, $stores, '')), $counts['total']);
            self::assertSame(count($q->productIds('every', null, $stores, '')), $counts['every']);
            self::assertSame(count($q->productIds('suggested', null, $stores, '')), $counts['suggested']);
            foreach ($stores as $id) {
                self::assertSame(count($q->productIds('missing', $id, $stores, '')), $counts['total'] - $counts['matched'][$id], "missing on store {$id}");
            }
            self::assertSame($q->productIds(null, null, $stores, '', 4, 3), array_slice($q->productIds(null, null, $stores, ''), 3, 4), 'a page is a slice of the list');
            self::assertSame(5, $q->productsBefore(null, null, $stores, '', $skus[5]));
            self::assertSame(count(array_filter($q->productIds('suggested', null, $stores, ''), static fn (int $id): bool => $id < $skus[9])),
                $q->productsBefore('suggested', null, $stores, '', $skus[9]));
            return $counts;
        };
        $counts = $check();
        self::assertSame([11, 2, 3], [$counts['total'], $counts['every'], $counts['suggested']], '0 and 6 are on all three; 1, 5 and 9 have a suggestion waiting');
        self::assertSame([6, 4, 2], [$counts['matched'][$a->channelId], $counts['matched'][$b->channelId], $counts['matched'][$c->channelId]],
            'a product on two pages of a store is one product');
        self::assertSame([$skus[0], $skus[6]], $q->productIds('every', null, [$c->channelId, $b->channelId, $a->channelId], ''));
        self::assertSame([$skus[1], $skus[5], $skus[9]], $q->productIds('suggested', null, [], ''));
        // 61 more stores: more than a number has bits.
        for ($i = 0; $i < 61; $i++) {
            self::makeChannel('extra' . $i);
        }
        self::assertCount(64, $q->everyStore());
        $many = $check();
        self::assertSame([11, 0, 3], [$many['total'], $many['every'], $many['suggested']], 'nothing is on all 64');
        self::assertSame([6, 4, 2], [$many['matched'][$a->channelId], $many['matched'][$b->channelId], $many['matched'][$c->channelId]]);
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe());
        self::assertCount(66, self::heads($page), 'a column for each of the 64, between the product and the summary');
        self::assertSame(Words::say('BY_PRODUCT', 'summary', 3, 64), self::cells($page, $skus[0])[Words::BY_PRODUCT['stores_matched']]);
    }

    public function testTheCsvHoldsEveryItemOfTheFilterWithOneColumnPerStoreAndEachVariantsOwnNote(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, '=Bravo "kit"');
        self::$db->exec("UPDATE sku SET brand = 'Alpha Co' WHERE id = ?", [$alpha]);
        $this->profiled($a, 'A1', ['product_title' => 'Alpha on Alt'], $alpha);
        $this->profiled($b, 'B1', ['product_title' => 'Alpha on Big'], $alpha);
        $this->profiled($b, 'B2', ['product_title' => 'Alpha on Big too', 'units_365d' => 40], $alpha);
        $this->profiled($c, 'C1', ['product_title' => 'Alpha ten pack'], $alpha, 10);
        $this->queued($a, 'A2', 'Key', $bravo, ['product_title' => 'Bravo on Alt']);
        $b3 = $this->queued($b, 'B3', 'Check', $bravo, ['product_title' => 'Bravo on Big']);
        $this->decide($lead, 'link', $b3, ['sku_id' => $bravo, 'units_per_item' => 6, 'proposal_id' => $this->proposalOf($b3)]);
        // Delta: two variants of ONE store on one item. The older one is a single and on hold; the best seller is a 10-pack.
        $delta = $this->item('legacy', 0, 'Delta kit');
        $held = $this->profiled($a, 'D1', ['product_title' => 'Delta single', 'units_30d' => 2, 'units_365d' => 3], $delta);
        self::$db->exec("UPDATE channel_listing SET status = 'quarantined' WHERE id = ?", [$held]);
        $this->profiled($a, 'D2', ['product_title' => 'Delta ten pack', 'units_30d' => 9, 'units_365d' => 7], $delta, 10);
        // Echo: the same two the other way round (the older one is the pack and the best seller).
        $echo = $this->item('legacy', 0, 'Echo kit');
        $this->profiled($a, 'E1', ['product_title' => 'Echo kit', 'units_30d' => 9, 'units_365d' => 9], $echo, 10);
        $this->profiled($a, 'E2', ['product_title' => 'Echo single', 'units_30d' => 1], $echo);
        for ($i = 1; $i <= 60; $i++) {
            self::makeSku('Filler ' . $i);
        }
        $web = $this->signIn($this->uiUser('viewer'));
        // On the screen a cell names the store's best seller and takes its notes from that one.
        $page = $web->get('/ui/review/products');
        self::assertSame(Words::PRODUCT_STORE['matched'] . ' Delta ten pack ' . Words::BY_PRODUCT['more_one'] . ' ' . Words::saleUses(10), self::cells($page, $delta)['Alt Store']);
        self::assertSame(Words::PRODUCT_STORE['matched'] . ' Echo kit ' . Words::BY_PRODUCT['more_one'] . ' ' . Words::saleUses(10), self::cells($page, $echo)['Alt Store']);

        $r = $web->get('/ui/review/products.csv');
        self::assertSame(200, $r->status, $r->describe());
        self::assertSame(['text/csv; charset=utf-8', 'attachment; filename="products-by-store-' . gmdate('Ymd') . '.csv"; filename*=UTF-8\'\'products-by-store-' . gmdate('Ymd') . '.csv'],
            [$r->header('content-type'), $r->header('content-disposition')]);
        self::assertSame('sandbox', $r->headerValues('content-security-policy')[0] ?? null, 'a download is sandboxed like the others');
        self::assertStringStartsWith(CsvWriter::BOM, $r->body);
        $lines = explode("\r\n", rtrim(substr($r->body, strlen(CsvWriter::BOM)), "\r\n"));
        self::assertCount(65, $lines, 'the head and every item, not only a page of them');
        self::assertSame('"cw_number","name","product","brand","Alt Store","Big Store","Cee Store","stores_matched","stores"', $lines[0]);
        self::assertSame('"' . self::cw($alpha) . '","Alpha kit","Alpha on Big too","Alpha Co","' . Words::PRODUCT_STORE['matched'] . ': A1","' . Words::PRODUCT_STORE['matched']
            . ': B1, B2","' . Words::PRODUCT_STORE['matched'] . ': C1 (' . Words::saleUses(10) . ')",3,3', $lines[1], 'its parent product is its best seller\'s (U122)');
        self::assertSame('"' . self::cw($bravo) . '","\'=Bravo ""kit""","","","' . Words::PRODUCT_STORE['suggested'] . ': A2 (' . Words::BAND['Key'] . ')","'
            . Words::PRODUCT_STORE['waiting'] . ': B3 (' . Words::saleUses(6) . ')","' . Words::PRODUCT_STORE['none'] . '",0,3', $lines[2],
            'a name a spreadsheet would run as a formula is made text');
        // Each variant carries its OWN note beside its option number, whatever the order of the two: the pack note is the pack's,
        // and "On hold" is said of the one on hold (the screen says only the best seller's).
        $none = '"' . Words::PRODUCT_STORE['none'] . '"';
        self::assertSame('"' . self::cw($delta) . '","Delta kit","Delta ten pack","","' . Words::PRODUCT_STORE['matched'] . ': D1 (' . Words::BY_PRODUCT['on_hold'] . '), D2 ('
            . Words::saleUses(10) . ')",' . $none . ',' . $none . ',1,3', $lines[3]);
        self::assertSame('"' . self::cw($echo) . '","Echo kit","","","' . Words::PRODUCT_STORE['matched'] . ': E1 (' . Words::saleUses(10) . '), E2",' . $none . ',' . $none . ',1,3',
            $lines[4], 'a parent product named like the item itself is not repeated');
        self::assertStringEndsWith('"","",' . $none . ',' . $none . ',' . $none . ',0,3', $lines[64]);

        $filtered = $web->get('/ui/review/products.csv', ['cov' => 'every']);
        self::assertSame(2, substr_count($filtered->body, "\r\n"), 'the filter\'s products only');
        self::assertStringContainsString(self::cw($alpha), $filtered->body);
        self::assertSame(2, substr_count($web->get('/ui/review/products.csv', ['q' => 'alpha'])->body, "\r\n"), 'and the search\'s');
    }

    public function testThePickerListsTheSuggestionsFirstThenTheStoresWaitingVariantsBySearch(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $sku = $this->item('legacy', 0, 'Elux Legend 3500 Blue Razz 20mg');
        $other = $this->item('legacy', 0, 'Another product');
        self::$db->exec("UPDATE sku SET brand = 'Elux', line = 'Legend', flavour = 'Blue Razz', strength_mg = 20, volume_ml = 2 WHERE id = ?", [$sku]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('5056168812345', ?)", [$sku]);
        // Alt: two suggestions for it (the strong one has its barcode with a leading zero), two waiting products the words fit (one with
        // its barcode, one with another), one the words do not fit, one suggested for another product, one matched, one ignored, one
        // with a decision waiting. Big: one the words fit, on another store.
        $likely = $this->queued($a, 'S1', 'Check', $sku, ['product_title' => 'Elux Blue Razz likely', 'units_30d' => 1]);
        $strong = $this->queued($a, 'S2', 'Key', $sku, ['product_title' => 'Elux Blue Razz strong', 'units_30d' => 2, 'barcodes' => ['05056168812345']]);
        $same = $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'brand' => 'Elux Vapes', 'units_30d' => 7, 'barcodes' => ['5056168812345']]);
        $differs = $this->profiled($a, 'U2', ['product_title' => 'Blue Razz Ice', 'brand' => 'ELUX', 'units_30d' => 9, 'barcodes' => ['5000000000017']]);
        $unrelated = $this->profiled($a, 'U3', ['product_title' => 'Something else', 'units_30d' => 50]);
        $elsewhere = $this->queued($a, 'U4', 'Check', $other, ['product_title' => 'Elux Blue Razz for another', 'units_30d' => 3]);
        $this->profiled($a, 'M1', ['product_title' => 'Elux Blue Razz matched', 'units_30d' => 99], $other);
        $ignored = $this->profiled($a, 'I1', ['product_title' => 'Elux Blue Razz ignored', 'units_30d' => 98]);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'placeholder']);
        $waiting = $this->profiled($a, 'W1', ['product_title' => 'Elux Blue Razz waiting', 'units_30d' => 97]);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $other, 'units_per_item' => 5]);
        $onBig = $this->profiled($b, 'O1', ['product_title' => 'Elux Blue Razz on Big', 'units_30d' => 96]);
        self::$db->exec('UPDATE listing_profile SET price = 4.99 WHERE listing_id = ?', [$same]);
        $web = $this->signIn($this->uiUser('mapper'));
        $choose = static fn (int $listing): string => "/ui/review/listing/{$listing}?pick={$sku}&via=product&product={$sku}&channel=alt";

        $page = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame('By Item', self::currentTab($page));
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::say('BY_PRODUCT', 'pick_title', self::cw($sku), 'Alt Store'), self::squash($xp->evaluate('string(//main//h1)')));
        self::assertSame('/ui/review/products?at=' . $sku . '#p-' . $sku, $xp->evaluate('string(//p[@class="crumbs"]/a/@href)'), 'back to the list, at the product');
        $facts = self::squash($xp->evaluate('string(//section[@aria-labelledby="facts-h"])'));
        foreach ([self::cw($sku) . ' Elux Legend 3500 Blue Razz 20mg', Words::FIELD['brand'] . 'Elux', Words::FIELD['line'] . 'Legend', Words::FIELD['flavour'] . 'Blue Razz',
            Words::FIELD['strength_mg'] . '20', Words::FIELD['volume_ml'] . '2', Words::FIELD['barcodes'] . '5056168812345',
            // what the store has for it now: its two suggestions
            Words::say('BY_PRODUCT', 'pick_now', 'Alt Store') . ' ' . Words::PRODUCT_STORE['suggested'] . ' Elux Blue Razz strong ' . Words::BY_PRODUCT['more_one']] as $fact) {
            self::assertStringContainsString($fact, $facts);
        }
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'pick_now', 'Big Store') . ' ' . Words::BY_PRODUCT['pick_now_none'],
            $web->get("/ui/review/products/{$sku}/map", ['channel' => 'vbig'])->text());
        self::assertSame('/ui/items/' . $sku, $xp->evaluate('string(//section[@aria-labelledby="facts-h"]//a/@href)'));
        self::assertSame(Words::BY_PRODUCT['pick_suggested'], self::squash($xp->evaluate('string(//h2[@id="suggested-h"])')));
        self::assertSame('Elux Blue Razz', $page->form('/map')['q'], 'the first search: its brand and flavour, not its strength');
        self::assertSame([
            ['Elux Blue Razz strong', Words::say('QUEUE', 'option', 'S2'), Words::BAND['Key'], Words::BY_PRODUCT['pick_barcode_same'], $choose($strong)],
            ['Elux Blue Razz likely', Words::say('QUEUE', 'option', 'S1'), Words::BAND['Check'], Words::BY_PRODUCT['pick_barcode_unknown'], $choose($likely)],
        ], self::picks($page, 'suggested'), 'the suggestions for THIS item first, the strongest first');
        // With suggestions on the page, a plain opening does NOT run the pre-filled search (it would read the profile of every
        // waiting variant of the store): the box is filled in and a line says to press Search.
        self::assertSame([], self::picks($page, 'found'));
        self::assertStringContainsString(Words::BY_PRODUCT['pick_search_first'], $page->text());
        self::assertStringNotContainsString(Words::BY_PRODUCT['pick_found_no_more'], $page->text());
        self::assertSame(Words::say('BY_PRODUCT', 'pick_search', 'Alt Store'), self::squash($xp->evaluate('string(//h2[@id="found-h"])')));
        self::assertSame(Words::UI['search'], self::squash($xp->evaluate('string(//form[contains(@action, "/map")]//button)')));
        self::assertSame(0, $xp->query('//nav[@class="pager"]')->length);
        self::assertStringContainsString(Words::BY_PRODUCT['pick_next_step'], $page->text());
        self::assertFalse($page->hasForm('/decide'), 'nothing is decided here');
        self::assertSame(0, $xp->query('//form[@method="post"][not(contains(@action, "/ui/logout"))]')->length);
        // Pressing Search sends those words: then the store's variants that still wait for a match and have every word, best
        // sellers first: none matched, ignored, waiting for a second OK, of another store, or listed above already. "Choose" now
        // carries the person's search (Back from the variant's page returns to it).
        $searched = $web->get("/ui/review/products/{$sku}/map", $page->form('/map'));
        self::assertSame(200, $searched->status, $searched->describe() . implode("\n", self::$log));
        $mine = '&pq=Elux%20Blue%20Razz';
        self::assertSame([
            ['Blue Razz Ice', 'ELUX · ' . Words::say('QUEUE', 'option', 'U2'), Words::STORE_STATE['not_matched'], Words::BY_PRODUCT['pick_barcode_differs'], $choose($differs) . $mine],
            ['Elux Legend', 'Elux Vapes · ' . Words::say('QUEUE', 'option', 'U1'), Words::STORE_STATE['not_matched'], Words::BY_PRODUCT['pick_barcode_same'], $choose($same) . $mine],
            ['Elux Blue Razz for another', Words::say('QUEUE', 'option', 'U4'), Words::STORE_STATE['suggested'], Words::BY_PRODUCT['pick_barcode_unknown'], $choose($elsewhere) . $mine],
        ], self::picks($searched, 'found'));
        self::assertSame($choose($strong) . $mine, self::picks($searched, 'suggested')[0][4]);
        self::assertStringNotContainsString(Words::BY_PRODUCT['pick_search_first'], $searched->text());
        $sxp = new \DOMXPath($searched->dom());
        self::assertStringContainsString('£4.99', self::squash($sxp->evaluate('string(//table[contains(@class, "pick-found")]/tbody/tr[2])')));
        // A row names the variant's product AND its own option name.
        self::assertSame(['Elux Legend', 'Blue Razz'], [self::squash($sxp->evaluate('string(//table[contains(@class, "pick-found")]/tbody/tr[2]/th/a)')),
            self::squash($sxp->evaluate('string(//table[contains(@class, "pick-found")]/tbody/tr[2]/th/span[@class="o-sub"][1])'))]);
        self::assertSame(Words::BY_PRODUCT['pick_variant'], self::squash($sxp->evaluate('string(//table[contains(@class, "pick-found")]/thead/tr/th[1])')));

        // The person's own search: other words, an option number, a barcode, nothing (every waiting variant, best sellers first).
        $search = static fn (string $q): array => array_column(self::picks($web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => $q]), 'found'), 0);
        self::assertSame(['Something else'], $search('else something'));
        self::assertSame(['Elux Legend'], $search('U1'));
        self::assertSame(['Elux Legend'], $search('5056168812345'), 'the strong suggestion has it too, and is listed above');
        self::assertSame(['Something else', 'Blue Razz Ice', 'Elux Legend', 'Elux Blue Razz for another', 'First of Alt Store'], $search(''));
        self::assertSame([], $search('zzz'));
        self::assertStringContainsString(Words::BY_PRODUCT['pick_found_no_more'], $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => 'zzz'])->text());
        self::assertStringContainsString(Words::BY_PRODUCT['pick_found_none'], $web->get("/ui/review/products/{$sku}/map", ['channel' => 'vbig', 'q' => 'zzz'])->text());
        self::assertSame(['Elux Blue Razz on Big', 'First of Big Store'], array_column(self::picks($web->get("/ui/review/products/{$sku}/map", ['channel' => 'vbig', 'q' => '']), 'found'), 0));
        // A search with no usable word finds nothing here too (never a failure), also as the list's own search carried along.
        foreach (["\u{00A0}", "\u{3000}", "\x0C", "\xFF", "caf\xE9"] as $odd) {
            $r = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => $odd, 'lq' => $odd]);
            self::assertSame([200, []], [$r->status, self::picks($r, 'found')], bin2hex($odd) . ' ' . $r->describe() . implode("\n", self::$log));
            self::assertCount(2, self::picks($r, 'suggested'), bin2hex($odd));
        }
        // Nothing is suggested for it on Big: there a plain opening runs the first search itself.
        $big = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'vbig']);
        self::assertSame([], self::picks($big, 'suggested'));
        self::assertStringContainsString(Words::BY_PRODUCT['pick_suggested_none'], $big->text());
        self::assertSame([['Elux Blue Razz on Big', Words::say('QUEUE', 'option', 'O1'), Words::STORE_STATE['not_matched'], Words::BY_PRODUCT['pick_barcode_unknown'],
            "/ui/review/listing/{$onBig}?pick={$sku}&via=product&product={$sku}&channel=vbig"]], self::picks($big, 'found'));
        self::assertStringNotContainsString(Words::BY_PRODUCT['pick_search_first'], $big->text());

        // A small page: 20, then Next. The links name the search they page through.
        for ($i = 1; $i <= 25; $i++) {
            $this->profiled($a, 'F' . $i, ['product_title' => sprintf('Pager item %02d', $i), 'units_30d' => 100 - $i]);
        }
        $href = static fn (UiResponse $r, string $rel): string => (string) (new \DOMXPath($r->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="' . $rel . '"]/@href)');
        $crumb = static fn (UiResponse $r): string => (string) (new \DOMXPath($r->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)');
        $first = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => 'pager item']);
        self::assertCount(Queries::PICK_PER_PAGE, self::picks($first, 'found'));
        self::assertSame(['', "/ui/review/products/{$sku}/map?channel=alt&q=pager%20item&page=2"], [$href($first, 'prev'), $href($first, 'next')]);
        $next = self::open($web, $href($first, 'next'));
        self::assertSame(['Pager item 21', 'Pager item 22', 'Pager item 23', 'Pager item 24', 'Pager item 25'], array_column(self::picks($next, 'found'), 0));
        self::assertSame(["/ui/review/products/{$sku}/map?channel=alt&q=pager%20item", ''], [$href($next, 'prev'), $href($next, 'next')]);
        // An EMPTIED search (every waiting variant of the store) stays that on Next and Previous: the links carry `q=` (without
        // it page 2 fell back to the first search and showed another, narrower list).
        $all = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => '']);
        self::assertSame(array_map(static fn (int $i): string => sprintf('Pager item %02d', $i), range(1, 20)), array_column(self::picks($all, 'found'), 0));
        self::assertSame(['', "/ui/review/products/{$sku}/map?channel=alt&q=&page=2"], [$href($all, 'prev'), $href($all, 'next')]);
        $second = self::open($web, $href($all, 'next'));
        self::assertSame(['Pager item 21', 'Pager item 22', 'Pager item 23', 'Pager item 24', 'Pager item 25', 'Something else', 'Blue Razz Ice', 'Elux Legend',
            'Elux Blue Razz for another', 'First of Alt Store'], array_column(self::picks($second, 'found'), 0), 'rows 21 onward of the whole store');
        self::assertSame('', $second->form('/map')['q'] ?? '', 'the box stays empty');
        self::assertSame(["/ui/review/products/{$sku}/map?channel=alt&q=", ''], [$href($second, 'prev'), $href($second, 'next')]);
        self::assertSame('Pager item 01', self::picks(self::open($web, $href($second, 'prev')), 'found')[0][0]);
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&cov=missing-alt&lq=elux&q=&page=2",
            $href($web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'cov' => 'missing-alt', 'lq' => 'elux', 'q' => '']), 'next'), 'with the list\'s filter and search');

        // Back from the variant's page is the picker as the person left it: their search and their page (U119), also an emptied
        // search. "Leave it and go back" is the same link. The picker's first search on its first page needs neither.
        $pick = self::picks($next, 'found')[0][4];
        self::assertSame('/ui/review/listing/' . self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'F21'")
            . "?pick={$sku}&via=product&product={$sku}&channel=alt&pq=pager%20item&pp=2", $pick);
        $variant = self::open($web, $pick);
        self::assertSame(200, $variant->status, $variant->describe() . implode("\n", self::$log));
        $back = "/ui/review/products/{$sku}/map?channel=alt&q=pager%20item&page=2";
        self::assertSame($back, $crumb($variant));
        self::assertGreaterThanOrEqual(2, count(array_keys($variant->hrefs(), $back, true)), 'the crumb and "leave it and go back"');
        self::assertSame(['pager item', '2'], [$variant->form('/decide')['pq'], $variant->form('/decide')['pp']], 'a refused answer keeps them as well');
        self::assertSame(['Pager item 21', 'Pager item 22', 'Pager item 23', 'Pager item 24', 'Pager item 25'], array_column(self::picks(self::open($web, $back), 'found'), 0));
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&q=&page=2", $crumb(self::open($web, self::picks($second, 'found')[0][4])), 'an emptied search, its second page');
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&q=", $crumb(self::open($web, self::picks($all, 'found')[0][4])));
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt", $crumb(self::open($web, self::picks($page, 'suggested')[0][4])));
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&cov=missing-alt&lq=elux&q=zz&page=3",
            $crumb($web->get('/ui/review/listing/' . $same, ['pick' => (string) $sku, 'via' => 'product', 'product' => (string) $sku, 'channel' => 'alt', 'cov' => 'missing-alt',
                'lq' => 'elux', 'pq' => ' zz ', 'pp' => '3'])), 'with the list\'s filter and search');
        // Crafted values are no search and no page: the picker's first page.
        foreach ([['pp' => '0'], ['pp' => '-2'], ['pp' => 'x'], ['pp' => '1'], ['pp' => str_repeat('9', 30)]] as $crafted) {
            self::assertSame("/ui/review/products/{$sku}/map?channel=alt",
                $crumb($web->get('/ui/review/listing/' . $same, ['pick' => (string) $sku, 'via' => 'product', 'product' => (string) $sku, 'channel' => 'alt'] + $crafted)), json_encode($crafted));
        }
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&q=" . str_repeat('%C3%A9', 100) . '&page=7',
            $crumb($web->get('/ui/review/listing/' . $same, ['pick' => (string) $sku, 'via' => 'product', 'product' => (string) $sku, 'channel' => 'alt',
                'pq' => str_repeat('é', 500), 'pp' => '7'])), 'a long text is cut to the search box\'s 100');
        self::assertSame(200, $web->send('GET', '/ui/review/listing/' . $same, ['pick' => (string) $sku, 'via' => 'product', 'product' => (string) $sku, 'channel' => 'alt',
            'pq' => ['a'], 'pp' => ['2']], [])->status, 'a list is no value');

        // The list's filter and search travel with the person, so "back" is where they were.
        $kept = $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'cov' => 'missing-alt', 'lq' => 'elux']);
        self::assertSame('/ui/review/products?cov=missing-alt&q=elux&at=' . $sku . '#p-' . $sku, (new \DOMXPath($kept->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        self::assertSame($choose($strong) . '&cov=missing-alt&lq=elux', self::picks($kept, 'suggested')[0][4]);
        self::assertSame(['channel' => 'alt', 'cov' => 'missing-alt', 'lq' => 'elux', 'q' => 'Elux Blue Razz'], $kept->form('/map'));

        // A product that does not exist or was joined into another one, a store that is not one, odd values: in words, never a failure.
        $gone = $this->item('legacy', 0, 'Joined away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $gone]);
        $r = $web->get('/ui/review/products/999999/map', ['channel' => 'alt']);
        self::assertSame([404, 'unknown_item'], [$r->status, $r->errorCode()]);
        $r = $web->get("/ui/review/products/{$gone}/map", ['channel' => 'alt']);
        self::assertSame([404, 'item_joined'], [$r->status, $r->errorCode()]);
        self::assertStringContainsString(Words::ERROR['item_joined'], $r->text());
        self::assertContains('/ui/review/products?at=' . $sku . '#p-' . $sku, $r->hrefs(), 'with the way to the product it was joined into');
        foreach ([['channel' => 'nosuch'], [], ['channel' => ''], ['channel' => 'ALT'], ['channel' => "alt' OR 1=1"]] as $query) {
            $r = $web->get("/ui/review/products/{$sku}/map", $query);
            self::assertSame([404, 'unknown_store'], [$r->status, $r->errorCode()], json_encode($query));
            self::assertStringContainsString(Words::ERROR['unknown_store'], $r->text());
        }
        self::assertSame(404, $web->send('GET', "/ui/review/products/{$sku}/map", ['channel' => ['alt']], [])->status, 'a list is not a store');
        foreach (['/ui/review/products/0/map', '/ui/review/products/-1/map', '/ui/review/products/abc/map', '/ui/review/products/1.5/map',
            '/ui/review/products/' . str_repeat('9', 30) . '/map'] as $path) {
            self::assertSame(404, $web->get($path, ['channel' => 'alt'])->status, $path);
        }
        foreach ([['page' => '-1'], ['page' => 'x'], ['page' => str_repeat('9', 18)], ['q' => str_repeat('%_', 300)], ['cov' => 'missing-nosuch'], ['lq' => str_repeat('é', 500)],
            ['q' => '', 'page' => str_repeat('9', 18)], ['q' => '', 'page' => '0']] as $query) {
            self::assertSame(200, $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt'] + $query)->status, json_encode($query));
        }
        self::assertSame(200, $web->send('GET', "/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => ['a'], 'page' => ['2'], 'cov' => ['x'], 'lq' => ['y']], [])->status);
        self::assertSame(405, $web->post("/ui/review/products/{$sku}/map", ['csrf' => $this->token($web), 'channel' => 'alt'])->status, 'the picker only reads');
    }

    public function testChooseOpensTheVariantsPageAndTheDecisionMadeThereLeadsBackWithItsNotice(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $one = $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 9]);
        $pack = $this->profiled($a, 'U2', ['product_title' => 'Elux Legend Blue Razz box of 10', 'units_30d' => 5]);
        $two = $this->profiled($a, 'U3', ['product_title' => 'Elux Legend Blue Razz second page', 'units_30d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));
        $code = self::cw($sku);

        // From the list: "Match…" on the cell of the store where it is missing, then "Choose".
        $list = $web->get('/ui/review/products');
        $picker = self::open($web, self::links($list, $sku, 'Alt Store')[Words::BY_PRODUCT['quick_page']]);
        self::assertSame(200, $picker->status, $picker->describe());
        $choose = array_column(self::picks($picker, 'found'), 4, 0);
        self::assertSame("/ui/review/listing/{$one}?pick={$sku}&via=product&product={$sku}&channel=alt", $choose['Elux Legend']);
        $page = self::open($web, $choose['Elux Legend']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame('Mapping', self::currentTab($page), 'the existing page, where it always was');
        self::assertStringContainsString(Words::LISTING['picked'], $page->text());
        self::assertStringContainsString(Words::say('LISTING', 'yes', $code), $page->text());
        $xp = new \DOMXPath($page->dom());
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt", $xp->evaluate('string(//p[@class="crumbs"]/a/@href)'), 'Back is the picker');
        self::assertSame(Words::say('BY_PRODUCT', 'pick_crumb', 'Alt Store'), self::squash($xp->evaluate('string(//p[@class="crumbs"]/a)')));
        $form = $page->form('/decide');
        self::assertSame([(string) $sku, 'product', (string) $sku, 'alt', '1'], [$form['sku_id'], $form['via'], $form['product'], $form['channel'], $form['units_per_item']]);
        self::assertArrayNotHasKey('action', $form, 'nothing is preselected: the person says yes');
        self::assertSame('unmapped', $this->link($one)['status'], 'choosing decided nothing');

        // A refused answer stays on the page with its words, and still knows where it came from.
        $stale = $web->post("/ui/review/listing/{$one}/decide", ['action' => 'link', 'expected_map_version' => '7'] + $form);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['map_version_conflict'], $stale->text());
        self::assertSame(['product', (string) $sku, 'alt'], [$stale->form('/decide')['via'], $stale->form('/decide')['product'], $stale->form('/decide')['channel']]);
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt", (new \DOMXPath($stale->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        // ... and where in the picker: the search and page it was opened with are still its Back after a refused answer.
        $again = $web->post("/ui/review/listing/{$one}/decide", ['action' => 'link', 'expected_map_version' => '7', 'pq' => ' ', 'pp' => '4'] + $form);
        self::assertSame([409, "/ui/review/products/{$sku}/map?channel=alt&q=&page=4"], [$again->status, (new \DOMXPath($again->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)')]);
        self::assertSame([' ', '4'], [$again->form('/decide')['pq'], $again->form('/decide')['pp']]);

        // "Yes, same product": DecisionService matches it, and the person lands on By Item at that product.
        $r = $web->post("/ui/review/listing/{$one}/decide", ['action' => 'link'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame("/ui/review/products?at={$sku}&notice=decided_link&prev={$one}#p-{$sku}", $r->location());
        self::assertSame([$sku, 1, 'mapped'], [(int) $this->link($one)['sku_id'], (int) $this->link($one)['units_per_item'], $this->link($one)['status']]);
        $back = $web->follow($r);
        self::assertSame(200, $back->status, $back->describe());
        self::assertSame('By Item', self::currentTab($back));
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', '"Elux Legend Blue Razz"', $code), $back->text());
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'] . ' Elux Legend Blue Razz', self::cells($back, $sku)['Alt Store']);
        self::assertStringContainsString('here', (new \DOMXPath($back->dom()))->evaluate('string(//tr[@id="p-' . $sku . '"]/@class)'));

        // One sale of the box uses 10: it is sent to Second approval, and By Item says so.
        $more = self::open($web, self::links($back, $sku, 'Alt Store')[Words::BY_PRODUCT['map_more']]);
        self::assertStringContainsString(Words::BY_PRODUCT['pick_now_more'], $more->text());
        self::assertSame(['Elux Legend Blue Razz box of 10', 'Elux Legend Blue Razz second page'], array_column(self::picks($more, 'found'), 0), 'the matched one is not offered again');
        $form = self::open($web, array_column(self::picks($more, 'found'), 4, 0)['Elux Legend Blue Razz box of 10'])->form('/decide');
        $r = $web->post("/ui/review/listing/{$pack}/decide", ['action' => 'link', 'units_per_item' => '10'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame("/ui/review/products?at={$sku}&notice=pending_second&prev={$pack}#p-{$sku}", $r->location());
        self::assertSame('unmapped', $this->link($pack)['status'], 'not live: it waits for a matching lead');
        $back = $web->follow($r);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'pending_second', '"Elux Legend Blue Razz box of 10"'), $back->text());
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'also_waiting', 1), self::cells($back, $sku)['Alt Store']);

        // A second page of the same item on the store: matched the same way, and the cell says "+1 more variant". The picker's own
        // search and page come along for Back; the redirect after the decision goes to the LIST and leaves them out.
        $form = self::open($web, "/ui/review/listing/{$two}?pick={$sku}&via=product&product={$sku}&channel=alt&cov=missing-alt&lq=elux&pq=second%20page&pp=3")->form('/decide');
        self::assertSame(['missing-alt', 'elux', 'second page', '3'], [$form['cov'], $form['lq'], $form['pq'], $form['pp']]);
        $r = $web->post("/ui/review/listing/{$two}/decide", ['action' => 'link'] + $form);
        self::assertSame("/ui/review/products?cov=missing-alt&q=elux&at={$sku}&notice=decided_link&prev={$two}#p-{$sku}", $r->location(), 'back in the list as the person left it');
        $back = $web->follow($r);
        self::assertSame([$sku], self::ids($back), 'it is on Alt now, so "Missing on Alt" no longer holds it: it is shown once, first');
        self::assertStringContainsString(Words::BY_PRODUCT['pinned'], $back->text());
        self::assertStringContainsString(Words::BY_PRODUCT['more_one'], self::cells($back, $sku)['Alt Store']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE sku_id = ? AND status = 'mapped'", [$sku]));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action = 'link'"), 'three decisions, each made by DecisionService on its page');
    }

    public function testTheWayBackIsBuiltFromANumberAndAStoreNeverFromAnAddress(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Kit');
        $web = $this->signIn($this->uiUser('mapper'));
        $n = 0;
        $decide = function (array $context) use ($a, $sku, $web, &$n): array {
            $l = $this->profiled($a, 'X' . ++$n, ['product_title' => 'Listing ' . $n]);
            $form = $this->decideForm($web, $l, ['pick' => (string) $sku]);
            $r = $web->post("/ui/review/listing/{$l}/decide", ['action' => 'link'] + $context + $form);
            self::assertSame(303, $r->status, json_encode($context) . ' ' . $r->describe());
            self::assertSame('mapped', $this->link($l)['status']);
            return [$l, (string) $r->location()];
        };
        // Not "from By Item" unless the token, a product number and a store's code are all there: the page's own address then.
        foreach ([['via' => 'product', 'product' => '//evil.example', 'channel' => 'alt'], ['via' => 'product', 'product' => (string) $sku, 'channel' => 'https://evil.example'],
            ['via' => 'product', 'product' => (string) $sku], ['via' => 'products', 'product' => (string) $sku, 'channel' => 'alt'],
            ['via' => 'product', 'product' => '-4', 'channel' => 'alt'], ['product' => (string) $sku, 'channel' => 'alt'],
            ['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt', 'sample' => '5']] as $context) {
            [$l, $to] = $decide($context);
            self::assertStringStartsWith("/ui/review/listing/{$l}?notice=decided_link", $to, json_encode($context));
        }
        // A filter or a search that is not one is dropped; a hostile one is only ever a query value of By Item.
        [$l, $to] = $decide(['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt', 'cov' => 'https://evil.example', 'lq' => "//evil.example\r\nX: y"]);
        self::assertSame("/ui/review/products?q=%2F%2Fevil.example%0D%0AX%3A%20y&at={$sku}&notice=decided_link&prev={$l}#p-{$sku}", $to);
        self::assertSame(200, $web->follow(new UiResponse(303, ['location' => [$to]], ''))->status);
        // A list search with no usable word in the context: By Item answers (it finds nothing, and shows the item once), never a
        // failure. The picker's own search and page are never part of the way back after a decision.
        foreach (["\u{00A0}", "\xFF"] as $odd) {
            [$l, $to] = $decide(['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt', 'lq' => $odd, 'pq' => $odd, 'pp' => '5']);
            self::assertSame('/ui/review/products?q=' . rawurlencode(ByProductContext::text($odd)) . "&at={$sku}&notice=decided_link&prev={$l}#p-{$sku}", $to);
            $page = $web->follow(new UiResponse(303, ['location' => [$to]], ''));
            self::assertSame([200, [$sku]], [$page->status, self::ids($page)], bin2hex($odd) . ' ' . $page->describe());
        }
        [$l, $to] = $decide(['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt', 'pq' => '//evil.example', 'pp' => 'https://evil.example']);
        self::assertSame("/ui/review/products?at={$sku}&notice=decided_link&prev={$l}#p-{$sku}", $to);
        // A product number that names nothing: By Item, with the notice, and no row marked.
        [$l, $to] = $decide(['via' => 'product', 'product' => '999999', 'channel' => 'alt']);
        self::assertSame("/ui/review/products?at=999999&notice=decided_link&prev={$l}#p-999999", $to);
        $page = $web->follow(new UiResponse(303, ['location' => [$to]], ''));
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', '"Listing ' . $n . '"', self::cw($sku)), $page->text());
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//tr[contains(@class, "here")]')->length);

        // A list comes first: a variant opened from a strength list goes on to the next one of that list, as before.
        $first = $this->queued($a, 'Q1', 'Key', $sku, ['product_title' => 'Queue one', 'units_365d' => 9]);
        $second = $this->queued($a, 'Q2', 'Key', $sku, ['product_title' => 'Queue two', 'units_365d' => 8]);
        $form = $this->decideForm($web, $first, ['queue' => 'Key', 'via' => 'product', 'product' => (string) $sku, 'channel' => 'alt']);
        self::assertArrayNotHasKey('via', $form);
        $r = $web->post("/ui/review/listing/{$first}/decide", ['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt'] + $form);
        self::assertSame("/ui/review/listing/{$second}?queue=Key&channel=alt&notice=decided_link&prev={$first}", $r->location());
    }

    public function testHostileNamesAreShownAsTextOnTheListThePickerAndInTheCsv(): void
    {
        $evil = '<script>alert(1)</script>"\'&';
        $shown = htmlspecialchars($evil, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $attr = '"><img src=x onerror=alert(1)>';
        $a = $this->site('alt', 'shadow');
        self::$db->exec('UPDATE channel SET name = ? WHERE code = ?', ['Store ' . $evil, 'alt']);
        $sku = $this->item('legacy', 0, 'Kit ' . $evil);
        self::$db->exec('UPDATE sku SET brand = ?, flavour = ? WHERE id = ?', ['Brand ' . $evil, 'script', $sku]);
        $this->profiled($a, 'V' . $evil, ['product_title' => 'Matched ' . $evil, 'variant_title' => 'Option ' . $evil], $sku);
        $this->profiled($a, 'W1', ['product_title' => 'Waiting ' . $evil, 'brand' => 'B ' . $evil]);
        $web = $this->signIn($this->uiUser('mapper'));
        $pages = [
            $web->get('/ui/review/products'),
            $web->get('/ui/review/products', ['cov' => $evil, 'q' => $attr, 'page' => $evil, 'at' => $attr, 'notice' => $evil, 'prev' => $attr]),
            $web->get('/ui/review/products', ['q' => 'script', 'at' => (string) $sku, 'notice' => 'decided_link', 'prev' => $attr]),
            $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => 'script', 'cov' => $evil, 'lq' => $attr]),
            $web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => $attr, 'page' => $evil]),
        ];
        foreach ($pages as $i => $r) {
            self::assertSame(200, $r->status, $i . ': ' . $r->describe());
            self::assertSame(1, substr_count(strtolower($r->body), '<script'), $i . ': the asset tag is the only script tag');
            self::assertSame(0, substr_count(strtolower($r->body), '<img'), $i . ': no tag made of a query value');
            self::assertStringNotContainsString('onerror=alert(1)>', (string) preg_replace('/&quot;&gt;&lt;img src=x onerror=alert\(1\)&gt;/', '', $r->body), (string) $i);
        }
        foreach ([0, 2, 3] as $i) {
            self::assertStringContainsString('Kit ' . $shown, $pages[$i]->body, $i . ': the product\'s name, as text');
            self::assertStringContainsString('Store ' . $shown, $pages[$i]->body, $i . ': the store\'s name, as text');
        }
        self::assertStringContainsString('Matched ' . $shown . ' Option ' . $shown, $pages[0]->body);
        self::assertStringContainsString('Waiting ' . $shown, $pages[3]->body);
        self::assertStringContainsString('value="' . htmlspecialchars($attr, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"', $pages[1]->body, 'the search box shows what was typed, as a value');
        // The CSV is a download (an attachment, sandboxed): the names are data there, and a cell a spreadsheet would run is made text.
        $csv = $web->get('/ui/review/products.csv');
        self::assertSame(['text/csv; charset=utf-8', 'sandbox'], [$csv->header('content-type'), $csv->headerValues('content-security-policy')[0] ?? null]);
        self::assertStringStartsWith('attachment;', (string) $csv->header('content-disposition'));
        self::assertStringContainsString('"Kit <script>alert(1)</script>""\'&"', $csv->body);
    }

    public function testTheItemPageLinksToItsRowAndTheFirstSearchComesFromTheItemsWords(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Kit one');
        $l = $this->profiled($a, 'A1', ['product_title' => 'Kit one on Alt'], $sku);
        $gone = $this->item('legacy', 0, 'Kit gone');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $gone]);
        $web = $this->signIn($this->uiUser('viewer'));
        $item = $web->get('/ui/items/' . $sku);
        self::assertSame(200, $item->status, $item->describe());
        $href = '/ui/review/products?q=' . self::cw($sku);
        self::assertSame(Words::ITEM['coverage_link'], self::squash((new \DOMXPath($item->dom()))->evaluate('string(//a[@href="' . $href . '"])')));
        $row = self::open($web, $href);
        self::assertSame([$sku], self::ids($row));
        self::assertSame('/ui/review/listing/' . $l, self::links($row, $sku, 'Alt Store')['Kit one on Alt']);
        self::assertStringNotContainsString('/ui/review/products?q=', implode(' ', $web->get('/ui/items/' . $gone)->hrefs()), 'a product joined away has no row');
        self::assertStringNotContainsString('/ui/review/products?q=', implode(' ', $this->signIn($this->uiUser('buyer'))->get('/ui/items/' . $sku)->hrefs()),
            'no link to a page the person cannot open');

        // The picker's first search (pure): brand and flavour when the product's details have them, else the first words of its name;
        // a strength, a size or a pack is left to the eye.
        self::assertSame('Elf Bar Blue Razz Lemonade', ByProductController::firstSearch(['name' => 'Elf Bar 600 Blue Razz Lemonade 20mg', 'brand' => 'Elf Bar', 'flavour' => 'Blue Razz Lemonade']));
        self::assertSame('Lost Mary BM600 Pineapple', ByProductController::firstSearch(['name' => 'Lost Mary BM600 - Pineapple Ice 20 mg (x10)', 'brand' => null, 'flavour' => '']));
        self::assertSame('Vaporesso XROS 600', ByProductController::firstSearch(['name' => 'Vaporesso XROS 3 600 10ml 2 ml', 'brand' => 'Vaporesso', 'flavour' => null]));
        self::assertSame('', ByProductController::firstSearch(['name' => '10 ml', 'brand' => null, 'flavour' => null]));
        self::assertSame('', ByProductController::firstSearch([]));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The quick match (the owner's request of 20 Oct 2026: "directly add dropdown with full details, need search all attribute")

    /**
     * Without app.js the fold is a plain search that opens the picker: its box and button name a GET form of their own, which sits
     * OUTSIDE the list's bulk form (a form inside a form is no form), with the store, the list's filter, search and rows. The box is
     * filled in with the item's own words, as the picker's first search. Only a cell of a store that has no variant for the item
     * has it, and only for whoever may decide.
     */
    public function testACellNotOnTheStoreHoldsASearchThatOpensThePickerWithoutTheScript(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Elux Legend 3500 Blue Razz 20mg');
        self::$db->exec("UPDATE sku SET brand = 'Elux', flavour = 'Blue Razz' WHERE id = ?", [$sku]);
        $matched = $this->item('legacy', 0, 'Elux matched kit');
        $this->profiled($a, 'M1', ['product_title' => 'Matched on Alt'], $matched);
        $suggested = $this->item('legacy', 0, 'Elux suggested kit');
        $this->queued($a, 'S1', 'Check', $suggested, ['product_title' => 'Suggested on Alt']);
        $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 5]);
        $web = $this->signIn($this->uiUser('mapper'));
        $code = self::cw($sku);

        $page = $web->get('/ui/review/products', ['q' => 'elux', 'n' => '100']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        $fold = self::fold($page, $sku, 'Alt Store');
        self::assertNotNull($fold, 'a store with no variant for the item: the fold');
        $xp = new \DOMXPath($fold->ownerDocument);
        self::assertSame(["/ui/review/products/{$sku}/map/options?channel=alt&lq=elux&ln=100", (string) $sku, 'alt', Words::BY_PRODUCT['quick_loading'], Words::BY_PRODUCT['quick_failed']],
            [$fold->getAttribute('data-options'), $fold->getAttribute('data-sku-id'), $fold->getAttribute('data-channel'), $fold->getAttribute('data-loading'),
                $fold->getAttribute('data-failed')], 'the fragment\'s address and the words app.js shows come from the page');
        self::assertSame(Words::BY_PRODUCT['map'], self::squash($xp->evaluate('string(summary)', $fold)), 'it opens with "Match…"');
        $box = $xp->query('.//input[@name="q"]', $fold)->item(0);
        self::assertInstanceOf(\DOMElement::class, $box);
        $id = $box->getAttribute('form');
        self::assertMatchesRegularExpression('/^qp-' . $sku . '-\d+$/D', $id);
        self::assertSame(['Elux Blue Razz', 'search', '100', Words::say('BY_PRODUCT', 'quick_label', 'Alt Store', $code)],
            [$box->getAttribute('value'), $box->getAttribute('type'), $box->getAttribute('maxlength'), $box->getAttribute('aria-label')],
            'filled in with the item\'s own words, as the picker\'s first search');
        self::assertTrue($box->hasAttribute('data-qp-q'));
        self::assertSame(Words::UI['search'], self::squash($xp->evaluate('string(.//button[@type="submit"][@form="' . $id . '"])', $fold)));
        self::assertSame(1, $xp->query('.//*[@data-qp-results]', $fold)->length, 'where app.js puts the variants');
        // The form itself: a GET of the picker, not inside the bulk form (the list's own POST form around the table).
        self::assertSame(1, $xp->query('//form[@id="' . $id . '"][@method="get"][@action="/ui/review/products/' . $sku . '/map"]')->length);
        self::assertSame(1, $xp->query('//form[@data-bulk]')->length, 'a person who may decide has the bulk form around the list');
        self::assertSame(0, $xp->query('//form[@data-bulk]//form')->length, 'no form inside the bulk form');
        $sent = $page->form("/ui/review/products/{$sku}/map");
        ksort($sent);
        self::assertSame(['channel' => 'alt', 'ln' => '100', 'lq' => 'elux', 'q' => 'Elux Blue Razz'], $sent, 'what a browser sends: the box names its form');
        self::assertSame(['elux', '100'], [$page->form('/ui/review/products/bulk')['q'], $page->form('/ui/review/products/bulk')['n']],
            'the bulk form keeps the list\'s own search: the box is not one of its fields');
        $picker = $web->get("/ui/review/products/{$sku}/map", $sent);
        self::assertSame(200, $picker->status, $picker->describe());
        self::assertSame(['Elux Legend'], array_column(self::picks($picker, 'found'), 0), 'the picker, searched with those words');
        self::assertSame('/ui/review/products?q=elux&n=100&at=' . $sku . '#p-' . $sku, (new \DOMXPath($picker->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        self::assertSame([Words::BY_PRODUCT['quick_page'] => "/ui/review/products/{$sku}/map?channel=alt&lq=elux&ln=100"], self::links($page, $sku, 'Alt Store'));

        // Only "Not on this store" has it: a matched or a suggested cell keeps its links; each store's fold is its own.
        self::assertNull(self::fold($page, $matched, 'Alt Store'));
        self::assertSame(Words::BY_PRODUCT['map_more'], array_key_last(self::links($page, $matched, 'Alt Store')));
        self::assertNull(self::fold($page, $suggested, 'Alt Store'));
        $big = self::fold($page, $sku, 'Big Store');
        self::assertNotNull($big);
        self::assertSame("/ui/review/products/{$sku}/map/options?channel=vbig&lq=elux&ln=100", $big->getAttribute('data-options'));
        self::assertNotSame($id, (new \DOMXPath($big->ownerDocument))->evaluate('string(.//input[@name="q"]/@form)', $big));
        // Someone who can only look: no fold, no address of the fragment.
        $look = $this->signIn($this->uiUser('viewer'))->get('/ui/review/products');
        self::assertSame(0, (new \DOMXPath($look->dom()))->query('//details[@data-quick-pick]')->length);
        self::assertStringNotContainsString('/map/options', $look->body);
    }

    /**
     * The fragment: no layout, its root marked (app.js shows nothing else), the suggestions for the item first (the strongest first),
     * then, once the person searched (or on opening, when nothing is suggested), the store's waiting variants that have every word.
     * Each row carries the variant's details and data attributes, and a "Match to …" form with all that the variant's decide route
     * needs and the way back to this row of the list.
     */
    public function testTheFragmentShowsTheSuggestionsFirstThenTheSearchEachWithItsDetailsAndAMatchForm(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $sku = $this->item('legacy', 0, 'Elux Legend 3500 Blue Razz 20mg');
        self::$db->exec("UPDATE sku SET brand = 'Elux', flavour = 'Blue Razz' WHERE id = ?", [$sku]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('5056168812345', ?)", [$sku]);
        $code = self::cw($sku);
        $likely = $this->queued($a, 'S1', 'Check', $sku, ['product_title' => 'Elux Blue Razz likely', 'units_30d' => 1], ['ai_units_per_item' => 10]);
        $strong = $this->queued($a, 'S2', 'Key', $sku, ['product_title' => 'Elux Blue Razz strong', 'variant_title' => '20mg 2ml', 'brand' => 'Elux', 'units_30d' => 2,
            'units_365d' => 40, 'barcodes' => ['05056168812345'], 'features' => ['strength_mg' => 20, 'volume_ml' => 2, 'flavour_tokens' => ['blue', 'razz'], 'line_tokens' => ['legend']]]);
        self::$db->exec('UPDATE listing_profile SET price = 4.99 WHERE listing_id = ?', [$strong]);
        $plain = $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'brand' => 'Elux Vapes', 'units_30d' => 7, 'barcodes' => ['5000000000017']]);
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertNull(self::fold($web->get('/ui/review/products'), $sku, 'Alt Store'), 'a store that suggests a variant: "Suggested", no fold');

        // Opening: the suggestions only (as the picker's plain opening: the item's words are not run while something is suggested).
        $open = self::optionsOf($web, $sku, 'alt');
        self::assertSame(200, $open->status, $open->describe() . implode("\n", self::$log));
        self::assertStringNotContainsString('<html', strtolower($open->body), 'a fragment: no layout');
        self::assertStringNotContainsString('<nav', $open->body);
        self::assertSame(0, substr_count(strtolower($open->body), '<script'));
        $xp = new \DOMXPath($open->dom());
        $root = $xp->query('//div[@data-qp-fragment]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $root, 'its root says what it is (app.js shows nothing else)');
        self::assertSame([(string) $sku, 'alt'], [$root->getAttribute('data-sku-id'), $root->getAttribute('data-channel')]);
        self::assertSame([$strong => 'suggested', $likely => 'suggested'], self::quickRows($open), 'the strongest suggestion first');
        self::assertStringContainsString(Words::BY_PRODUCT['quick_search_first'], $open->text());
        self::assertStringContainsString(Words::BY_PRODUCT['pick_suggested'], $open->text());

        // A row: what the variant is, with every detail the picker shows and the features where they are known.
        $row = self::quickRow($open, $strong);
        foreach (['data-listing-id' => (string) $strong, 'data-sku-id' => (string) $sku, 'data-channel' => 'alt'] as $attr => $value) {
            self::assertSame($value, $row['li']->getAttribute($attr), $attr);
        }
        self::assertStringStartsWith('Elux Blue Razz strong 20mg 2ml', $row['text']);
        foreach ([Words::STORE_STATE['suggested'], Words::BAND['Key'], Words::BY_PRODUCT['pick_barcode_same']] as $word) {
            self::assertStringContainsString($word, $row['text']);
        }
        self::assertSame([Words::FIELD['brand'] => 'Elux', Words::LISTING['option'] => 'S2', Words::FIELD['strength_mg'] => '20', Words::FIELD['volume_ml'] => '2',
            Words::FIELD['flavour'] => 'blue razz', Words::FIELD['line'] => 'legend', Words::FIELD['barcodes'] => '05056168812345', Words::BY_PRODUCT['pick_price'] => '£4.99',
            Words::QUEUE['sold_30'] => '2', Words::QUEUE['sold_365'] => '40'], $row['facts']);
        $back = ['via' => 'product', 'product' => (string) $sku, 'channel' => 'alt'];
        self::assertSame(['csrf' => $this->token($web), 'action' => 'link', 'sku_id' => (string) $sku, 'expected_map_version' => (string) $this->version($strong),
            'proposal_id' => (string) $this->proposalOf($strong)] + $back + ['units_per_item' => '1'], $row['match'], 'all that the decide route needs, and the way back');
        $button = $xp->query('//li[@data-listing-id="' . $strong . '"]//form[contains(@class, "qp-match")]//button[@type="submit"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $button);
        self::assertSame([Words::say('BY_PRODUCT', 'quick_match', $code), Words::say('BY_PRODUCT', 'quick_match_named', 'Elux Blue Razz strong', $code), (string) $strong, (string) $sku, 'alt'],
            [self::squash($button->textContent), $button->getAttribute('title'), $button->getAttribute('data-listing-id'), $button->getAttribute('data-sku-id'),
                $button->getAttribute('data-channel')]);
        self::assertSame("/ui/review/listing/{$strong}?pick={$sku}&via=product&product={$sku}&channel=alt", $row['links']['Elux Blue Razz strong'], 'its name opens its page, the item picked');
        // The suggestion says how many one sale uses: the number goes in the form, and it can be changed there.
        $likelyRow = self::quickRow($open, $likely);
        self::assertSame('10', $likelyRow['match']['units_per_item'] ?? null);
        self::assertStringContainsString(Words::BAND['Check'], $likelyRow['text']);
        self::assertStringContainsString(Words::BY_PRODUCT['pick_barcode_unknown'], $likelyRow['text']);
        self::assertSame(1, (new \DOMXPath($open->dom()))->query('//li[@data-listing-id="' . $likely . '"]//input[@type="number"][@name="units_per_item"][@min="1"][@max="'
            . DecisionService::MAX_UNITS . '"]')->length);

        // The person's search: the suggestions stay first, then the waiting variants with every word (not the ones listed above).
        $found = self::optionsOf($web, $sku, 'alt', 'blue razz');
        self::assertSame([$strong => 'suggested', $likely => 'suggested', $plain => 'found'], self::quickRows($found));
        $row = self::quickRow($found, $plain);
        self::assertStringContainsString(Words::STORE_STATE['not_matched'], $row['text']);
        self::assertStringContainsString(Words::BY_PRODUCT['pick_barcode_differs'], $row['text']);
        self::assertStringNotContainsString(Words::BAND['Key'], $row['text'], 'no strength: nothing suggests it for this item');
        self::assertSame(['action' => 'link', 'sku_id' => (string) $sku, 'pq' => 'blue razz'], array_intersect_key($row['match'] ?? [], ['action' => 1, 'sku_id' => 1, 'pq' => 1]),
            'its search comes along, for Back from the variant\'s page');
        self::assertArrayNotHasKey('proposal_id', $row['match'] ?? [], 'nothing is suggested for it');
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'pick_search', 'Alt Store'), $found->text());
        // An emptied box is the person's search too (as the picker's): the suggestions, then every waiting variant, best sellers
        // first; its forms carry the emptied search for Back.
        $firstAlt = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'first-alt'");
        $emptied = self::optionsOf($web, $sku, 'alt', '');
        self::assertSame([$strong => 'suggested', $likely => 'suggested', $plain => 'found', $firstAlt => 'found'], self::quickRows($emptied));
        self::assertSame(' ', self::quickRow($emptied, $plain)['match']['pq'] ?? null);
        self::assertStringNotContainsString(Words::say('BY_PRODUCT', 'quick_first', 'Elux Blue Razz'), $emptied->text());
        $none = self::optionsOf($web, $sku, 'alt', 'zzz');
        self::assertSame([$strong => 'suggested', $likely => 'suggested'], self::quickRows($none));
        self::assertStringContainsString(Words::BY_PRODUCT['pick_found_no_more'], $none->text());

        // Nothing suggested on Big: opening runs the item's own words there, and says so.
        $this->profiled($b, 'B1', ['product_title' => 'Elux Blue Razz on Big', 'units_30d' => 3]);
        $onBig = self::options($web, self::fold($web->get('/ui/review/products'), $sku, 'Big Store'));
        self::assertSame(200, $onBig->status, $onBig->describe());
        self::assertSame(['found'], array_values(array_unique(self::quickRows($onBig))));
        self::assertStringContainsString(Words::BY_PRODUCT['quick_suggested_none'], $onBig->text());
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'quick_first', 'Elux Blue Razz'), $onBig->text());

        // At most a page of them (the picker's 20), and the way to the rest on the matching page, with the search.
        for ($i = 1; $i <= 25; $i++) {
            $this->profiled($a, 'F' . $i, ['product_title' => sprintf('Pager item %02d', $i), 'units_30d' => 100 - $i]);
        }
        $many = self::optionsOf($web, $sku, 'alt', 'pager item');
        self::assertCount(2 + Queries::PICK_PER_PAGE, self::quickRows($many));
        $more = (string) (new \DOMXPath($many->dom()))->evaluate('string(//div[@data-qp-fragment]//a[normalize-space(.)="' . Words::BY_PRODUCT['quick_more'] . '"]/@href)');
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&q=pager%20item", $more);
        self::assertStringNotContainsString(Words::BY_PRODUCT['quick_more'], $found->text(), 'no "more" when every one is shown');
        self::assertStringContainsString(Words::BY_PRODUCT['quick_hint'], $found->text());
    }

    /**
     * "Search all attribute" (the owner, 20 Oct 2026): every word of the search must be somewhere in the variant: its names and
     * brand (as before), its attributes, its web address, the website's number of its product, its option number, any part of a
     * barcode, the features the matching read from it (strength, size, flavour, range). The picker and the fold search alike.
     */
    public function testTheSearchOfTheStoresWaitingVariantsReadsEveryAttribute(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Mystery kit');
        $attr = $this->profiled($a, 'U2', ['product_title' => 'Mystery bar', 'units_30d' => 4]);
        self::$db->exec('UPDATE listing_profile SET attributes = CAST(? AS JSON), perma_link = ?, site_product_id = ? WHERE listing_id = ?',
            ['{"items": [{"name": "Flavour", "value": "Watermelon Chill"}, {"name": "Nicotine Strength", "value": "11mg"}, {"name": "Note", "value": "Café Crème"}]}',
                'https://alt.example/shop/mystery-bar-special-edition/',
                'P-77812', $attr]);
        $barcode = $this->profiled($a, 'U3', ['product_title' => 'Plain thing', 'units_30d' => 3, 'barcodes' => ['5012345678900', '0000000000001']]);
        $feature = $this->profiled($a, 'U4', ['product_title' => 'Fruit bar', 'units_30d' => 2, 'features' => ['flavour_tokens' => ['kiwi', 'passion'], 'strength_mg' => 17,
            'volume_ml' => 13, 'line_tokens' => ['nebula'], 'product_id' => 99123]]);
        $titled = $this->profiled($a, 'U5', ['product_title' => 'Watermelon Ice', 'units_30d' => 1]);
        // An attribute whose value is a number (Codex's review: JSON_SEARCH reads strings only).
        $numeric = $this->profiled($a, 'U6', ['product_title' => 'Cloud stick', 'units_30d' => 0]);
        self::$db->exec('UPDATE listing_profile SET attributes = CAST(? AS JSON) WHERE listing_id = ?', ['{"items": [{"name": "Puff count", "value": 6000}]}', $numeric]);
        $bare = $this->listing($a, 'NOPROFILE7', null);
        $web = $this->signIn($this->uiUser('mapper'));
        $fold = self::fold($web->get('/ui/review/products'), $sku, 'Alt Store');
        self::assertNotNull($fold);
        $q = new Queries(self::$db);
        $ids = static fn (string $text): array => $q->waitingListings($a->channelId, $text, 1)['ids'];
        $cases = [
            'watermelon' => [$attr, $titled],            // an attribute's value, and a name as before
            'watermelon chill' => [$attr],               // both words in one attribute
            'chill mystery' => [$attr],                  // the words in two places: the attribute and the name
            'chill plain' => [],                         // every word, not any
            '11mg' => [$attr],
            'CREME cafe' => [$attr],                     // whatever its case and accents
            'nicotine' => [$attr],                       // an attribute's name is a word of it too ...
            'items' => [],                               // ... the JSON's own keys are not
            'value' => [],
            '6000' => [$numeric],                        // a value that is a number, as text
            '600' => [$numeric],
            'puff count' => [$numeric],
            'special-edition' => [$attr],                // the web address
            'P-77812' => [$attr],                        // the website's number of its product
            '7812' => [$attr],
            '1234567' => [$barcode],                     // a part of a barcode
            '5012345678900' => [$barcode],               // a whole one, as before
            'kiwi' => [$feature],                        // the features: flavour, strength, size, range, the product's number
            'nebula passion' => [$feature],
            '99123' => [$feature],
            'U4' => [$feature],                          // the option number, as before
            'NOPROFILE7' => [$bare],                     // also a variant whose details never came
            'nosuchword' => [],
        ];
        foreach ($cases as $text => $want) {
            self::assertEqualsCanonicalizing($want, $ids((string) $text), "the store's search: {$text}");
            self::assertEqualsCanonicalizing($want, array_keys(self::quickRows(self::options($web, $fold, (string) $text))), "the fold: {$text}");
        }
        self::assertSame(['Mystery bar'], array_column(self::picks($web->get("/ui/review/products/{$sku}/map", ['channel' => 'alt', 'q' => 'watermelon chill']), 'found'), 0),
            'the picker searches the same way');
        // Words that are LIKE's own signs are only text; a long or odd text is no failure.
        foreach (['%', '_', '\\', "50%_off", str_repeat('ab ', 40), "caf\xE9", "\u{3000}"] as $odd) {
            self::assertSame(200, self::options($web, $fold, $odd)->status, bin2hex($odd));
        }
        self::assertSame([], $ids('%'));
        self::assertSame([], $ids('_'));
    }

    /**
     * "Match to …" from the fold: DecisionService links the variant (the same decide route as the variant's page), and the person is
     * back on the list at that item, with the list as they had it and the notice; the cell says Matched. A pack size other than 1
     * goes to a second OK, and the list says that too.
     */
    public function testMatchInTheFoldLinksTheVariantAndTheListShowsItMatched(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $sku = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $one = $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 9]);
        $pack = $this->profiled($b, 'P1', ['product_title' => 'Elux Legend Blue Razz box of 10', 'units_30d' => 5]);
        $web = $this->signIn($this->uiUser('mapper'));
        $code = self::cw($sku);
        $list = $web->get('/ui/review/products', ['cov' => 'missing-alt', 'q' => 'elux']);
        $fragment = self::options($web, self::fold($list, $sku, 'Alt Store'), 'legend');
        $form = self::quickRow($fragment, $one)['match'];
        self::assertNotNull($form);
        self::assertSame(['missing-alt', 'elux', 'legend'], [$form['cov'], $form['lq'], $form['pq']]);
        $r = $web->post("/ui/review/listing/{$one}/decide", $form);
        self::assertSame(303, $r->status, $r->describe() . implode("\n", self::$log));
        self::assertSame("/ui/review/products?cov=missing-alt&q=elux&at={$sku}&notice=decided_link&prev={$one}#p-{$sku}", $r->location(),
            'back on the list as the person had it, at the item');
        self::assertSame([$sku, 1, 'mapped'], [(int) $this->link($one)['sku_id'], (int) $this->link($one)['units_per_item'], $this->link($one)['status']]);
        $back = $web->follow($r);
        self::assertSame(200, $back->status, $back->describe());
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', '"Elux Legend Blue Razz"', $code), $back->text());
        self::assertSame([$sku], self::ids($back), 'it is on Alt now: shown once, first');
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'] . ' Elux Legend Blue Razz', self::cells($back, $sku)['Alt Store']);
        self::assertNull(self::fold($back, $sku, 'Alt Store'), 'a matched cell has no fold');
        self::assertStringContainsString('here', (new \DOMXPath($back->dom()))->evaluate('string(//tr[@id="p-' . $sku . '"]/@class)'));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND action = 'link' AND state = 'applied'", [$one]));

        // A box of 10: the number is changed in the fold; a matching lead gives the second OK, and the list says it waits.
        $form = self::quickRow(self::options($web, self::fold($back, $sku, 'Big Store'), 'box'), $pack)['match'];
        self::assertNotNull($form);
        $r = $web->post("/ui/review/listing/{$pack}/decide", ['units_per_item' => '10'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame("/ui/review/products?cov=missing-alt&q=elux&at={$sku}&notice=pending_second&prev={$pack}#p-{$sku}", $r->location());
        self::assertSame('unmapped', $this->link($pack)['status'], 'not live: it waits for a matching lead');
        $waits = $web->follow($r);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'pending_second', '"Elux Legend Blue Razz box of 10"'), $waits->text());
        self::assertStringStartsWith(Words::PRODUCT_STORE['waiting'], self::cells($waits, $sku)['Big Store']);
    }

    /**
     * A refused "Match to …" is never silent: it lands on the variant's own page with that page's words and its answer form, which
     * still leads back to the picker as the fold had it. The fold offers no "Match to …" where that page would not let this person
     * answer at once: a suggestion whose clues disagree (a matching lead's), and a variant decided one at a time (held back, a spot
     * check's match): there it is "Choose" (the variant's page) and the line the lists say.
     */
    public function testARefusedMatchLandsOnTheVariantsPageWithItsWordsAndRowsDecidedOneAtATimeOpenThatPage(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $one = $this->profiled($a, 'U1', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 9]);
        $two = $this->profiled($a, 'U2', ['product_title' => 'Elux Legend two', 'units_30d' => 8]);
        $clash = $this->queued($a, 'C1', 'Conflict', $sku, ['product_title' => 'Elux Legend clues disagree', 'units_30d' => 7]);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $fragment = self::optionsOf($mapper, $sku, 'alt', 'legend');
        self::assertSame([$clash => 'suggested', $one => 'found', $two => 'found'], self::quickRows($fragment));

        // The variant changed after the fold was drawn (renamed on the website, say): its page, with its words, and Back to the picker.
        $form = self::quickRow($fragment, $one)['match'];
        self::$db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id = ?', [$one]);
        $stale = $mapper->post("/ui/review/listing/{$one}/decide", $form);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['map_version_conflict'], $stale->text());
        self::assertSame("/ui/review/products/{$sku}/map?channel=alt&q=legend", (new \DOMXPath($stale->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        self::assertSame(['product', (string) $sku, 'alt', (string) $sku], [$stale->form('/decide')['via'], $stale->form('/decide')['product'], $stale->form('/decide')['channel'],
            $stale->form('/decide')['sku_id']], 'its answer form, with the item picked and the way back');
        self::assertSame('unmapped', $this->link($one)['status'], 'nothing was saved');
        // A suggestion arrived after the fold was drawn: the form names none, so the page shows the new one first.
        $form = self::quickRow($fragment, $two)['match'];
        $this->propose($two, 'Check', $this->item('legacy', 0, 'Another kit'), [], false);
        $newer = $mapper->post("/ui/review/listing/{$two}/decide", $form);
        self::assertSame(409, $newer->status, $newer->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['proposal_changed'], $newer->text());
        self::assertSame('unmapped', $this->link($two)['status']);

        // Clues that disagree: a matching lead's. The mapper gets the variant's page and the line; a hand-made form is refused there.
        $row = self::quickRow($fragment, $clash);
        self::assertNull($row['match'], 'no "Match to …" for a suggestion only a matching lead decides');
        self::assertStringContainsString(Words::LISTING['lead_only'], $row['text']);
        self::assertSame("/ui/review/listing/{$clash}?pick={$sku}&via=product&product={$sku}&channel=alt&pq=legend", $row['links'][Words::BY_PRODUCT['pick_choose']]);
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $leads = self::quickRow(self::optionsOf($lead, $sku, 'alt', 'legend'), $clash)['match'];
        self::assertNotNull($leads, 'a matching lead may');
        $refused = $mapper->post("/ui/review/listing/{$clash}/decide", ['csrf' => $this->token($mapper)] + $leads);
        self::assertSame(403, $refused->status, $refused->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['lead_required'], $refused->text());
        self::assertSame('suggested', $this->link($clash)['status']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action <> 'suggest'"), 'nothing was decided by any of them');
    }

    /** Held back and a spot check's match (OneAtATime): no "Match to …" in the fold, the lists' line, and "Choose" for the variant's page. */
    public function testAVariantDecidedOneAtATimeHasNoMatchButtonInTheFold(): void
    {
        $this->keySetup();
        $saved = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.spot_check_size'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('5' AS JSON) WHERE setting_key = 'approvals.spot_check_size'");
        try {
            $lead = $this->staffUser('mapping_lead');
            $pop = [];
            for ($i = 0; $i < 9; $i++) {
                $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 900 - $i);
            }
            $sample = (new KeySample(self::$db))->create($lead, 'sc1', 5, true);
            $spot = array_map('intval', array_column($sample['members'], 'listing_id'));
            $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $spot, true)));
            (new KeyHold(self::$db))->run($lead, 'sc1', KeyHold::parse("proposal_id,listing_id,reason\n{$others[0]['proposal']},{$others[0]['listing']},Pack size differs\n"),
                false, true);
            $inSpot = $spot[0];
            $free = $others[1]['listing'];
            // Another item, on no store: its fold's search finds the strong matches of the others (a variant may be matched to any item).
            $kit = $this->item('legacy', 0, 'Other kit');
            $web = $this->signIn($this->uiUser('mapper'));
            $page = $web->get('/ui/review/products', ['q' => self::cw($kit)]);
            self::assertSame(200, $page->status, $page->describe());
            $fold = self::fold($page, $kit, 'ALT test site');
            self::assertNotNull($fold, 'the item is not on the store: its cell has the fold');
            $fragment = self::options($web, $fold, 'Electrofag listing');
            self::assertSame(200, $fragment->status, $fragment->describe() . implode("\n", self::$log));
            self::assertCount(9, self::quickRows($fragment));
            $hold = self::$db->one('SELECT h.created_at, u.display_name FROM key_bulk_hold h JOIN staff_user u ON u.id = h.staff_user_id WHERE h.listing_id = ?',
                [$others[0]['listing']]) ?? [];
            $held = self::quickRow($fragment, $others[0]['listing']);
            self::assertNull($held['match'], 'held back: decided on its own page');
            self::assertStringContainsString(Words::say('ONE_AT_A_TIME', 'held', Html::day((string) $hold['created_at']), (string) $hold['display_name']), $held['text']);
            self::assertStringNotContainsString('Pack size differs', $held['text'], 'the hold\'s note stays on the variant\'s page');
            self::assertStringStartsWith("/ui/review/listing/{$others[0]['listing']}?pick={$kit}&via=product&product={$kit}&channel=alt", $held['links'][Words::BY_PRODUCT['pick_choose']]);
            $inCheck = self::quickRow($fragment, $inSpot);
            self::assertNull($inCheck['match'], 'a spot check\'s match: its owner answers it, there');
            self::assertStringContainsString(Words::say('ONE_AT_A_TIME', 'in_spot_check', 'sc1'), $inCheck['text']);
            self::assertSame('/ui/review/samples/' . (int) $sample['sample_id'], $inCheck['links'][Words::ONE_AT_A_TIME['open_spot']]);
            self::assertArrayHasKey(Words::BY_PRODUCT['pick_choose'], $inCheck['links']);
            self::assertNotNull(self::quickRow($fragment, $free)['match'], 'a free one has its "Match to …"');
            self::assertSame(count($spot) + 1, count(array_filter(array_keys(self::quickRows($fragment)), static fn (int $l): bool => self::quickRow($fragment, $l)['match'] === null)),
                'every spot check match and the held one, no other');
        } finally {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$saved, 'approvals.spot_check_size']);
        }
    }

    /**
     * The fold hides "Match to …" for a variant decided one at a time, and the server refuses it all the same (docs/decisions.md DG1,
     * DG2): a fold drawn BEFORE the variant became another lead's spot check's match or was held back still has the button, and its
     * form lands on the variant's own page with the refusal in words, nothing saved. Another matching lead: 403, the spot check's
     * owner named, and no answer form for them there. The spot check's owner (its match is answered on its own page, one at a time) and
     * anyone on a held variant: 409, and the page drawn again shows why (the spot check's box, the hold's note) with its own answer
     * form and the way back to the picker. The same stale fold's button on a variant that is neither is saved as before.
     */
    public function testAStaleMatchButtonOnAnotherLeadsSpotCheckOrAHeldVariantIsRefusedOnTheVariantsPage(): void
    {
        $this->keySetup();
        $saved = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.spot_check_size'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('5' AS JSON) WHERE setting_key = 'approvals.spot_check_size'");
        try {
            $pop = [];
            for ($i = 0; $i < 9; $i++) {
                $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 900 - $i);
            }
            $kit = $this->item('legacy', 0, 'Other kit');
            $owner = $this->uiUser('mapping_lead');
            $mine = $this->signIn($owner);
            $other = $this->signIn($this->uiUser('mapping_lead'), $this->browser('198.51.100.31'));
            $mapper = $this->signIn($this->uiUser('mapper'), $this->browser('198.51.100.32'));
            // The folds, drawn while every variant is free: each has its "Match to …".
            $folds = [];
            foreach (['mine' => $mine, 'other' => $other, 'mapper' => $mapper] as $who => $web) {
                $folds[$who] = self::optionsOf($web, $kit, 'alt', 'Electrofag listing');
                self::assertSame(200, $folds[$who]->status, $folds[$who]->describe());
                self::assertCount(9, self::quickRows($folds[$who]));
            }
            $sample = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'sc-late', 5, true);
            $spot = array_map('intval', array_column($sample['members'], 'listing_id'));
            $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $spot, true)));
            (new KeyHold(self::$db))->run(Caller::staff($owner['id']), 'sc-late',
                KeyHold::parse("proposal_id,listing_id,reason\n{$others[0]['proposal']},{$others[0]['listing']},Pack size differs\n"), false, true);
            [$held, $free] = [$others[0]['listing'], $others[1]['listing']];
            $ownerName = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$owner['id']]);
            $decided = static fn (int $lid): int => (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND action <> 'suggest'", [$lid]);
            $back = "/ui/review/products/{$kit}/map?channel=alt";

            // Another matching lead's button on the spot check's match: 403 on its page, the owner named, no answer form.
            $form = self::quickRow($folds['other'], $spot[0])['match'];
            self::assertNotNull($form);
            $r = $other->post("/ui/review/listing/{$spot[0]}/decide", $form);
            self::assertSame(403, $r->status, $r->describe());
            self::assertStringContainsString(Words::say('MATCH_ERROR', 'spot_check_other', $ownerName), $r->text());
            self::assertStringContainsString(Words::say('SPOT', 'other', $ownerName), $r->text(), 'the variant\'s page');
            self::assertFalse($r->hasForm('/decide'));
            self::assertStringStartsWith($back, (string) (new \DOMXPath($r->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'), 'Back to the picker');
            // ... and a Matcher's.
            $r = $mapper->post("/ui/review/listing/{$spot[1]}/decide", (array) self::quickRow($folds['mapper'], $spot[1])['match']);
            self::assertSame(403, $r->status, $r->describe());
            self::assertStringContainsString(Words::say('MATCH_ERROR', 'spot_check_other', $ownerName), $r->text());
            // The owner's own button: the match is answered on its own page, which is drawn again with the spot check's box.
            $r = $mine->post("/ui/review/listing/{$spot[0]}/decide", (array) self::quickRow($folds['mine'], $spot[0])['match']);
            self::assertSame(409, $r->status, $r->describe());
            self::assertStringContainsString(Words::MATCH_ERROR['one_at_a_time'], $r->text());
            self::assertStringContainsString(Words::SPOT['mine'], $r->text());
            self::assertTrue($r->hasForm("/ui/review/listing/{$spot[0]}/decide"), 'answered there');
            // Held back since: 409 on its page, with the hold's note and its answer form, which leads back to the picker.
            $r = $mapper->post("/ui/review/listing/{$held}/decide", (array) self::quickRow($folds['mapper'], $held)['match']);
            self::assertSame(409, $r->status, $r->describe());
            self::assertStringContainsString(Words::MATCH_ERROR['one_at_a_time'], $r->text());
            self::assertStringContainsString('Check this one by hand: "Pack size differs"', $r->text());
            $page = $r->form("/ui/review/listing/{$held}/decide");
            self::assertSame(['product', (string) $kit, 'alt'], [$page['via'] ?? null, $page['product'] ?? null, $page['channel'] ?? null]);
            self::assertSame([0, 0, 0, 0], [$decided($spot[0]), $decided($spot[1]), $decided($held), $decided($free)], 'nothing was saved');
            self::assertSame(['waiting', 0], [(new KeySample(self::$db))->status('sc-late')['verdict'], (new KeySample(self::$db))->status('sc-late')['decided']],
                'the spot check goes on');
            // The page's own form, drawn again there (the item picked, its answer "Yes"): saved, and back to By Item.
            self::assertSame([(string) $kit, OneAtATime::HELD . ':' . self::$db->value('SELECT id FROM key_bulk_hold WHERE listing_id = ?', [$held])],
                [$page['sku_id'] ?? null, $page['seen'] ?? null]);
            $r = $mapper->post("/ui/review/listing/{$held}/decide", ['action' => 'link'] + $page);
            self::assertSame(303, $r->status, $r->describe());
            self::assertStringStartsWith('/ui/review/products?', (string) $r->location());
            self::assertSame([1, $kit], [$decided($held), (int) $this->link($held)['sku_id']]);
            // A variant that is neither: the stale fold's button is saved as before.
            $r = $mapper->post("/ui/review/listing/{$free}/decide", (array) self::quickRow($folds['mapper'], $free)['match']);
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame([1, $kit], [$decided($free), (int) $this->link($free)['sku_id']]);
        } finally {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$saved, 'approvals.spot_check_size']);
        }
    }

    /**
     * The fragment's rows decided one at a time cost the same few statements however many spot checks they are in (Codex's review:
     * OneAtATime::of() asked KeySample for each spot check's verdict, one by one). OneAtATime::ofWaiting() answers as of() does for
     * variants that still wait, without a verdict: such a variant is never a confirmed match, so a spot check that holds one is not
     * complete. Counted in statements sent (the session's own counter; its own read is one of them).
     */
    public function testTheFragmentReadsTheSpotChecksOfItsRowsInOneGoHoweverManyThereAre(): void
    {
        $this->keySetup();
        $saved = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.spot_check_size'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST('5' AS JSON) WHERE setting_key = 'approvals.spot_check_size'");
        try {
            $lead = $this->staffUser('mapping_lead');
            $samples = [];
            $members = [];
            for ($s = 1; $s <= 3; $s++) {
                for ($i = 0; $i < 9; $i++) {
                    $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 900 - $i);
                }
                $k = (new KeySample(self::$db))->create($lead, 'sc' . $s, 5, true);
                $samples[$s] = (int) $k['sample_id'];
                $members[$s] = array_map('intval', array_column($k['members'], 'listing_id'));
            }
            $waiting = array_map('intval', self::$db->column("SELECT id FROM channel_listing WHERE channel_id = ? AND status IN ('unmapped', 'suggested') ORDER BY id",
                [$this->altSite->channelId]));
            self::assertCount(27, $waiting);
            $answer = OneAtATime::ofWaiting(self::$db, $waiting);
            self::assertEquals(OneAtATime::of(self::$db, $waiting), $answer, 'the same answer as of() for variants that still wait');
            self::assertCount(15, $answer, 'the 15 matches of the three spot checks');
            $sent = static function (callable $fn): int {
                $n = static fn (): int => (int) self::$db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
                $before = $n();
                $fn();
                return $n() - $before - 1;
            };
            $one = $sent(static fn (): array => OneAtATime::ofWaiting(self::$db, $members[1]));
            self::assertSame($one, $sent(static fn (): array => OneAtATime::ofWaiting(self::$db, $waiting)), 'one spot check or three: the same statements');
            self::assertLessThan($sent(static fn (): array => OneAtATime::of(self::$db, $waiting)), $sent(static fn (): array => OneAtATime::ofWaiting(self::$db, $waiting)),
                'of() reads each spot check\'s verdict');

            // Through the fragment, as the kernel's own connection (cw_app) sends it: the rows of one spot check, then rows of all three.
            $kit = $this->item('legacy', 0, 'Other kit');
            $web = $this->signIn($this->uiUser('mapper'));
            $fragment = static function (string $q) use ($web, $kit): array {
                $n = static fn (): int => (int) self::$appDb->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
                $before = $n();
                $r = $web->get("/ui/review/products/{$kit}/map/options", ['channel' => 'alt', 'q' => $q]);
                return [$n() - $before - 1, $r];
            };
            $fragment('warm up');
            // One match of the first spot check by its option number ("A<n>": the one with the most digits, so no other holds it).
            $options = array_map('strval', self::$db->column('SELECT external_variant_id FROM channel_listing WHERE id IN (' . implode(',', $members[1]) . ')'));
            usort($options, static fn (string $x, string $y): int => [strlen($y), $y] <=> [strlen($x), $x]);
            [$few, $r1] = $fragment($options[0]);
            self::assertCount(1, self::quickRows($r1));
            [$many, $r3] = $fragment('Electrofag listing');
            self::assertSame([200, 200], [$r1->status, $r3->status]);
            $spotLinks = static function (UiResponse $r): array {
                $out = [];
                foreach (array_keys(self::quickRows($r)) as $lid) {
                    $href = self::quickRow($r, $lid)['links'][Words::ONE_AT_A_TIME['open_spot']] ?? null;
                    if ($href !== null) {
                        $out[$href] = true;
                    }
                }
                return array_keys($out);
            };
            self::assertSame(['/ui/review/samples/' . $samples[1]], $spotLinks($r1));
            self::assertEqualsCanonicalizing(array_map(static fn (int $sid): string => '/ui/review/samples/' . $sid, $samples), $spotLinks($r3), 'rows of all three spot checks');
            self::assertCount(Queries::PICK_PER_PAGE, self::quickRows($r3));
            self::assertSame($few, $many, 'the same statements for 1 row of one spot check and 20 rows of three');
        } finally {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$saved, 'approvals.spot_check_size']);
        }
    }

    /**
     * The fragment is the picker's: only for whoever may decide (403 in words otherwise, a sign-in without a session), 404 in words
     * for an item, a joined item or a store that is not one, never a failure for odd values, and it only reads (405 for a POST). Its
     * "Match to …" is a POST of the decide route: refused without the person's own token or the job, nothing linked.
     */
    public function testTheFragmentRefusesWhatThePickerRefusesAndItsMatchNeedsTheTokenAndTheJob(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Kit <b>"one"</b>');
        $evil = '<script>alert(1)</script>"\'&';
        $one = $this->profiled($a, 'U1', ['product_title' => 'Kit ' . $evil, 'variant_title' => '"><img src=x onerror=alert(1)>', 'brand' => 'B ' . $evil]);
        $gone = $this->item('legacy', 0, 'Joined away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $gone]);
        $path = "/ui/review/products/{$sku}/map/options";
        $mapper = $this->signIn($this->uiUser('mapper'));
        $fragment = $mapper->get($path, ['channel' => 'alt', 'q' => 'kit']);
        self::assertSame(200, $fragment->status, $fragment->describe() . implode("\n", self::$log));
        self::assertSame([$one => 'found'], self::quickRows($fragment));
        self::assertSame(0, substr_count(strtolower($fragment->body), '<script'), 'a name is text');
        self::assertSame(0, substr_count(strtolower($fragment->body), '<img'));
        self::assertStringContainsString('Kit ' . htmlspecialchars($evil, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), $fragment->body);
        self::assertSame(['nosniff', 'DENY', 'no-store'], [$fragment->header('x-content-type-options'), $fragment->header('x-frame-options'), $fragment->header('cache-control')]);

        foreach (['viewer', 'buyer', 'admin'] as $job) {
            $r = $this->signIn($this->uiUser($job))->get($path, ['channel' => 'alt']);
            self::assertSame([403, 'role_not_allowed'], [$r->status, $r->errorCode()], $job);
            self::assertStringNotContainsString('data-qp-fragment', $r->body, $job);
        }
        $out = $this->browser()->get($path, ['channel' => 'alt']);
        self::assertSame(303, $out->status, 'no session: the sign-in page');
        self::assertStringStartsWith('/ui/login', (string) $out->location());
        $r = $mapper->get('/ui/review/products/999999/map/options', ['channel' => 'alt']);
        self::assertSame([404, 'unknown_item'], [$r->status, $r->errorCode()]);
        $r = $mapper->get("/ui/review/products/{$gone}/map/options", ['channel' => 'alt']);
        self::assertSame([404, 'item_joined'], [$r->status, $r->errorCode()]);
        foreach ([['channel' => 'nosuch'], [], ['channel' => ''], ['channel' => 'ALT']] as $query) {
            $r = $mapper->get($path, $query);
            self::assertSame([404, 'unknown_store'], [$r->status, $r->errorCode()], json_encode($query));
            self::assertStringContainsString(Words::ERROR['unknown_store'], $r->text());
        }
        self::assertSame(404, $mapper->send('GET', $path, ['channel' => ['alt']], [])->status, 'a list is not a store');
        foreach (['/ui/review/products/0/map/options', '/ui/review/products/abc/map/options', '/ui/review/products/' . str_repeat('9', 30) . '/map/options'] as $odd) {
            self::assertSame(404, $mapper->get($odd, ['channel' => 'alt'])->status, $odd);
        }
        foreach ([['q' => str_repeat('é', 500)], ['q' => "\xFF"], ['q' => '', 'cov' => 'nonsense', 'lq' => str_repeat('x', 500), 'ln' => '7'], ['cov' => 'missing-nosuch']] as $query) {
            self::assertSame(200, $mapper->get($path, ['channel' => 'alt'] + $query)->status, (string) json_encode($query, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertSame(200, $mapper->send('GET', $path, ['channel' => 'alt', 'q' => ['a'], 'cov' => ['x'], 'lq' => ['y']], [])->status);
        self::assertSame(405, $mapper->post($path, ['csrf' => $this->token($mapper), 'channel' => 'alt'])->status, 'it only reads');

        // The Match form is the decide route's: the person's own token and the job, or nothing happens.
        $form = self::quickRow($fragment, $one)['match'];
        self::assertNotNull($form);
        foreach ([array_diff_key($form, ['csrf' => 1]), ['csrf' => 'x' . $form['csrf']] + $form] as $i => $bad) {
            $r = $mapper->post("/ui/review/listing/{$one}/decide", $bad);
            self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], (string) $i);
        }
        $other = $this->signIn($this->uiUser('mapper'));
        self::assertSame(403, $other->post("/ui/review/listing/{$one}/decide", $form)->status, 'another person\'s token');
        $viewer = $this->signIn($this->uiUser('viewer'));
        $r = $viewer->post("/ui/review/listing/{$one}/decide", ['csrf' => $this->token($viewer)] + $form);
        self::assertSame([403, 'role_not_allowed'], [$r->status, $r->errorCode()]);
        self::assertSame(['unmapped', 0], [$this->link($one)['status'], (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action <> 'suggest'")],
            'nothing was linked');
    }
}
