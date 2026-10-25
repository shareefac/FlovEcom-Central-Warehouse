<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\ArrivalCheck;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\SimpleMatchController;
use CW\Ui\Words;

/**
 * Products › Simple matching (docs/decisions.md SM1-SM9; the owner's request of 25 Oct 2026: "a simple page with a table with 4
 * columns: one for CW, one for Vape and Go, one for Vape Big and one for Electrofag ... if nothing is matched there is a blank simple
 * dropdown ... click Apply and it matches, with a plus button so if there is a duplicate I can match that too"), through the real
 * /ui kernel as cw_app. Covered: the tab (the first matching tab), the four columns in the owner's order (a fourth store after them),
 * one row per warehouse product with its anchor, a cell's matched products (name on the store and its ID) and a match waiting for a
 * second OK, the search (CW number, name, barcode), the pager, who sees the add control (at once in an empty cell, behind "+" in a full
 * one), the options fragment (the store's products still waiting for a match: by name words and by ID; never a matched, landing,
 * ignored, waiting or other store's one; the item's own suggestions for an empty box), and Apply: through DecisionService as the
 * decide route's link (audit under the person, the way back to the row with the notice), a second product of the same store, the
 * refusals in words (another store's, not waiting, changed, a lead's, one decided on its own page, no token), the typed ID without the
 * script, the second OK of a store that sells warehouse stock, and the units of the item's own suggestion.
 */
final class SimpleMatchScreenTest extends KernelUiTestCase
{
    private const PATH = SimpleMatchController::PATH;

    /** @return array<string, Caller> the three stores as production names them, made out of the owner's order */
    private function stores(): array
    {
        $out = [];
        foreach (['electrofag' => 'Electrofag', 'vapebig' => 'Vape Big', 'vapeandgo' => 'Vape and Go'] as $code => $name) {
            $out[$code] = $this->site($code, 'shadow');
            self::$db->exec('UPDATE channel SET name = ? WHERE code = ?', [$name, $code]);
        }
        return $out;
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    private static function cw(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    /** Opens a link of a page (its path and query). */
    private static function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $web->get((string) parse_url($href, PHP_URL_PATH), $query);
    }

    /** @return list<string> the list's column heads */
    private static function heads(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => self::squash($n->textContent),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "simple-match")]/thead/tr/th')));
    }

    /** @return list<int> the warehouse products of the rows, in order */
    private static function ids(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "simple-match")]/tbody/tr')));
    }

    /** The matched products a cell lists, each as its text ("Name ID 4711 …"). @return list<string> */
    private static function matched(UiResponse $r, int $sku, string $code): array
    {
        return array_map(static fn (\DOMNode $li): string => self::squash($li->textContent),
            iterator_to_array(self::xp($r)->query('//tr[@id="m-' . $sku . '"]/td[@data-channel="' . $code . '"]//li[contains(@class, "sm-match")]')));
    }

    /** The cell's refusal line (null: none) and its link. @return array{0: ?string, 1: ?string} */
    private static function said(UiResponse $r, int $sku, string $code): array
    {
        $xp = self::xp($r);
        $p = $xp->query('//tr[@id="m-' . $sku . '"]/td[@data-channel="' . $code . '"]//p[contains(@class, "sm-said")]')->item(0);
        if (!$p instanceof \DOMElement) {
            return [null, null];
        }
        $a = $xp->query('.//a', $p)->item(0);
        return [self::squash($p->textContent), $a instanceof \DOMElement ? $a->getAttribute('href') : null];
    }

    /**
     * The add control of a cell: the fields its form sends as drawn, whether it sits behind the "+", its options address, the
     * datalist its box names, and the button's words; null when the cell has none.
     *
     * @return array{fields: array<string, string>, plus: bool, action: string, options: string, list: bool, button: string, open: bool}|null
     */
    private static function addForm(UiResponse $r, int $sku, string $code): ?array
    {
        $xp = self::xp($r);
        $form = $xp->query('//tr[@id="m-' . $sku . '"]/td[@data-channel="' . $code . '"]//form[@data-sm-add]')->item(0);
        if (!$form instanceof \DOMElement) {
            return null;
        }
        $fields = [];
        foreach ($xp->query('.//input[@name]', $form) as $in) {
            /** @var \DOMElement $in */
            $fields[$in->getAttribute('name')] = $in->getAttribute('value');
        }
        $box = $xp->query('.//input[@data-sm-pick]', $form)->item(0);
        $listId = $box instanceof \DOMElement ? $box->getAttribute('list') : '';
        $details = $xp->query('ancestor::details[contains(@class, "sm-more")]', $form)->item(0);
        return ['fields' => $fields, 'plus' => $details instanceof \DOMElement, 'open' => $details instanceof \DOMElement && $details->hasAttribute('open'),
            'action' => $form->getAttribute('action'), 'options' => $form->getAttribute('data-options'),
            'list' => $listId !== '' && $xp->query('.//datalist[@id="' . $listId . '"]', $form)->length === 1,
            'button' => self::squash($xp->evaluate('string(.//button[@type="submit"])', $form))];
    }

    /** The options fragment of one item on one store, as app.js asks for it. */
    private static function options(KernelBrowser $web, int $sku, string $code, ?string $q = null): UiResponse
    {
        return $web->get(self::PATH . '/options', ['sku' => $sku, 'channel' => $code] + ($q === null ? [] : ['q' => $q]));
    }

    /**
     * The fragment's choices, in order: listing id => its label (the option's value), map_version, open suggestion and ID.
     *
     * @return array<int, array{label: string, version: string, proposal: string, option: string}>
     */
    private static function choices(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//*[@data-sm-fragment]//datalist/option[@data-listing-id]') as $o) {
            /** @var \DOMElement $o */
            $out[(int) $o->getAttribute('data-listing-id')] = ['label' => $o->getAttribute('value'), 'version' => $o->getAttribute('data-map-version'),
                'proposal' => $o->getAttribute('data-proposal-id'), 'option' => $o->getAttribute('data-option')];
        }
        return $out;
    }

    /** The fragment's one line under the box ('' when it says nothing). */
    private static function says(UiResponse $r): string
    {
        return self::squash(self::xp($r)->evaluate('string(//*[@data-sm-fragment]//*[@data-sm-says])'));
    }

    /** How many decisions people made (the computer's suggestion is written as a decision of its own, `suggest`). */
    private function made(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action <> 'suggest'");
    }

    private function optionOf(int $listing): string
    {
        return (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$listing]);
    }

    /**
     * Apply in a cell as app.js sends it: the cell's form as drawn, with the hidden fields of the choice the fragment offered for the
     * product's ID ($over last: a test's own changes).
     *
     * @param array<string, string> $over
     */
    private function apply(KernelBrowser $web, UiResponse $page, int $sku, string $code, int $listing, array $over = []): UiResponse
    {
        $form = self::addForm($page, $sku, $code);
        self::assertNotNull($form, "the cell of {$code} has the add control");
        $choice = self::choices(self::options($web, $sku, $code, $this->optionOf($listing)))[$listing] ?? null;
        self::assertNotNull($choice, "listing {$listing} is offered");
        $fields = ['listing_id' => (string) $listing, 'expected_map_version' => $choice['version'], 'proposal_id' => $choice['proposal'], 'pick' => $choice['label']]
            + $form['fields'];
        return $web->post(self::PATH . '/apply', $over + $fields);
    }

    /** A redirect's address in parts. @return array{path: string, query: array<string, string>, fragment: string} */
    private static function went(UiResponse $r): array
    {
        self::assertSame(303, $r->status, $r->describe());
        $to = (string) $r->location();
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return ['path' => (string) parse_url($to, PHP_URL_PATH), 'query' => $query, 'fragment' => (string) parse_url($to, PHP_URL_FRAGMENT)];
    }

    /** @return array<string, string> the query of a link */
    private static function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    private static function toast(UiResponse $r): string
    {
        return self::squash(self::xp($r)->evaluate('string(//p[contains(@class, "toast")])'));
    }

    // ------------------------------------------------------------------------------------------

    public function testTheTabIsTheFirstMatchingTabAndTheTableHasFourColumnsInTheOwnersOrder(): void
    {
        $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $gone = $this->item('legacy', 0, 'Old kit');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$alpha, $gone]);

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame(Words::MENU['simple_match'], self::currentTab($page));
        self::assertSame(['All Products', Words::MENU['simple_match'], 'Mapping', 'Store Products', 'By Item', 'New Variants', 'Duplicates', 'Families', 'Multipacks'],
            self::tabLabels($page), 'the first of the matching tabs; the old pages stay');
        $xp = self::xp($page);
        self::assertSame(Words::MENU['simple_match'], self::squash($xp->evaluate('string(//main//h1)')));
        self::assertSame(Words::PAGE_INTRO['simple_match'][0], self::squash($xp->evaluate('string(//header[contains(@class, "page-head")]//p[@class="lede"])')),
            'one short line under the title');
        self::assertSame([Words::SIMPLE_MATCH['item'], 'Vape and Go', 'Vape Big', 'Electrofag'], self::heads($page), 'four columns, in the owner\'s order');
        self::assertSame([$alpha, $bravo], self::ids($page), 'by CW number; a product joined into another one is not listed');
        self::assertSame(self::cw($alpha) . ' Alpha kit', self::squash($xp->evaluate('string(//tr[@id="m-' . $alpha . '"]/th)')));
        self::assertSame(['vapeandgo', 'vapebig', 'electrofag'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-channel'),
            iterator_to_array($xp->query('//tr[@id="m-' . $alpha . '"]/td'))));
        self::assertSame(['Vape and Go', 'Vape Big', 'Electrofag'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-label'),
            iterator_to_array($xp->query('//tr[@id="m-' . $alpha . '"]/td'))), 'on a phone each cell says its store');
        // Nothing of the other pages: no tick boxes, no bulk bar, no filter chips, no help to unfold.
        self::assertSame(0, $xp->query('//main//input[@type="checkbox"]')->length);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "pills")]')->length);
        self::assertSame(0, $xp->query('//main//*[contains(@class, "bulkbar")]')->length);
        self::assertSame(0, $xp->query('//main//details[contains(@class, "help")]')->length);
        self::assertFalse($page->hasForm('/bulk'));

        // Another store (the channel table has a fourth row): a column after the three, whatever its name.
        $this->site('zed', 'shadow');
        self::$db->exec("UPDATE channel SET name = 'Another Store' WHERE code = 'zed'");
        self::assertSame([Words::SIMPLE_MATCH['item'], 'Vape and Go', 'Vape Big', 'Electrofag', 'Another Store'], self::heads($web->get(self::PATH)));
    }

    public function testACellShowsEveryMatchedProductWithItsNameAndIdAndAWaitingOneSaysSo(): void
    {
        $s = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $counted = $this->item('strict', 0, 'Counted kit');
        $a1 = $this->profiled($s['vapebig'], '4711', ['product_title' => 'Hayati Pro Max 4000', 'variant_title' => 'Blue Razz', 'units_30d' => 9], $alpha);
        $this->profiled($s['vapebig'], '4712', ['product_title' => 'Hayati Pro Max 4000', 'variant_title' => 'Blue Razz copy', 'units_30d' => 2], $alpha);
        $this->profiled($s['vapeandgo'], '88', ['product_title' => 'Hayati ten pack'], $alpha, 10);
        // A match to an item whose stock the warehouse counts waits for a second OK.
        $w = $this->profiled($s['electrofag'], 'E-1', ['product_title' => 'Counted on Electrofag']);
        self::assertSame('pending_second', $this->decide($lead, 'link', $w, ['sku_id' => $counted])['state']);

        $page = $this->signIn($this->uiUser('mapper'))->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        $id = static fn (string $o): string => Words::say('SIMPLE_MATCH', 'id', $o);
        self::assertSame(['Hayati Pro Max 4000 Blue Razz ' . $id('4711'), 'Hayati Pro Max 4000 Blue Razz copy ' . $id('4712')], self::matched($page, $alpha, 'vapebig'),
            'each matched product with its name on the store and its ID, the best seller first');
        self::assertSame(['Hayati ten pack ' . $id('88') . ' ' . Words::saleUses(10)], self::matched($page, $alpha, 'vapeandgo'));
        self::assertSame([], self::matched($page, $alpha, 'electrofag'));
        self::assertSame(['Counted on Electrofag ' . $id('E-1') . ' ' . Words::SIMPLE_MATCH['waiting']], self::matched($page, $counted, 'electrofag'));
        $xp = self::xp($page);
        self::assertSame('/ui/review/listing/' . $a1, $xp->evaluate('string(//tr[@id="m-' . $alpha . '"]/td[@data-channel="vapebig"]//li[1]//a/@href)'),
            'the name opens the product\'s own page, where a match is changed');
        self::assertFalse($page->hasForm('/decide'), 'no unmatch or change here');
        self::assertSame(['unmapped'], [$this->link($w)['status']], 'looking decides nothing');
    }

    public function testTheSearchFindsByCwNumberNameAndBarcode(): void
    {
        $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $charlie = $this->item('legacy', 0, 'Charlie pod');
        self::$db->exec("UPDATE sku SET brand = 'Zed Co' WHERE id = ?", [$charlie]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, source) VALUES ('5060000000017', ?, 'test')", [$bravo]);
        $web = $this->signIn($this->uiUser('viewer'));
        self::assertSame([$charlie], self::ids($web->get(self::PATH, ['q' => self::cw($charlie)])), 'a CW number');
        self::assertSame([$charlie], self::ids($web->get(self::PATH, ['q' => 'cw ' . $charlie])), 'a CW number as people type it');
        self::assertSame([$bravo], self::ids($web->get(self::PATH, ['q' => 'bravo'])), 'a word of the name');
        self::assertSame([$alpha, $bravo], self::ids($web->get(self::PATH, ['q' => 'KIT'])), 'any case');
        self::assertSame([$charlie], self::ids($web->get(self::PATH, ['q' => 'zed'])), 'the brand');
        self::assertSame([$bravo], self::ids($web->get(self::PATH, ['q' => '5060000000017'])), 'a barcode');
        $none = $web->get(self::PATH, ['q' => 'nothing like it']);
        self::assertSame([], self::ids($none));
        self::assertStringContainsString(Words::SIMPLE_MATCH['empty_search'], $none->text());
        self::assertContains(self::PATH, $none->hrefs(), 'the way back to every product');
        self::assertSame('nothing like it', self::xp($none)->evaluate('string(//main//form[@method="get"]//input[@name="q"]/@value)'), 'the search is kept in its box');
    }

    public function testThePagerCarriesThePageAndTheSearch(): void
    {
        $this->stores();
        $ids = [];
        for ($i = 1; $i <= 120; $i++) {
            $ids[] = self::makeSku(sprintf('Pager kit %03d', $i));
        }
        $web = $this->signIn($this->uiUser('mapper'));
        $p1 = $web->get(self::PATH);
        self::assertSame(array_slice($ids, 0, 50), self::ids($p1), '50 rows a page');
        $next = (string) self::xp($p1)->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="next"]/@href)');
        self::assertSame('2', self::queryOf($next)['page'] ?? null, 'Next on page 1 is page 2');
        self::assertSame('', (string) self::xp($p1)->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="prev"]/@href)'), 'no Previous on page 1');

        $p2 = self::open($web, $next);
        self::assertSame(array_slice($ids, 50, 50), self::ids($p2));
        $xp = self::xp($p2);
        $next2 = (string) $xp->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="next"]/@href)');
        $prev2 = (string) $xp->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="prev"]/@href)');
        self::assertSame('3', self::queryOf($next2)['page'] ?? null, 'Next on page 2 is page 3');
        self::assertSame(array_slice($ids, 0, 50), self::ids(self::open($web, $prev2)), 'Previous on page 2 is page 1');
        // The add controls of page 2 bring the person back to page 2.
        self::assertSame('2', self::addForm($p2, $ids[50], 'vapebig')['fields']['page'] ?? null);

        $p3 = self::open($web, $next2);
        self::assertSame(array_slice($ids, 100), self::ids($p3));
        self::assertSame('', (string) self::xp($p3)->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="next"]/@href)'), 'no Next on the last page');

        // A search pages too, and its links keep it.
        $found = $web->get(self::PATH, ['q' => 'pager kit']);
        $link = (string) self::xp($found)->evaluate('string(//nav[contains(@class, "pager")]//a[@rel="next"]/@href)');
        self::assertSame(['q' => 'pager kit', 'page' => '2'], self::queryOf($link));
        self::assertSame(array_slice($ids, 50, 50), self::ids(self::open($web, $link)));
        self::assertSame('pager kit', self::addForm($found, $ids[0], 'electrofag')['fields']['q'] ?? null, 'Apply comes back to the same search');
    }

    public function testAPersonWhoOnlyLooksHasNoAddControlAndIsRefused(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $viewer = $this->signIn($this->uiUser('viewer'));
        $page = $viewer->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe());
        self::assertNull(self::addForm($page, $alpha, 'vapebig'));
        self::assertFalse($page->hasForm(self::PATH . '/apply'));
        self::assertSame(0, self::xp($page)->query('//main//datalist | //main//details[contains(@class, "sm-more")]')->length);
        self::assertStringContainsString(sprintf(Words::UI['look_only'], Words::whoCan('mapping.decide')), $page->text(), 'the page says who matches');

        self::assertSame(403, self::options($viewer, $alpha, 'vapebig', 'elux')->status);
        $r = $viewer->post(self::PATH . '/apply', ['csrf' => $this->token($viewer), 'sku_id' => (string) $alpha, 'channel' => 'vapebig', 'listing_id' => (string) $l,
            'expected_map_version' => (string) $this->version($l), 'proposal_id' => '', 'pick' => '5001']);
        self::assertSame(403, $r->status);
        self::assertSame('role_not_allowed', $r->errorCode());
        self::assertSame(0, $this->made());
        self::assertSame('unmapped', $this->link($l)['status']);
    }

    public function testAnEmptyCellHasTheAddControlAtOnceAndAFullOneHasItBehindThePlus(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $this->profiled($s['vapebig'], 'B1', ['product_title' => 'Alpha on Big'], $alpha);
        $page = $this->signIn($this->uiUser('mapper'))->get(self::PATH, ['q' => 'alpha']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));

        $empty = self::addForm($page, $alpha, 'vapeandgo');
        self::assertNotNull($empty);
        self::assertFalse($empty['plus'], 'an empty cell shows the box at once');
        self::assertSame(self::PATH . '/apply', $empty['action']);
        self::assertTrue($empty['list'], 'one box that is a plain dropdown (its own list)');
        self::assertSame(Words::SIMPLE_MATCH['apply'], $empty['button']);
        self::assertSame(self::PATH . '/options?sku=' . $alpha . '&channel=vapeandgo', $empty['options']);
        $fields = $empty['fields'];
        self::assertNotSame('', $fields['csrf'] ?? '');
        unset($fields['csrf']);
        self::assertSame(['sku_id' => (string) $alpha, 'channel' => 'vapeandgo', 'q' => 'alpha', 'listing_id' => '', 'expected_map_version' => '', 'proposal_id' => '', 'pick' => ''],
            $fields);

        $full = self::addForm($page, $alpha, 'vapebig');
        self::assertNotNull($full, 'a second product of the same store can be added');
        self::assertTrue($full['plus'], 'behind the "+"');
        self::assertFalse($full['open']);
        $summary = self::squash(self::xp($page)->evaluate('string(//tr[@id="m-' . $alpha . '"]/td[@data-channel="vapebig"]//details[contains(@class, "sm-more")]/summary)'));
        self::assertStringStartsWith(Words::SIMPLE_MATCH['add_more'], $summary);
    }

    public function testTheOptionsAreTheStoresWaitingProductsByNameWordsAndExactId(): void
    {
        $s = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $counted = $this->item('strict', 0, 'Counted kit');
        $waiting = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 5]);
        $suggested = $this->queued($s['vapebig'], '5006', 'Check', $other, ['product_title' => 'Elux Legend', 'variant_title' => 'Cherry']);
        $matched = $this->profiled($s['vapebig'], '5002', ['product_title' => 'Elux Legend', 'variant_title' => 'Red'], $other);
        $landing = $this->profiled($s['vapebig'], '5003', ['product_title' => 'Elux Legend', 'variant_title' => 'All flavours']);
        $ignored = $this->profiled($s['vapebig'], '5004', ['product_title' => 'Elux Legend', 'variant_title' => 'Grey']);
        self::$db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$landing]);
        self::$db->exec("UPDATE channel_listing SET status = 'ignored' WHERE id = ?", [$ignored]);
        $pending = $this->profiled($s['vapebig'], '5005', ['product_title' => 'Elux Legend', 'variant_title' => 'Mint']);
        self::assertSame('pending_second', $this->decide($lead, 'link', $pending, ['sku_id' => $counted])['state']);
        $elsewhere = $this->profiled($s['electrofag'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);

        $web = $this->signIn($this->uiUser('mapper'));
        $r = self::options($web, $alpha, 'vapebig', 'elux legend');
        self::assertSame(200, $r->status, $r->describe() . implode("\n", self::$log));
        $xp = self::xp($r);
        self::assertSame(1, $xp->query('//*[@data-sm-fragment]')->length, 'its root says what it is (app.js shows nothing else)');
        self::assertSame(0, $xp->query('//nav[@aria-label="Main"]')->length, 'a fragment, not a page');
        $c = self::choices($r);
        self::assertSame([$waiting, $suggested], array_keys($c), 'only this store\'s products still waiting for a match, best sellers first');
        self::assertSame(['label' => Words::say('SIMPLE_MATCH', 'option', 'Elux Legend Blue Razz', '5001'), 'version' => (string) $this->version($waiting), 'proposal' => '',
            'option' => '5001'], $c[$waiting]);
        self::assertSame((string) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$suggested]), $c[$suggested]['proposal'],
            'an open suggestion of another item is named: the decision must name what is open');
        self::assertSame('', self::says($r));

        self::assertSame([$waiting], array_keys(self::choices(self::options($web, $alpha, 'vapebig', '5001'))), 'by its ID: this store\'s only');
        self::assertSame([$waiting], array_keys(self::choices(self::options($web, $alpha, 'vapebig', 'blue razz'))), 'words of the name on the store');
        foreach (['5002' => 'matched', '5003' => 'a landing page', '5004' => 'ignored', '5005' => 'waiting for a second OK'] as $option => $what) {
            $none = self::options($web, $alpha, 'vapebig', (string) $option);
            self::assertSame([], self::choices($none), "{$what}: not offered");
            self::assertSame(Words::say('SIMPLE_MATCH', 'found_none', 'Vape Big'), self::says($none));
        }
        self::assertSame([$elsewhere], array_keys(self::choices(self::options($web, $alpha, 'electrofag', 'elux'))), 'each store its own');

        // At most a small list.
        for ($i = 1; $i <= 25; $i++) {
            $this->profiled($s['vapeandgo'], 'F' . $i, ['product_title' => 'Bulk flavour ' . $i]);
        }
        self::assertCount(SimpleMatchController::OPTIONS_MAX, self::choices(self::options($web, $alpha, 'vapeandgo', 'bulk flavour')));
        self::assertSame(20, SimpleMatchController::OPTIONS_MAX);

        // Odd requests: a store or an item that is not one.
        self::assertSame(404, self::options($web, $alpha, 'nope', 'elux')->status);
        self::assertSame(404, self::options($web, $alpha + 1000, 'vapebig', 'elux')->status);
    }

    public function testTheOptionsOfAnEmptyBoxAreTheItemsOwnSuggestions(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $mine = $this->queued($s['vapebig'], '7001', 'Check', $alpha, ['product_title' => 'Alpha on Big']);
        $this->queued($s['vapebig'], '7002', 'Check', $bravo, ['product_title' => 'Bravo on Big']);
        $this->profiled($s['vapebig'], '7003', ['product_title' => 'Nobody on Big']);
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertSame([$mine], array_keys(self::choices(self::options($web, $alpha, 'vapebig'))), 'the item\'s own suggestions on that store');
        self::assertSame([$mine], array_keys(self::choices(self::options($web, $alpha, 'vapebig', ' '))), 'also for a box of spaces');
        $none = self::options($web, $alpha, 'electrofag');
        self::assertSame([], self::choices($none));
        self::assertSame(Words::SIMPLE_MATCH['type_more'], self::says($none), 'nothing suggested: what to type');
    }

    public function testApplyMatchesThroughTheDecisionServiceAndComesBackToTheRowWithTheNotice(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $mapper = $this->uiUser('mapper');
        $web = $this->signIn($mapper);
        $page = $web->get(self::PATH, ['q' => 'alpha']);

        $r = $this->apply($web, $page, $alpha, 'vapebig', $l);
        $to = self::went($r);
        self::assertSame([self::PATH, ['q' => 'alpha', 'notice' => 'decided_link', 'prev' => (string) $l, 'at' => (string) $alpha], 'm-' . $alpha],
            [$to['path'], $to['query'], $to['fragment']], 'back to the same search, at the row');
        self::assertSame(['sku_id' => $alpha, 'units_per_item' => 1, 'status' => 'mapped'], array_intersect_key($this->link($l), ['sku_id' => 1, 'units_per_item' => 1, 'status' => 1]));
        $d = self::$db->one("SELECT action, sku_id, decided_by, state, proposal_id FROM match_decision WHERE listing_id = ? AND action <> 'suggest'", [$l]);
        self::assertSame(['action' => 'link', 'sku_id' => $alpha, 'decided_by' => $mapper['id'], 'state' => 'applied', 'proposal_id' => null], $d,
            'one decision, under the person\'s name');
        self::assertSame($mapper['id'], (int) self::$db->value("SELECT staff_user_id FROM audit_log WHERE action = 'mapping.link' AND entity_id = ?", [(string) $l]));

        $back = self::open($web, (string) $r->location());
        self::assertSame(200, $back->status, $back->describe());
        self::assertSame(Words::say('MATCH_NOTICE', 'decided_link', '"Elux Legend Blue Razz"', self::cw($alpha)), self::toast($back));
        self::assertSame(['Elux Legend Blue Razz ' . Words::say('SIMPLE_MATCH', 'id', '5001')], self::matched($back, $alpha, 'vapebig'));
        self::assertStringContainsString('here', (string) self::xp($back)->evaluate('string(//tr[@id="m-' . $alpha . '"]/@class)'), 'the row it came back to is marked');

        // The page number comes back too.
        $l2 = $this->profiled($s['electrofag'], '6001', ['product_title' => 'Alpha on Electrofag']);
        $to = self::went($this->apply($web, $page, $alpha, 'electrofag', $l2, ['page' => '2']));
        self::assertSame('2', $to['query']['page'] ?? null);
    }

    public function testASecondProductOfTheSameStoreCanBeAddedToTheSameItem(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $first = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $copy = $this->profiled($s['vapebig'], '5099', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz old page']);
        $web = $this->signIn($this->uiUser('mapper'));
        self::went($this->apply($web, $web->get(self::PATH), $alpha, 'vapebig', $first));
        $page = $web->get(self::PATH);
        self::assertTrue(self::addForm($page, $alpha, 'vapebig')['plus'] ?? false);
        self::went($this->apply($web, $page, $alpha, 'vapebig', $copy));
        self::assertSame([$alpha, $alpha], [$this->link($first)['sku_id'], $this->link($copy)['sku_id']]);
        self::assertCount(2, self::matched($web->get(self::PATH), $alpha, 'vapebig'), 'both pages of the store sell the item');
    }

    public function testAnotherStoresANotWaitingAndAChangedProductAreRefusedInWordsAndNothingIsSaved(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $big = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $ef = $this->profiled($s['electrofag'], '6001', ['product_title' => 'On Electrofag']);
        $mapped = $this->profiled($s['vapebig'], '5002', ['product_title' => 'Matched already'], $other);
        $landing = $this->profiled($s['vapebig'], '5003', ['product_title' => 'A landing page']);
        self::$db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$landing]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);
        $form = self::addForm($page, $alpha, 'vapebig')['fields'] ?? [];
        $send = static fn (int $listing, array $over = []): UiResponse => $web->post(self::PATH . '/apply',
            $over + ['listing_id' => (string) $listing, 'expected_map_version' => (string) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listing]),
                'proposal_id' => ''] + $form);
        $refused = function (UiResponse $r, string $said, string $words, bool $link = false, ?int $listing = null) use ($web, $alpha): void {
            $to = self::went($r);
            self::assertSame([self::PATH, $said, (string) $alpha, 'm-' . $alpha], [$to['path'], $to['query']['said'] ?? null, $to['query']['at'] ?? null, $to['fragment']], $said);
            $back = self::open($web, (string) $r->location());
            self::assertSame(200, $back->status, $back->describe());
            [$text, $href] = self::said($back, $alpha, 'vapebig');
            self::assertSame($words . ($link ? ' ' . Words::SIMPLE_MATCH['open_page'] : ''), $text, $said);
            self::assertSame($link ? '/ui/review/listing/' . $listing : null, $href, $said);
            self::assertSame('', self::toast($back), 'a refusal is never the green "done" toast');
        };

        $refused($send($ef), 'other_store', Words::say('SIMPLE_MATCH', 'said_other_store', 'Vape Big'));
        $refused($send($mapped), 'not_waiting', Words::say('SIMPLE_MATCH', 'said_not_waiting', 'Vape Big'));
        $refused($send($landing), 'not_waiting', Words::say('SIMPLE_MATCH', 'said_not_waiting', 'Vape Big'));
        $refused($send($big, ['expected_map_version' => (string) ($this->version($big) + 1)]), 'changed', Words::SIMPLE_MATCH['said_changed']);
        $refused($send($big, ['listing_id' => '999999']), 'choose', Words::SIMPLE_MATCH['said_choose']);
        // A suggestion that arrived after the list was read (a proposal does not move map_version): the decision must name it (I7).
        $this->propose($big, 'Check', $other);
        $refused($send($big), 'changed', Words::SIMPLE_MATCH['said_changed']);
        // Clues that disagree are a matching lead's: a Matcher's Apply is refused, with the way to the product's page.
        $conflict = $this->queued($s['vapebig'], '5009', 'Conflict', $other, ['product_title' => 'Clues disagree']);
        $refused($send($conflict, ['proposal_id' => (string) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$conflict])]),
            'not_allowed', Words::SIMPLE_MATCH['said_not_allowed'], true, $conflict);

        self::assertSame(0, $this->made(), 'nothing was decided');
        self::assertSame([null, $other, null], [$this->link($big)['sku_id'], $this->link($mapped)['sku_id'], $this->link($ef)['sku_id']]);
        // An item that was joined into another one meanwhile.
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$other, $alpha]);
        $to = self::went($send($ef, ['listing_id' => (string) $this->profiled($s['vapebig'], '5010', ['product_title' => 'Fresh'])]));
        self::assertSame('gone', $to['query']['said'] ?? null);
        self::assertSame(0, $this->made());
    }

    public function testAFormWithoutItsTokenIsRefused(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend']);
        $web = $this->signIn($this->uiUser('mapper'));
        $fields = self::addForm($web->get(self::PATH), $alpha, 'vapebig')['fields'] ?? [];
        $fields = ['listing_id' => (string) $l, 'expected_map_version' => (string) $this->version($l)] + $fields;
        foreach ([['csrf' => 'not-the-token'] + $fields, array_diff_key($fields, ['csrf' => 1])] as $form) {
            $r = $web->post(self::PATH . '/apply', $form);
            self::assertSame(403, $r->status, $r->describe());
            self::assertSame('csrf', $r->errorCode());
            self::assertStringContainsString(Words::ERROR['csrf'], $r->text());
        }
        self::assertSame(0, $this->made());
    }

    public function testATypedIdWithoutTheScriptWorksOnlyForOneWaitingProductOfThatStore(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $big = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $this->profiled($s['vapebig'], '5002', ['product_title' => 'Matched already'], $other);
        $this->profiled($s['electrofag'], '6001', ['product_title' => 'On Electrofag']);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);

        // Without the script nothing fills the hidden fields: what was typed is the store's ID.
        foreach (['', 'Elux', '5002', '6001', '9999', '50'] as $typed) {
            $r = $web->post(self::PATH . '/apply', ['pick' => $typed] + (self::addForm($page, $bravo, 'vapebig')['fields'] ?? []));
            $to = self::went($r);
            self::assertSame(['choose', (string) $bravo], [$to['query']['said'] ?? null, $to['query']['at'] ?? null], "typed \"{$typed}\"");
            $back = self::open($web, (string) $r->location());
            self::assertSame(Words::SIMPLE_MATCH['said_choose'], self::said($back, $bravo, 'vapebig')[0], "typed \"{$typed}\"");
            self::assertSame($typed, self::addForm($back, $bravo, 'vapebig')['fields']['pick'] ?? null, 'what was typed is kept in the box');
        }
        self::assertSame(0, $this->made());

        $to = self::went($web->post(self::PATH . '/apply', ['pick' => ' 5001 '] + (self::addForm($page, $alpha, 'vapebig')['fields'] ?? [])));
        self::assertSame(['decided_link', (string) $big], [$to['query']['notice'] ?? null, $to['query']['prev'] ?? null]);
        self::assertSame($alpha, $this->link($big)['sku_id']);
    }

    public function testAStoreThatSellsWarehouseStockSendsTheMatchForASecondOk(): void
    {
        $s = $this->stores();
        $counted = $this->item('strict', 0, 'Counted kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $web = $this->signIn($this->uiUser('mapper'));
        $r = $this->apply($web, $web->get(self::PATH), $counted, 'vapebig', $l);
        $to = self::went($r);
        self::assertSame('pending_second', $to['query']['notice'] ?? null);
        $d = self::$db->one("SELECT state, needs_second FROM match_decision WHERE listing_id = ? AND action = 'link'", [$l]);
        self::assertSame('pending_second', $d['state']);
        self::assertContains('protected_sku', json_decode((string) $d['needs_second'], true));
        self::assertSame(['sku_id' => null, 'status' => 'unmapped'], array_intersect_key($this->link($l), ['sku_id' => 1, 'status' => 1]), 'nothing is live yet');
        $back = self::open($web, (string) $r->location());
        self::assertSame(Words::say('MATCH_NOTICE', 'pending_second', '"Elux Legend Blue Razz"'), self::toast($back));
        self::assertSame(['Elux Legend Blue Razz ' . Words::say('SIMPLE_MATCH', 'id', '5001') . ' ' . Words::SIMPLE_MATCH['waiting']], self::matched($back, $counted, 'vapebig'));
    }

    public function testAProductDecidedOneAtATimeIsSaidSoAndRefusedWithTheWayToItsPage(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        // Found by the check on arrival (no AI looked at it): decided one at a time, on its own page (M64, DG2).
        $run = $this->proposals->run('arrival-t1', ArrivalCheck::SOURCE);
        $proposal = $this->proposals->add(Caller::system('test'), $l, $run, ['band' => 'Check', 'proposed_sku_id' => $alpha], true)['proposal_id'];
        $web = $this->signIn($this->uiUser('mapper'));
        $c = self::choices(self::options($web, $alpha, 'vapebig', '5001'));
        self::assertSame(Words::say('SIMPLE_MATCH', 'option_own_page', 'Elux Legend Blue Razz', '5001'), $c[$l]['label'] ?? null, 'the list says so before Apply');
        self::assertSame((string) $proposal, $c[$l]['proposal']);

        $r = $this->apply($web, $web->get(self::PATH), $alpha, 'vapebig', $l);
        self::assertSame('own_page', self::went($r)['query']['said'] ?? null);
        $back = self::open($web, (string) $r->location());
        self::assertSame([Words::SIMPLE_MATCH['said_own_page'] . ' ' . Words::SIMPLE_MATCH['open_page'], '/ui/review/listing/' . $l], self::said($back, $alpha, 'vapebig'));
        self::assertSame(0, $this->made());
        self::assertSame('suggested', $this->link($l)['status']);
    }

    public function testTheUnitsComeFromTheItemsOwnSuggestion(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $mine = $this->queued($s['vapebig'], '5001', 'Check', $alpha, ['product_title' => 'Alpha ten pack'], ['ai_units_per_item' => 10]);
        $theirs = $this->queued($s['vapebig'], '5002', 'Check', $other, ['product_title' => 'Other ten pack'], ['ai_units_per_item' => 10]);
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertSame('pending_second', self::went($this->apply($web, $web->get(self::PATH), $alpha, 'vapebig', $mine))['query']['notice'] ?? null,
            '1 sale = 10 waits for a second OK');
        self::assertSame(10, (int) self::$db->value("SELECT units_per_item FROM match_decision WHERE listing_id = ? AND action = 'link'", [$mine]), 'the units of this item\'s own suggestion');
        self::assertSame('decided_link', self::went($this->apply($web, $web->get(self::PATH), $alpha, 'vapebig', $theirs))['query']['notice'] ?? null);
        self::assertSame(['sku_id' => $alpha, 'units_per_item' => 1], array_intersect_key($this->link($theirs), ['sku_id' => 1, 'units_per_item' => 1]),
            'another item\'s suggestion gives no units for this one');
    }
}
