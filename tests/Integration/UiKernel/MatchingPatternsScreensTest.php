<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\ReviewController;
use CW\Ui\Words;

/**
 * Wave w12 (docs/decisions.md W12): the Matching pages as the browser gets them. A store product's page is the approved TP_Match: the
 * question in the head, the store product's card beside the item's with each fact marked agrees or differs, the units and the note, ONE
 * decision form whose bar at the bottom is Skip, More, Not the same, Same item, and its answer sent by the button pressed; the lists are
 * boards (a group per state with its solid status cell, one search and one Show row), Staff requests an inbox. In-process through the real
 * /ui kernel as cw_app.
 */
final class MatchingPatternsScreensTest extends KernelUiTestCase
{
    public function testAStoreProductsPageIsTheApprovedMatchingTemplate(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $l1 = $this->queued($site, 'W1', 'Key', $a, ['product_title' => 'Elux Legend', 'variant_title' => 'Blue Razz', 'brand' => 'Elux', 'units_30d' => 9]);
        $l2 = $this->queued($site, 'W2', 'Key', $a, ['product_title' => 'Elux Legend Mint', 'units_30d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $l1, ['queue' => 'Key', 'channel' => 'vpg']);
        self::assertSame(200, $page->status, $page->describe());
        $xp = new \DOMXPath($page->dom());
        // The head asks the question; the store and how many are left in its eyebrow; the bar at its right says the progress in words.
        self::assertSame(Words::MATCH['question'], trim((string) $xp->evaluate('string(//main//h1)')));
        // The eyebrow (DC3): the list it was opened from, then the store and how many are left.
        self::assertSame('/ui/review?queue=Key&channel=vpg', (string) $xp->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'));
        self::assertStringContainsString('VPG test site · ' . Words::say('MATCH', 'to_match', 2), trim((string) $xp->evaluate('string(//main//p[contains(@class, "ph-eyebrow")])')));
        self::assertSame(1, $xp->query('//header//span[@class="mt-progress"][@data-progress]')->length);
        // The store product on the left (the blue edge), the item on the right (the green edge), in that order, each its facts.
        self::assertSame(['card mt-card info', 'card mt-card done'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('class'),
            iterator_to_array($xp->query('//div[@class="mt-cards"]/section'))));
        self::assertSame([Words::MATCH['on_store'], Words::LISTING['suggested']], self::texts($xp, '//div[@class="mt-cards"]/section//h2[@class="mt-h"]'));
        self::assertSame('Elux Legend – Blue Razz', trim((string) $xp->evaluate('string(//section[@aria-labelledby="mt-store-h"]//p[@class="mt-name"])')));
        self::assertStringStartsWith(Words::say('MATCH', 'variant', 'W1'), trim((string) $xp->evaluate('string(//section[@aria-labelledby="mt-store-h"]//p[@class="mt-sub"])')));
        self::assertSame(sprintf('CW-%06d', $a) . ' · ' . Words::say('MATCH', 'in_stock', 0),
            trim((string) $xp->evaluate('string(//section[@aria-labelledby="mt-item-h"]//p[@class="mt-sub"])')));
        // Each fact of the two sides is marked: what agrees on the green, what differs (or one side does not state) on the amber; brand first.
        $marks = $xp->query('//section[@aria-labelledby="mt-store-h"]//dl[@class="mt-facts"]/dd/span[contains(@class, "mt-mark")]');
        self::assertGreaterThan(0, $marks->length);
        foreach ($marks as $m) {
            self::assertContains($m->getAttribute('class'), ['mt-mark same', 'mt-mark diff']);
            self::assertSame(in_array($m->getAttribute('data-state'), ['same', 'alike'], true), $m->getAttribute('class') === 'mt-mark same');
        }
        self::assertSame(Words::FIELD['brand'], trim((string) $xp->evaluate('string(//section[@aria-labelledby="mt-store-h"]//dl[@class="mt-facts"]/dt[1])')));
        self::assertSame(1, $xp->query('//section[@aria-labelledby="mt-item-h"]//input[@name="s"][@form="mt-find"][@placeholder="' . Words::MATCH['find_hint'] . '"]')->length,
            '"Or find another item" in the item\'s card, its own GET form');
        // The units and the note, in the approved words.
        self::assertSame([Words::MATCH['units'], Words::MATCH['note']], self::texts($xp, '//section[contains(@class, "mt-answer")]//label'));
        self::assertSame(Words::MATCH['note_hint'], (string) $xp->evaluate('string(//input[@id="f-reason"]/@placeholder)'));
        // ONE decision form (the quick yes is gone); its bar: Skip (the next one), More, Not the same, Same item (the main one).
        self::assertSame(1, $xp->query('//form[contains(@action, "/decide")]')->length - $xp->query('//form[@id="mark-landing"]')->length);
        $bar = '//form[@id="decide"]/div[contains(@class, "mt-bar")]';
        self::assertSame([Words::MATCH['skip'], Words::MATCH['more'], Words::MATCH['not_same'], Words::MATCH['same']],
            self::texts($xp, $bar . '/a | ' . $bar . '/details/summary | ' . $bar . '/button'));
        self::assertSame('/ui/review/listing/' . $l2 . '?queue=Key&channel=vpg', (string) $xp->evaluate('string(' . $bar . '/a/@href)'));
        self::assertSame(['link'], $page->buttons('/decide', 'action', true));
        self::assertSame(['new_item', 'ignore', 'reject', 'link'], $page->buttons('/decide'), 'New item and Ignore under More');
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//details[@id="why"]')->length, 'the evidence under More');
        self::assertSame(0, $xp->query('//input[@type="radio"][@name="action"]')->length, 'the answer is the button pressed');
        self::assertSame('1', (string) $xp->evaluate('count(//form[@id="decide"]/button[1][@class="po-default"][@disabled])'), 'Enter answers nothing');

        // Same item: the form with the button's answer, as a browser sends it; on to the next one.
        $form = $page->form('/ui/review/listing/' . $l1 . '/decide');
        self::assertArrayNotHasKey('action', $form);
        $r = $web->post('/ui/review/listing/' . $l1 . '/decide', $form + ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringStartsWith('/ui/review/listing/' . $l2 . '?', (string) $r->location());
        self::assertSame(['mapped', $a], [$this->link($l1)['status'], $this->link($l1)['sku_id']]);
    }

    public function testTheListsAreBoards(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Board item');
        $l1 = $this->queued($site, 'B1', 'Key', $a, ['product_title' => 'Board one', 'units_30d' => 5]);
        $this->profiled($site, 'B2', ['product_title' => 'Board two'], $a);
        $web = $this->signIn($this->uiUser('mapper'));

        // To review, one list: one search and ONE Show control (DC27's pop-over: store, list, what it shows, found by, sold at least), the list's group.
        $list = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'vpg']);
        self::assertSame(200, $list->status, $list->describe());
        $xp = new \DOMXPath($list->dom());
        self::assertSame(['channel', 'queue', 'lane'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('name'),
            array_values(array_filter(iterator_to_array($xp->query('//form[contains(@class, "toolbar")]//div[@class="tb-state"]/details[@class="tb-pop tb-show"]//select')),
                static fn (\DOMElement $s): bool => $s->getAttribute('name') !== 'show'))));
        self::assertSame(1, $xp->query('//form[contains(@class, "toolbar")]//input[@name="min"]')->length);
        self::assertSame(0, $xp->query('//nav[contains(@class, "store-seg")] | //details[@class="tb-pop"] | //div[@class="battery"]')->length);
        self::assertSame(Words::BAND['Key'], trim((string) $xp->evaluate('string(//tr[@id="l-' . $l1 . '"]/td[contains(@class, "doc-st")])')), 'the solid status cell');
        self::assertSame(1, $xp->query('//div[contains(@class, "doc-group")]/div[contains(@class, "doc-board")]//tr[@id="l-' . $l1 . '"]')->length);
        self::assertSame(ReviewController::progress(1, 0), (string) $xp->evaluate('string(//*[@data-progress]/@data-progress)'));

        // Store Products: grouped by state, each its colour and count; the state the solid cell.
        $store = $web->get('/ui/review/store', ['channel' => 'vpg']);
        $sx = new \DOMXPath($store->dom());
        self::assertSame([Words::STORE_STATE['suggested'], Words::STORE_STATE['linked']], self::texts($sx, '//div[contains(@class, "doc-group")]/h3/button'), 'what is still to match first');
        self::assertSame(Words::STORE_STATE['suggested'], trim((string) $sx->evaluate('string(//tr[@id="l-' . $l1 . '"]/td[contains(@class, "doc-st")])')));
        self::assertSame(['channel', 'state', 'sort'], array_map(static fn (\DOMElement $s): string => $s->getAttribute('name'),
            iterator_to_array($sx->query('//form[contains(@class, "toolbar")]//select'))));
    }

    /**
     * The final fixes of w12 (docs/decisions.md W12.20; the Codex review of da7c620, LOW): when the main answer is New item (no item to compare,
     * the computer suggests a new one), its details are under More; a New item the service refused (a strength that is not a number, no name)
     * is drawn again with More open and the New item fold open in it, the refused field marked, so the person sees what to put right.
     */
    public function testARefusedNewItemIsDrawnWithMoreAndItsDetailsOpen(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'NI1', 'New item', null, ['product_title' => 'Acme Kiwi Ice 10ml', 'brand' => 'Acme', 'units_30d' => 4], ['proposed_new_item' => true]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $l, ['queue' => 'New item', 'channel' => 'vpg']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['new_item'], $page->buttons('/decide', 'action', true), 'New item is the main answer');
        $more = '//form[@id="decide"]/div[contains(@class, "mt-bar")]/details[contains(@class, "more")][.//details[@id="new-product"]]';
        self::assertSame(1, (new \DOMXPath($page->dom()))->query($more)->length);
        self::assertSame(0, (new \DOMXPath($page->dom()))->query($more . '[@open]')->length, 'nothing refused: More is closed');
        // (a strength that is not a number is refused on its field, which is marked; no name at all is refused for the item as a whole)
        foreach (['card_strength_mg' => ['abc', 400, 'true'], 'card_name' => ['', 422, '']] as $field => [$bad, $status, $marked]) {
            $r = $web->post('/ui/review/listing/' . $l . '/decide', array_replace($page->form('/ui/review/listing/' . $l . '/decide'), ['action' => 'new_item', $field => $bad]));
            self::assertSame($status, $r->status, $field . ': ' . $r->describe());
            $xp = new \DOMXPath($r->dom());
            self::assertSame(1, $xp->query($more . '[@open]//details[@id="new-product"][@open]')->length, $field . ': More and the New item fold are open');
            self::assertSame($marked, (string) $xp->evaluate('string(//*[@id="' . $field . '"]/@aria-invalid)'), $field);
        }
        self::assertSame('suggested', $this->link($l)['status'], 'nothing was saved');
    }

    /**
     * The final fixes of w12 (docs/decisions.md W12.21; the owner's Chrome check of da7c620): a store product's eyebrow (DC3, W12.1) is the list
     * it was opened from, with the store and how many are left to match there, whatever list's link opened it: Store Products' Open carries
     * the list as the person has it (as its Check & match fold does); a page opened from New Variants or Simple matching names that list; a
     * page opened from nowhere known names its store's Store Products. Never Home.
     */
    public function testAStoreProductsEyebrowIsTheListItWasOpenedFrom(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Eyebrow item');
        $l1 = $this->queued($site, 'E1', 'Key', $a, ['product_title' => 'Eyebrow one', 'units_30d' => 5, 'units_365d' => 5]);
        $this->queued($site, 'E2', 'Check', $a, ['product_title' => 'Eyebrow two', 'units_30d' => 3, 'units_365d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));
        $eyebrow = static function (\CW\Tests\Support\UiResponse $page): array {
            self::assertSame(200, $page->status, $page->describe());
            $xp = new \DOMXPath($page->dom());
            return [(string) $xp->evaluate('string(//main//p[contains(@class, "ph-back")]/a/@href)'), trim((string) $xp->evaluate('string(//main//p[contains(@class, "ph-back")]/a)')),
                trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//p[contains(@class, "ph-eyebrow")])')))];
        };
        $left = 'VPG test site · ' . Words::say('MATCH', 'to_match', 2);

        // Store Products' Open carries the list as the person has it; the eyebrow is that list, at the row.
        $store = $web->get('/ui/review/store', ['channel' => 'vpg', 'sort' => 'sold_365']);
        $open = (string) (new \DOMXPath($store->dom()))->evaluate('string(//tr[@id="l-' . $l1 . '"]/td[@class="c-next"]/a/@href)');
        self::assertSame('/ui/review/listing/' . $l1 . '?via=store&channel=vpg&sort=sold_365', $open);
        $query = [];
        parse_str((string) parse_url($open, PHP_URL_QUERY), $query);
        [$href, $label, $line] = $eyebrow($web->get('/ui/review/listing/' . $l1, $query));
        self::assertSame(['/ui/review/store?channel=vpg&sort=sold_365#l-' . $l1, Words::MENU['store_products']], [$href, $label]);
        self::assertStringContainsString($left, $line, 'the store and how many are left to match');

        // From New Variants or Simple matching (their rows link the page bare): that list, as the person had it.
        $from = static fn (string $ref): \CW\Tests\Support\UiResponse => $web->send('GET', '/ui/review/listing/' . $l1, [], [], ['referer' => 'http://cw-ui.review.invalid' . $ref]);
        [$href, $label, $line] = $eyebrow($from('/ui/review/new-variants?channel=vpg&period=all&state=all&x=evil'));
        self::assertSame(['/ui/review/new-variants?channel=vpg&period=all&state=all', Words::MENU['new_variants']], [$href, $label]);
        self::assertStringContainsString($left, $line);
        [$href, $label, $line] = $eyebrow($from('/ui/match?q=Eyebrow'));
        self::assertSame(['/ui/match?q=Eyebrow', Words::MENU['simple_match']], [$href, $label]);
        self::assertStringContainsString($left, $line);
        // Another site's page, or nothing known: the store's Store Products, never Home.
        foreach ([$from('/ui/'), $web->send('GET', '/ui/review/listing/' . $l1, [], [], ['referer' => 'https://evil.example/ui/match?q=x']), $web->get('/ui/review/listing/' . $l1)] as $page) {
            [$href, $label, $line] = $eyebrow($page);
            self::assertSame(['/ui/review/store?channel=vpg', Words::MENU['store_products']], [$href, $label]);
            self::assertStringContainsString($left, $line);
        }
    }

    /** @return list<string> */
    private static function texts(\DOMXPath $xp, string $path): array
    {
        return array_values(array_map(static fn (\DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', $n->textContent)), iterator_to_array($xp->query($path))));
    }
}
