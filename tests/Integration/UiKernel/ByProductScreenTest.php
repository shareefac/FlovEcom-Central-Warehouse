<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Output\CsvWriter;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\ByProductContext;
use CW\Ui\Controller\ByProductController;
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
 */
final class ByProductScreenTest extends KernelUiTestCase
{
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
        return array_map(static fn (\DOMNode $n): string => self::squash($n->textContent), iterator_to_array($xp->query('//table[contains(@class, "by-product")]/thead/tr/th')));
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
        foreach ($xp->query('//tr[@id="p-' . $sku . '"]/td') as $td) {
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
        self::assertSame(['All Products', 'Mapping', 'Store Products', 'By Item', 'Duplicates'], self::tabLabels($page), 'next to Store Products');
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
        self::assertSame(array_fill_keys(['Alt Store', 'Big Store', 'Cee Store'], Words::PRODUCT_STORE['none'] . ' ' . Words::BY_PRODUCT['map']),
            array_slice(self::cells($page, $charlie), 0, 3));
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
        self::assertSame([Words::BY_PRODUCT['map'] => "/ui/review/products/{$alpha}/map?channel=cee"], self::links($page, $alpha, 'Cee Store'));
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
        self::assertSame('waiting picked', $xp->evaluate('string(//tr[@id="p-' . $last . '"]/@class)'));
        self::assertSame(1, $xp->query('//tr[contains(@class, "picked")]')->length);
        self::assertStringContainsString(Words::BY_PRODUCT['back_here'], $at->text());
        self::assertStringNotContainsString(Words::BY_PRODUCT['pinned'], $at->text());
        $pinned = $web->get('/ui/review/products', ['cov' => 'suggested', 'at' => (string) $alpha]);
        self::assertSame([$alpha, $bravo], self::ids($pinned));
        self::assertStringContainsString(Words::BY_PRODUCT['pinned'], $pinned->text());
        $paged = $web->get('/ui/review/products', ['at' => (string) $last, 'page' => '1']);
        self::assertSame([$alpha, 50, 0], [self::ids($paged)[0], count(self::ids($paged)), (new \DOMXPath($paged->dom()))->query('//tr[contains(@class, "picked")]')->length],
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
        $picker = self::open($web, self::links($list, $sku, 'Alt Store')[Words::BY_PRODUCT['map']]);
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
        self::assertStringContainsString('picked', (new \DOMXPath($back->dom()))->evaluate('string(//tr[@id="p-' . $sku . '"]/@class)'));

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
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//tr[contains(@class, "picked")]')->length);

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
}
