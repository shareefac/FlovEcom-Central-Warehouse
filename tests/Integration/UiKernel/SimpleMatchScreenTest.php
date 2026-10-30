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
 * script, the second OK of a store that sells warehouse stock, and the units of the item's own suggestion. SM11 (26 Oct 2026): the box's
 * list is the page's own (no native <datalist>), each choice on two lines (the full name, then its ID, its status on the store, its
 * strength and its mark), and a matched cell says each product's status on its store after its ID.
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

    /** What a matched product says after its name (SM11): "ID 4711 · <its status on the store>", "status not known" by default. */
    private static function idLine(string $option, ?string $status = null): string
    {
        return Words::say('SIMPLE_MATCH', 'id', $option) . ' · ' . ($status ?? Words::SIMPLE_MATCH['status_unknown']);
    }

    /** The matched products a cell lists, each as its text ("Name ID 4711 · Published …"). @return list<string> */
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
     * box's own list (SM11: a hidden `ul` app.js draws under the box, the box's id + "-list"; never a native <datalist>), and the
     * button's words; null when the cell has none.
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
        $boxId = $box instanceof \DOMElement ? $box->getAttribute('id') : '';
        // Every cell's picker is a fold of the page's one group (docs/decisions.md W12.12): "+" in a cell with a product, "Match" in an empty one.
        $details = $xp->query('ancestor::details[contains(@class, "sm-more")][@name="sm-pick"]', $form)->item(0);
        $plus = $details instanceof \DOMElement && str_starts_with(self::squash($xp->evaluate('string(summary)', $details)), Words::SIMPLE_MATCH['add_more']);
        return ['fields' => $fields, 'plus' => $plus, 'open' => $plus && $details->hasAttribute('open'),
            'action' => $form->getAttribute('action'), 'options' => $form->getAttribute('data-options'),
            'list' => $boxId !== '' && !$box->hasAttribute('list') && $xp->query('.//datalist', $form)->length === 0
                && $xp->query('.//ul[@id="' . $boxId . '-list"][@data-sm-list][@role="listbox"][@hidden]', $form)->length === 1,
            'button' => self::squash($xp->evaluate('string(.//button[@type="submit"])', $form))];
    }

    /** The options fragment of one item on one store, as app.js asks for it. */
    private static function options(KernelBrowser $web, int $sku, string $code, ?string $q = null): UiResponse
    {
        return $web->get(self::PATH . '/options', ['sku' => $sku, 'channel' => $code] + ($q === null ? [] : ['q' => $q]));
    }

    /**
     * The fragment's choices, in order: listing id => its label (the box's words when it is chosen: `data-label`), map_version, open
     * suggestion and ID.
     *
     * @return array<int, array{label: string, version: string, proposal: string, option: string}>
     */
    private static function choices(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//*[@data-sm-fragment]//ul[@data-sm-choices]/li[@data-listing-id]') as $o) {
            /** @var \DOMElement $o */
            $out[(int) $o->getAttribute('data-listing-id')] = ['label' => $o->getAttribute('data-label'), 'version' => $o->getAttribute('data-map-version'),
                'proposal' => $o->getAttribute('data-proposal-id'), 'option' => $o->getAttribute('data-option')];
        }
        return $out;
    }

    /**
     * The two lines of each of the fragment's choices (SM11): listing id => [the name on the store, the line under it].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function lines(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//*[@data-sm-fragment]//ul[@data-sm-choices]/li[@data-listing-id]') as $o) {
            /** @var \DOMElement $o */
            $out[(int) $o->getAttribute('data-listing-id')] = [self::squash($xp->evaluate('string(./span[contains(@class, "sm-opt-name")])', $o)),
                self::squash($xp->evaluate('string(./span[contains(@class, "sm-opt-meta")])', $o))];
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
        self::assertSame([Words::MENU['simple_match'], 'Mapping', 'Store products'], self::tabLabels($page), 'the first tab of Matching (HV3); the old pages stay');
        self::assertSame(['By item', 'New variants', 'Duplicates'], array_column(self::moreTabs($page), 'label'));
        self::assertSame('Matching', self::currentSection($page));
        $xp = self::xp($page);
        self::assertSame(Words::MENU['simple_match'], self::squash($xp->evaluate('string(//main//h1)')));
        self::assertSame(0, $xp->query('//header[contains(@class, "page-head")]//p[@class="lede"]')->length, 'no line under the title (DC1)');
        self::assertSame([Words::SIMPLE_MATCH['item'], 'Vape and Go', 'Vape Big', 'Electrofag'], self::heads($page), 'four columns, in the owner\'s order');
        self::assertSame([$alpha, $bravo], self::ids($page), 'by CW number; a product joined into another one is not listed');
        self::assertSame(self::cw($alpha) . ' Alpha kit', self::squash($xp->evaluate('string(//tr[@id="m-' . $alpha . '"]/th)')));
        self::assertSame(['vapeandgo', 'vapebig', 'electrofag'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-channel'),
            iterator_to_array($xp->query('//tr[@id="m-' . $alpha . '"]/td'))));
        self::assertSame(['Vape and Go', 'Vape Big', 'Electrofag'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-label'),
            iterator_to_array($xp->query('//tr[@id="m-' . $alpha . '"]/td'))), 'on a phone each cell says its store');
        // Nothing of the other pages: no tick boxes, no bulk bar, no filter chips, no help to unfold.
        self::assertSame(0, $xp->query('//main//input[@type="checkbox"]')->length);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "state-tabs")]')->length);
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
        // (no state of these is known: each says so after its ID, SM11)
        $id = static fn (string $o): string => self::idLine($o);
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
        self::assertSame(0, self::xp($page)->query('//main//datalist | //main//*[@data-sm-list] | //main//details[contains(@class, "sm-more")]')->length);
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
        self::assertFalse($empty['plus'], 'an empty cell: its box behind "Match", not "+" (one picker at a time, W12.12)');
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
        self::assertSame(['label' => Words::say('SIMPLE_MATCH', 'option', 'Blue Razz · Elux Legend', '5001'), 'version' => (string) $this->version($waiting), 'proposal' => '',
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
        self::assertSame(['Elux Legend Blue Razz ' . self::idLine('5001')], self::matched($back, $alpha, 'vapebig'));
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
        // (the box holds the product's ID, as app.js sends an ID it found in the list)
        $send = static fn (int $listing, array $over = []): UiResponse => $web->post(self::PATH . '/apply',
            $over + ['listing_id' => (string) $listing, 'expected_map_version' => (string) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listing]),
                'proposal_id' => '', 'pick' => (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$listing])] + $form);
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
        // Codex's fifth review, LOW: what the box held comes back in it after any refusal (rule 7), with no choice behind it.
        $label = Words::say('SIMPLE_MATCH', 'option', 'Blue Razz · Elux Legend', '5001');
        $back = self::open($web, (string) $send($big, ['expected_map_version' => (string) ($this->version($big) + 1), 'pick' => $label])->location());
        self::assertSame([$label, '', '', ''], [self::addForm($back, $alpha, 'vapebig')['fields']['pick'] ?? null, self::addForm($back, $alpha, 'vapebig')['fields']['listing_id'] ?? null,
            self::addForm($back, $alpha, 'vapebig')['fields']['expected_map_version'] ?? null, self::addForm($back, $alpha, 'vapebig')['fields']['proposal_id'] ?? null]);
        self::assertTrue(self::addForm($back, $alpha, 'vapebig')['open'] || !self::addForm($back, $alpha, 'vapebig')['plus'], 'the box is in sight');
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

        // The one waiting product with that ID is shown in its cell to be confirmed (Codex's review, HIGH 1): nothing is saved until the
        // person presses Apply on the page that shows it, whose version and suggestion that page carries (I7).
        $r = $web->post(self::PATH . '/apply', ['pick' => ' 5001 '] + (self::addForm($page, $alpha, 'vapebig')['fields'] ?? []));
        $to = self::went($r);
        self::assertSame([(string) $big, (string) $alpha, 'vapebig', null, null, 'm-' . $alpha], [$to['query']['confirm'] ?? null, $to['query']['at'] ?? null,
            $to['query']['ch'] ?? null, $to['query']['notice'] ?? null, $to['query']['said'] ?? null, $to['fragment']]);
        self::assertSame([null, 0], [$this->link($big)['sku_id'], $this->made()], 'nothing is saved yet');
        $check = self::open($web, (string) $r->location());
        $form = self::addForm($check, $alpha, 'vapebig');
        self::assertSame(['listing_id' => (string) $big, 'expected_map_version' => (string) $this->version($big), 'proposal_id' => '',
            'pick' => Words::say('SIMPLE_MATCH', 'option', 'Blue Razz · Elux Legend', '5001')],
            array_intersect_key($form['fields'] ?? [], array_flip(['listing_id', 'expected_map_version', 'proposal_id', 'pick'])), 'the product, as this page shows it');
        $cell = '//tr[@id="m-' . $alpha . '"]/td[@data-channel="vapebig"]';
        self::assertSame(Words::SIMPLE_MATCH['confirm'], self::squash(self::xp($check)->evaluate('string(' . $cell . '//p[contains(@class, "sm-confirm")])')));
        self::assertSame(1, self::xp($check)->query($cell . '//ul[@data-sm-list]/li[@data-listing-id="' . $big . '"][@data-label="' . ($form['fields']['pick'] ?? '') . '"]')->length,
            'its list holds it: app.js keeps the choice');
        self::assertSame(['Elux Legend Blue Razz', self::idLine('5001')], [
            self::squash(self::xp($check)->evaluate('string(' . $cell . '//ul[@data-sm-list]/li/span[contains(@class, "sm-opt-name")])')),
            self::squash(self::xp($check)->evaluate('string(' . $cell . '//ul[@data-sm-list]/li/span[contains(@class, "sm-opt-meta")])'))], 'on its two lines (SM11)');
        $to = self::went($web->post(self::PATH . '/apply', $form['fields'] ?? []));
        self::assertSame(['decided_link', (string) $big], [$to['query']['notice'] ?? null, $to['query']['prev'] ?? null]);
        self::assertSame($alpha, $this->link($big)['sku_id']);
        // A product no longer waiting by the time the confirmation is drawn: the cell says to choose again, and offers nothing.
        $again = self::open($web, (string) $r->location());
        self::assertSame(Words::SIMPLE_MATCH['said_choose'], self::said($again, $alpha, 'vapebig')[0]);
        self::assertSame(0, self::xp($again)->query($cell . '//ul[@data-sm-list]/li | ' . $cell . '//datalist')->length);
    }

    /**
     * Codex's review, HIGH 1: a typed ID is decided against the page that showed the product, never against the product as it is when
     * the second Apply arrives. A product renamed on its website (its version moves) or given a new suggestion after the confirmation was
     * drawn is refused, and nothing is saved.
     */
    public function testATypedIdIsDecidedAgainstThePageThatShowedIt(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $big = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $suggested = $this->queued($s['vapebig'], '5003', 'Check', $other, ['product_title' => 'Elux Legend', 'variant_title' => 'Cherry']);
        $web = $this->signIn($this->uiUser('mapper'));
        $confirm = function (string $id) use ($web, $alpha): array {
            $r = $web->post(self::PATH . '/apply', ['pick' => $id] + (self::addForm($web->get(self::PATH), $alpha, 'vapebig')['fields'] ?? []));
            return self::addForm(self::open($web, (string) $r->location()), $alpha, 'vapebig')['fields'] ?? [];
        };

        $fields = $confirm('5001');
        self::$db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id = ?', [$big]); // the website renamed it meanwhile
        self::assertSame('changed', self::went($web->post(self::PATH . '/apply', $fields))['query']['said'] ?? null);

        $fields = $confirm('5003');
        $first = (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$suggested]);
        self::assertSame((string) $first, $fields['proposal_id'] ?? null, 'the suggestion the page showed');
        $this->propose($suggested, 'Check', $alpha, [], false, 'test-run-2'); // a newer suggestion (another run) replaces it meanwhile
        self::assertNotSame($first, (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$suggested]));
        self::assertSame('changed', self::went($web->post(self::PATH . '/apply', $fields))['query']['said'] ?? null);

        self::assertSame(0, $this->made(), 'nothing was decided');
        self::assertSame([null, null], [$this->link($big)['sku_id'], $this->link($suggested)['sku_id']]);
    }

    /**
     * Codex's review, MEDIUM 2: this page only adds a match. A product matched by someone else while Apply waits for it is refused,
     * whatever version a hand-made form names: another session holds the product with its match written and commits a second later,
     * while Apply (naming the version that match leaves behind) is under way.
     */
    public function testAProductMatchedWhileApplyWaitsForItIsNotChanged(): void
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli needed for the second session');
        }
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $big = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $version = $this->version($big);
        $web = $this->signIn($this->uiUser('mapper'));
        $form = self::addForm($web->get(self::PATH), $alpha, 'vapebig')['fields'] ?? [];
        self::$db->exec('DROP PROCEDURE IF EXISTS p_sm_commit_later');
        self::$db->pdo()->exec('CREATE PROCEDURE p_sm_commit_later() BEGIN DO SLEEP(1); COMMIT; END');
        $b = self::mysqliSession();
        try {
            $b->begin_transaction();
            $b->query("UPDATE channel_listing SET sku_id = {$other}, status = 'mapped', map_version = map_version + 1 WHERE id = {$big}");
            $b->query('CALL p_sm_commit_later()', MYSQLI_ASYNC);
            $r = $web->post(self::PATH . '/apply', ['listing_id' => (string) $big, 'expected_map_version' => (string) ($version + 1), 'proposal_id' => ''] + $form);
            self::reap($b);
        } finally {
            $b->close();
            self::$db->exec('DROP PROCEDURE IF EXISTS p_sm_commit_later');
        }
        self::assertSame('changed', self::went($r)['query']['said'] ?? null);
        self::assertSame(['sku_id' => $other, 'status' => 'mapped'], array_intersect_key($this->link($big), ['sku_id' => 1, 'status' => 1]), 'the other match stays');
        self::assertSame(0, $this->made());
    }

    /**
     * Codex's review, MEDIUM 2: what Apply asks again under the product's lock (SimpleMatchController::addOnly, DecisionService's
     * recheck): still that store's and still waiting for a match. A matched product or another store's is refused, nothing written.
     */
    public function testUnderTheLockApplyOnlyAddsAMatchToAWaitingProductOfThatStore(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $mapper = $this->staffUser('mapper');
        $store = (int) $s['vapebig']->channelId;
        $decide = fn (int $listing): array => $this->ds->decide($mapper, ['action' => 'link', 'listing_id' => $listing, 'expected_map_version' => $this->version($listing),
            'sku_id' => $alpha, 'units_per_item' => 1, 'seen' => ''], SimpleMatchController::addOnly(self::$db, $listing, $store));
        $matched = $this->profiled($s['vapebig'], '5002', ['product_title' => 'Matched already'], $other);
        $elsewhere = $this->profiled($s['electrofag'], '6001', ['product_title' => 'On Electrofag']);
        self::refused(409, 'not_waiting', fn (): array => $decide($matched));
        self::refused(409, 'not_waiting', fn (): array => $decide($elsewhere));
        self::assertSame(0, $this->made());
        self::assertSame([$other, null], [$this->link($matched)['sku_id'], $this->link($elsewhere)['sku_id']]);
        $waiting = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend']);
        self::assertSame('applied', $decide($waiting)['state']);
        self::assertSame('not_waiting', SimpleMatchController::saidOf(new \CW\CwException('not_waiting', 'x', 409)));
    }

    /**
     * Codex's second review, HIGH: the box says which product Apply is for. A confirmation whose text was changed (without the script
     * nothing clears the hidden fields) is not applied to the product it showed: the new text is taken as typed (a unique waiting ID comes
     * back to be confirmed), an emptied box or words that are no ID are "Choose one from the list". Nothing is saved until the box names
     * the product the hidden fields carry (its words as the list shows them, or its ID).
     */
    public function testAChoiceWhoseTextWasChangedIsNotApplied(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $big = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        $cherry = $this->profiled($s['vapebig'], '5003', ['product_title' => 'Elux Legend', 'variant_title' => 'Cherry']);
        $web = $this->signIn($this->uiUser('mapper'));
        $r = $web->post(self::PATH . '/apply', ['pick' => '5001'] + (self::addForm($web->get(self::PATH), $alpha, 'vapebig')['fields'] ?? []));
        $fields = self::addForm(self::open($web, (string) $r->location()), $alpha, 'vapebig')['fields'] ?? [];
        self::assertSame((string) $big, $fields['listing_id'] ?? null);

        $to = self::went($web->post(self::PATH . '/apply', ['pick' => '5003'] + $fields));
        self::assertSame([(string) $cherry, null, null], [$to['query']['confirm'] ?? null, $to['query']['notice'] ?? null, $to['query']['said'] ?? null],
            'another ID typed over the confirmation: that product, to confirm');
        foreach (['', '   ', 'Elux Legend Cherry', 'Elux Legend Blue Razz'] as $typed) {
            $to = self::went($web->post(self::PATH . '/apply', ['pick' => $typed] + $fields));
            self::assertSame('choose', $to['query']['said'] ?? null, "typed \"{$typed}\" over the confirmation");
        }
        self::assertSame(0, $this->made(), 'nothing was decided');
        self::assertSame([null, null], [$this->link($big)['sku_id'], $this->link($cherry)['sku_id']]);

        $to = self::went($web->post(self::PATH . '/apply', ['pick' => ' 5001 '] + $fields));
        self::assertSame(['decided_link', (string) $big], [$to['query']['notice'] ?? null, $to['query']['prev'] ?? null], 'its own ID is that product');
        self::assertSame([$alpha, null], [$this->link($big)['sku_id'], $this->link($cherry)['sku_id']]);
    }

    /**
     * Codex's sixth review, LOW: a name with a line break or a tab (the intake keeps them) is one line in the box and its list, so its
     * choice is the box's words: chosen from the list, and confirmed without the script.
     */
    public function testANameWithALineBreakIsOneLineInTheBox(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $l = $this->profiled($s['vapebig'], '5001', ['product_title' => "Elux\nLegend", 'variant_title' => "Blue\t Razz"]);
        $label = Words::say('SIMPLE_MATCH', 'option', 'Blue Razz · Elux Legend', '5001');
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertSame($label, self::choices(self::options($web, $alpha, 'vapebig', '5001'))[$l]['label'] ?? null);
        $r = $web->post(self::PATH . '/apply', ['pick' => '5001'] + (self::addForm($web->get(self::PATH), $alpha, 'vapebig')['fields'] ?? []));
        $fields = self::addForm(self::open($web, (string) $r->location()), $alpha, 'vapebig')['fields'] ?? [];
        self::assertSame([(string) $l, $label], [$fields['listing_id'] ?? null, $fields['pick'] ?? null]);
        self::assertSame('decided_link', self::went($web->post(self::PATH . '/apply', $fields))['query']['notice'] ?? null);
        self::assertSame($alpha, $this->link($l)['sku_id']);
        self::assertSame(['Elux Legend Blue Razz ' . self::idLine('5001')], self::matched($web->get(self::PATH), $alpha, 'vapebig'));
    }

    /** Codex's review, LOW 4: the product whose ID was typed comes first, however many products whose numbers hold it sell more. */
    /**
     * The owner (26 Oct 2026): two choices looked the same, their ends cut off. A choice starts with what tells it apart: its ID, the
     * strength the matching reads, its own option name without the product's name repeated, then the product's name once (SM10).
     */
    public function testAChoiceStartsWithItsIdAndStrengthAndNamesTheProductOnce(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $ivg = $this->profiled($s['electrofag'], '4312', ['product_title' => 'IVG 2400 4 in 1 Rechargeable Prefilled Pod Kit',
            'variant_title' => 'IVG 2400 4 in 1 Rechargeable Prefilled Pod Kit - Red Edition']);
        $salt = $this->profiled($s['electrofag'], '3234', ['product_title' => 'Hayati Pro Max Blueberry Cherry Cranberry Nic Salt 10ml',
            'variant_title' => 'Hayati Pro Max Blueberry Cherry Cranberry 10ml Nic Salt E Liquid - 20mg']);
        $web = $this->signIn($this->uiUser('mapper'));
        self::assertSame('ID 4312 · Red Edition · IVG 2400 4 in 1 Rechargeable Prefilled Pod Kit',
            self::choices(self::options($web, $alpha, 'electrofag', '4312'))[$ivg]['label'] ?? null, 'the product\'s name once, its edition first');
        $label = self::choices(self::options($web, $alpha, 'electrofag', '3234'))[$salt]['label'] ?? '';
        self::assertStringStartsWith('ID 3234 · 20mg · ', $label, 'the ID and the strength first');
        self::assertSame('3234', SimpleMatchController::idOf($label));
        self::assertNull(SimpleMatchController::idOf('Blue Razz · ID 12'), 'a choice starts with its ID');
        $ten = $this->profiled($s['electrofag'], '3699', ['product_title' => 'Hayati Pro Max Plus 6000 Prefilled Pod Kit',
            'variant_title' => 'Hayati Pro Max Plus 6000 Prefilled Pod Kit - 10mg | Black Mamba']);
        self::assertSame('ID 3699 · 10mg | Black Mamba · Hayati Pro Max Plus 6000 Prefilled Pod Kit',
            self::choices(self::options($web, $alpha, 'electrofag', '3699'))[$ten]['label'] ?? null, 'a strength the name starts with, once');
        // The Codex review of c330f42 (two LOW): the product's name is taken out of the option's name whatever its case, accents
        // included ("CAFÉ" is "Café"), and what stands between them is trimmed by whole characters (a "£" after it stays whole).
        $cafe = $this->profiled($s['electrofag'], '3701', ['product_title' => 'CAFÉ SALT', 'variant_title' => 'Café Salt - Blue']);
        self::assertSame('ID 3701 · Blue · CAFÉ SALT', self::choices(self::options($web, $alpha, 'electrofag', '3701'))[$cafe]['label'] ?? null, 'any case');
        $kit = $this->profiled($s['electrofag'], '3702', ['product_title' => 'Brand Kit', 'variant_title' => 'Brand Kit - £5 Edition']);
        self::assertSame('ID 3702 · £5 Edition · Brand Kit', self::choices(self::options($web, $alpha, 'electrofag', '3702'))[$kit]['label'] ?? null, 'whole characters');
        $dot = $this->profiled($s['electrofag'], '3703', ['product_title' => 'Brand Kit', 'variant_title' => 'Brand Kit · Blue ·']);
        self::assertSame('ID 3703 · Blue · Brand Kit', self::choices(self::options($web, $alpha, 'electrofag', '3703'))[$dot]['label'] ?? null, 'a middle dot around it');
    }

    /**
     * The owner (26 Oct 2026, on the live page): "I want to still see the full name ... I need also the status of the variant shown
     * there from each shop, the same way you show the ID" (SM11). Each choice of the box's own list has two lines: the product's FULL
     * name on the store (the matched cells' name, never cut), then "ID <option> · <its status on the store> · <strength>", and the
     * mark when there is one. The status is the website state CW stores (SiteState::read: the stored state, else what a matching run
     * recorded, else the open suggestion's record), in the store's own word; the product's status too when the product is not
     * published; "status not known" when nothing is known or the stored state is older than the setting. The box's words (what Apply
     * compares) stay SM10's and carry no status, and each choice carries what Apply sends, as before.
     */
    public function testEachChoiceHasTheFullNameThenItsIdAndItsStatusOnTheStore(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $product = 'Hayati Pro Max Blueberry Cherry Cranberry Nic Salt 10ml';
        $twenty = $this->profiled($s['electrofag'], '3234', ['product_title' => $product, 'variant_title' => 'Hayati Pro Max Blueberry Cherry Cranberry 10ml Nic Salt E Liquid - 20mg']);
        $ten = $this->profiled($s['electrofag'], '3233', ['product_title' => $product, 'variant_title' => 'Hayati Pro Max Blueberry Cherry Cranberry 10ml Nic Salt E Liquid - 10mg']);
        self::siteState($twenty);
        self::siteState($ten, ['site_variant_status' => 'Bin']);
        $web = $this->signIn($this->uiUser('mapper'));
        $r = self::options($web, $alpha, 'electrofag', 'hayati');
        self::assertSame(200, $r->status, $r->describe() . implode("\n", self::$log));
        self::assertSame(0, self::xp($r)->query('//datalist | //option')->length, 'no native popup: the page draws its own list');
        $lines = self::lines($r);
        self::assertSame([$product . ' Hayati Pro Max Blueberry Cherry Cranberry 10ml Nic Salt E Liquid - 20mg', Words::say('SIMPLE_MATCH', 'id', '3234') . ' · Published · 20mg'], $lines[$twenty] ?? null,
            'the full name, then the ID, the status on the store and the strength');
        self::assertSame([$product . ' Hayati Pro Max Blueberry Cherry Cranberry 10ml Nic Salt E Liquid - 10mg', Words::say('SIMPLE_MATCH', 'id', '3233') . ' · Bin · 10mg'], $lines[$ten] ?? null,
            'the store\'s own word');
        $c = self::choices($r);
        self::assertSame(['version' => (string) $this->version($twenty), 'proposal' => '', 'option' => '3234'], array_diff_key($c[$twenty] ?? [], ['label' => 1]),
            'what Apply sends, as before');
        self::assertStringStartsWith('ID 3234 · 20mg · ', $c[$twenty]['label'] ?? '', 'the box\'s words are SM10\'s ...');
        self::assertStringNotContainsString('Published', $c[$twenty]['label'] ?? '', '... with no status: a state loaded meanwhile never makes a choice unusable');

        $pub = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz']);
        self::siteState($pub, ['site_product_status' => 'Draft']);
        $none = $this->profiled($s['vapebig'], '5002', ['product_title' => 'Elux Legend', 'variant_title' => 'Cherry']);
        $old = $this->profiled($s['vapebig'], '5003', ['product_title' => 'Elux Legend', 'variant_title' => 'Mango']);
        self::siteState($old, ['site_state_at' => \CW\Clock::db(\CW\Clock::now()->modify('-60 days'))]);
        $run = $this->profiled($s['vapebig'], '5004', ['product_title' => 'Elux Legend', 'variant_title' => 'Grape', 'features' => ['variant_status' => 'Draft']]);
        $checked = $this->queued($s['vapebig'], '5005', 'Check', $other, ['product_title' => 'Elux Legend', 'variant_title' => 'Lime'], ['evidence' => ['variant_status' => 'Bin']]);
        $half = $this->profiled($s['vapebig'], '5006', ['product_title' => 'Elux Legend', 'variant_title' => 'Kiwi']);
        self::siteState($half, ['site_variant_status' => null, 'site_product_status' => 'Bin']);
        $elux = self::lines(self::options($web, $alpha, 'vapebig', 'elux legend'));
        self::assertSame(['Elux Legend Blue Razz', 'ID 5001 · Published · product Draft'], $elux[$pub] ?? null, 'a published option of a product that is not');
        self::assertSame(Words::say('SIMPLE_MATCH', 'id', '5001') . ' · ' . Words::say('SIMPLE_MATCH', 'status_product', 'Published', 'Draft'), $elux[$pub][1] ?? null);
        self::assertSame(self::idLine('5002'), $elux[$none][1] ?? null, 'nothing known: said so');
        self::assertSame(self::idLine('5003'), $elux[$old][1] ?? null, 'a stored state older than the setting is not known');
        self::assertSame(self::idLine('5004', 'Draft'), $elux[$run][1] ?? null, 'no stored state: what a matching run recorded');
        self::assertSame(self::idLine('5005', 'Bin'), $elux[$checked][1] ?? null, 'else what the computer\'s check recorded');
        self::assertSame(self::idLine('5006', Words::say('SIMPLE_MATCH', 'status_product', Words::SIMPLE_MATCH['status_unknown'], 'Bin')), $elux[$half][1] ?? null,
            'the product\'s status beside an option\'s that is not known');

        // The marks of SM4 end the line: one decided on its own page, and (for a Matcher) one a matching lead decides.
        $arrival = $this->profiled($s['vapeandgo'], '7001', ['product_title' => 'Lost Mary', 'variant_title' => 'Grape']);
        self::siteState($arrival);
        $this->proposals->add(Caller::system('test'), $arrival, $this->proposals->run('arrival-t1', ArrivalCheck::SOURCE), ['band' => 'Check', 'proposed_sku_id' => $alpha], true);
        $conflict = $this->queued($s['vapeandgo'], '7002', 'Conflict', $other, ['product_title' => 'Lost Mary', 'variant_title' => 'Mint']);
        $mary = self::lines(self::options($web, $alpha, 'vapeandgo', 'lost mary'));
        self::assertSame(self::idLine('7001', 'Published') . ' · ' . Words::SIMPLE_MATCH['own_page'], $mary[$arrival][1] ?? null);
        self::assertSame(self::idLine('7002') . ' · ' . Words::SIMPLE_MATCH['lead_decides'], $mary[$conflict][1] ?? null);
        self::assertSame(['decided on its own page', 'a matching lead decides it'], [Words::SIMPLE_MATCH['own_page'], Words::SIMPLE_MATCH['lead_decides']]);

        // A long name is never cut (the box's words may be: they are one line of at most 200 characters).
        $long = $this->profiled($s['vapeandgo'], '7003', ['product_title' => str_repeat('Very long product name ', 6) . 'end', 'variant_title' => str_repeat('very long option name ', 6) . 'last']);
        $name = str_repeat('Very long product name ', 6) . 'end ' . str_repeat('very long option name ', 6) . 'last';
        self::assertGreaterThan(200, mb_strlen($name));
        self::assertSame($name, self::lines(self::options($web, $alpha, 'vapeandgo', '7003'))[$long][0] ?? null, 'never cut');
    }

    /**
     * SM11: a matched cell says each product's status on its store after its ID, the same way ("ID 5001 · Published"), from the same
     * reads as the cell's names; a match waiting for a second OK too.
     */
    public function testAMatchedCellSaysEachProductsStatusOnItsStoreAfterItsId(): void
    {
        $s = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $counted = $this->item('strict', 0, 'Counted kit');
        $pub = $this->profiled($s['vapebig'], '5001', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'units_30d' => 9], $alpha);
        $bin = $this->profiled($s['vapebig'], '5002', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz old page', 'units_30d' => 5], $alpha);
        $draft = $this->profiled($s['vapebig'], '5003', ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz copy', 'units_30d' => 1], $alpha);
        $old = $this->profiled($s['vapeandgo'], '88', ['product_title' => 'Alpha on Vape and Go'], $alpha);
        self::siteState($pub);
        self::siteState($bin, ['site_variant_status' => 'Bin']);
        self::siteState($draft, ['site_product_status' => 'Draft']);
        self::siteState($old, ['site_state_at' => \CW\Clock::db(\CW\Clock::now()->modify('-60 days'))]);
        $w = $this->profiled($s['electrofag'], 'E-1', ['product_title' => 'Counted on Electrofag', 'features' => ['variant_status' => 'Published']]);
        self::assertSame('pending_second', $this->decide($lead, 'link', $w, ['sku_id' => $counted])['state']);

        $page = $this->signIn($this->uiUser('mapper'))->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame(['Elux Legend Blue Razz ' . self::idLine('5001', 'Published'), 'Elux Legend Blue Razz old page ' . self::idLine('5002', 'Bin'),
            'Elux Legend Blue Razz copy ' . self::idLine('5003', Words::say('SIMPLE_MATCH', 'status_product', 'Published', 'Draft'))],
            self::matched($page, $alpha, 'vapebig'), 'each with its status after its ID');
        self::assertSame(['Alpha on Vape and Go ' . self::idLine('88')], self::matched($page, $alpha, 'vapeandgo'), 'too old: not known');
        self::assertSame(['Counted on Electrofag ' . self::idLine('E-1', 'Published') . ' ' . Words::SIMPLE_MATCH['waiting']], self::matched($page, $counted, 'electrofag'),
            'a match waiting for a second OK too');
        $li = '//tr[@id="m-' . $alpha . '"]/td[@data-channel="vapebig"]//li[1]';
        self::assertSame([Words::say('SIMPLE_MATCH', 'id', '5001'), '· Published'], [self::squash(self::xp($page)->evaluate('string(' . $li . '/span[contains(@class, "sm-id")])')),
            self::squash(self::xp($page)->evaluate('string(' . $li . '/span[contains(@class, "sm-site")])'))], 'the ID, then its status in a span of its own');
    }

    public function testTheProductWhoseIdWasTypedIsOfferedFirst(): void
    {
        $s = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $exact = $this->profiled($s['vapebig'], '12', ['product_title' => 'The one']);
        for ($i = 0; $i < 25; $i++) {
            $this->profiled($s['vapebig'], '12' . $i . '9', ['product_title' => 'Busy seller ' . $i, 'units_30d' => 50]);
        }
        $web = $this->signIn($this->uiUser('mapper'));
        $c = self::choices(self::options($web, $alpha, 'vapebig', '12'));
        self::assertSame($exact, array_key_first($c), 'the product whose ID it is, first');
        self::assertCount(SimpleMatchController::OPTIONS_MAX, $c);
        $one = $this->profiled($s['vapebig'], '7', ['product_title' => 'A one-digit ID']);
        self::assertSame($one, array_key_first(self::choices(self::options($web, $alpha, 'vapebig', '7'))), 'one digit is an ID too');
        // A box that still holds a choice's words (kept after a refusal): the product its ID names comes first again, so it can be chosen
        // anew as it is now (Codex's fifth review).
        foreach (['option', 'option_own_page', 'option_lead'] as $word) {
            self::assertSame($exact, array_key_first(self::choices(self::options($web, $alpha, 'vapebig', Words::say('SIMPLE_MATCH', $word, 'The one', '12')))), $word);
        }
    }

    private static function mysqliSession(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $s = \CW\Tests\Support\TestDb::config()->dbAdmin();
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $m->ssl_set(null, null, $s->sslCa ?? \CW\Db::SYSTEM_CA_BUNDLE, null, null);
        $m->real_connect($s->host, $s->user, $s->password(), \CW\Tests\Support\TestDb::name(), $s->port, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
        $m->query("SET SESSION time_zone = '+00:00', SESSION transaction_isolation = 'READ-COMMITTED'");
        return $m;
    }

    private static function reap(\mysqli $m): void
    {
        $links = $errors = $rejects = [$m];
        if (\mysqli::poll($links, $errors, $rejects, 10) < 1) {
            self::fail('async query did not complete');
        }
        $r = $m->reap_async_query();
        if ($r instanceof \mysqli_result) {
            $r->free();
        }
        while ($m->more_results()) {
            $m->next_result();
        }
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
        self::assertSame(['Elux Legend Blue Razz ' . self::idLine('5001') . ' ' . Words::SIMPLE_MATCH['waiting']], self::matched($back, $counted, 'vapebig'));
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
        self::assertSame(Words::say('SIMPLE_MATCH', 'option_own_page', 'Blue Razz · Elux Legend', '5001'), $c[$l]['label'] ?? null, 'the list says so before Apply');
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
