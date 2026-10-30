<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\ReviewController;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The product search boxes of the matching pages and the progress line (docs/decisions.md SX5, SX6), through the real /ui kernel:
 *  - each box says what to type (its placeholder), answers "/" (data-page-search, aria-keyshortcuts);
 *  - while a search is on, the line under the box says how many it found for what, with a link that clears the search alone;
 *  - a search that finds nothing says what to try;
 *  - a match-strength list, and a variant's page opened from one, say how many the list still holds and how many the person
 *    answered today; someone who can only look is told the first only.
 */
final class SearchHintsScreenTest extends KernelUiTestCase
{
    private Caller $alt;

    /** @return array{h1: int, h2: int, h3: int, sku1: int, sku2: int} */
    private function lists(): array
    {
        $this->alt = $this->site('alt', 'shadow');
        $sku1 = self::makeSku('Hayati Pro Max Plus 6000 Strawberry Kiwi');
        $sku2 = self::makeSku('Hayati Pro Max Plus 6000 Juicy Peach');
        $h1 = $this->queued($this->alt, 'H1', 'Check', $sku1, ['product_title' => 'Hayati Pro Max Plus 6000 Prefilled Pod Kit', 'variant_title' => 'Strawberry Kiwi',
            'brand' => 'Hayati', 'units_365d' => 30]);
        $h2 = $this->queued($this->alt, 'H2', 'Check', $sku2, ['product_title' => 'Hayati Pro Max Plus 6000 Prefilled Pod Kit', 'variant_title' => 'Juicy Peach',
            'brand' => 'Hayati', 'units_365d' => 20]);
        $h3 = $this->queued($this->alt, 'H3', 'Check', $sku2, ['product_title' => 'Elux Legend 3500', 'variant_title' => 'Blue Razz', 'brand' => 'Elux', 'units_365d' => 10]);
        return ['h1' => $h1, 'h2' => $h2, 'h3' => $h3, 'sku1' => $sku1, 'sku2' => $sku2];
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function attr(UiResponse $r, string $path): string
    {
        return (string) self::xp($r)->evaluate('string(' . $path . ')');
    }

    private static function textOf(UiResponse $r, string $path): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) self::xp($r)->evaluate('string(' . $path . ')')));
    }

    /** The page's own search box says what to type and answers "/". */
    private static function assertSearchBox(UiResponse $r, string $placeholder): void
    {
        self::assertSame(200, $r->status, $r->describe());
        $box = self::xp($r)->query('//main//input[@data-page-search]');
        self::assertSame(1, $box->length, 'one search box of the page');
        $el = $box->item(0);
        self::assertInstanceOf(\DOMElement::class, $el);
        self::assertSame([$placeholder, '/'], [$el->getAttribute('placeholder'), $el->getAttribute('aria-keyshortcuts')]);
    }

    /** "N found for "…"" and the Clear link, or none at all. */
    private static function assertFound(UiResponse $r, ?string $found, ?string $clear): void
    {
        if ($found === null) {
            self::assertSame(0, self::xp($r)->query('//p[@class="search-note"]')->length, 'no found line without a search');
            return;
        }
        self::assertSame($found, self::textOf($r, '//p[@class="search-note"]/span[@class="search-found"]'));
        self::assertSame([Words::FIND['clear'], $clear], [self::textOf($r, '//p[@class="search-note"]/a'), self::attr($r, '//p[@class="search-note"]/a/@href')]);
    }

    public function testEveryBoxSaysWhatToTypeWhatItFoundAndClearsTheSearchAlone(): void
    {
        $f = $this->lists();
        $web = $this->signIn($this->uiUser('mapper'));

        // To review's Likely list: every word in any order; the Clear keeps the store and what the list shows
        $list = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt', 'show' => 'ready', 'q' => 'kiwi hayati']);
        self::assertSearchBox($list, Words::FIND['listings']);
        self::assertFound($list, Words::say('FIND', 'found_one', '"kiwi hayati"'), Html::url('/ui/review', ['queue' => 'Check', 'channel' => 'alt', 'show' => 'ready']));
        self::assertSame(1, self::xp($list)->query('//tr[@id="l-' . $f['h1'] . '"]')->length);
        self::assertFound($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), null, null);
        $none = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt', 'q' => 'nothing like it']);
        self::assertStringContainsString(Words::QUEUE['empty_filter'], $none->text());
        self::assertStringContainsString(Words::FIND['try'], $none->text(), 'what to try');
        self::assertFound($none, null, null);
        // the CW number of the suggested item finds its rows
        $byCode = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt', 'q' => sprintf('cw %d', $f['sku2'])]);
        self::assertFound($byCode, Words::say('FIND', 'found_many', 2, Words::quoted(sprintf('cw %d', $f['sku2']), '')), Html::url('/ui/review', ['queue' => 'Check', 'channel' => 'alt']));

        // Store Products: the Clear keeps the state and the sort
        $store = $web->get('/ui/review/store', ['channel' => 'alt', 'state' => 'suggested', 'sort' => 'sold_365', 'q' => 'hayati pod kit']);
        self::assertSearchBox($store, Words::FIND['listings']);
        self::assertFound($store, Words::say('FIND', 'found_many', 2, '"hayati pod kit"'), Html::url('/ui/review/store', ['channel' => 'alt', 'state' => 'suggested', 'sort' => 'sold_365']));
        $storeNone = $web->get('/ui/review/store', ['channel' => 'alt', 'q' => 'zz nothing']);
        self::assertStringContainsString(Words::STORE_PRODUCTS['empty_filter'], $storeNone->text());
        self::assertStringContainsString(Words::FIND['try'], $storeNone->text());

        // New Variants: its own words ("variant"), the Clear keeps the period and the filter
        $new = $web->get('/ui/review/new-variants', ['channel' => 'alt', 'period' => 'all', 'state' => 'all', 'q' => 'peach juicy']);
        self::assertSearchBox($new, Words::FIND['variants']);
        self::assertFound($new, Words::say('FIND', 'found_one', '"peach juicy"'), Html::url('/ui/review/new-variants', ['channel' => 'alt', 'period' => 'all', 'state' => 'all']));
        self::assertStringContainsString(Words::FIND['try'], $web->get('/ui/review/new-variants', ['channel' => 'alt', 'period' => 'all', 'q' => 'zz nothing'])->text());

        // By Item: "item", the Clear keeps the filter
        $items = $web->get('/ui/review/products', ['cov' => 'missing-alt', 'q' => 'juicy hayati']);
        self::assertSearchBox($items, Words::FIND['items']);
        self::assertFound($items, Words::say('FIND', 'found_one', '"juicy hayati"'), Html::url('/ui/review/products', ['cov' => 'missing-alt']));
        self::assertStringContainsString(Words::FIND['try_items'], $web->get('/ui/review/products', ['q' => 'zz nothing'])->text());
        // the quick match box of a "Not on this store" cell says what to type too
        $lonely = self::makeSku('Nobody sells this one yet');
        $missing = $web->get('/ui/review/products', ['cov' => 'missing-alt', 'q' => sprintf('CW-%06d', $lonely)]);
        self::assertSame(Words::FIND['quick'], self::attr($missing, '(//input[@data-qp-q])[1]/@placeholder'), $missing->describe());

        // Find a product: what can be typed, before anything is
        $find = $web->get('/ui/search');
        self::assertSearchBox($find, '');
        self::assertStringContainsString(Words::FIND['any_order'], $find->text());
        // the variant's page: its "Or find another item" box answers "/" (TP_Match, W12.1: in the item's card, its own GET form)
        $own = $web->get('/ui/review/listing/' . $f['h1'], ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame('/', self::attr($own, '//div[@id="f-pick"]//input[@name="s"]/@aria-keyshortcuts'));
        self::assertSame(1, self::xp($own)->query('//div[@id="f-pick"]//input[@name="s"][@data-page-search][@form="mt-find"]')->length);
        self::assertSame(Words::MATCH['find_hint'], self::attr($own, '//div[@id="f-pick"]//input[@name="s"]/@placeholder'));
    }

    public function testTheProgressLineSaysWhatIsLeftAndWhatIAnsweredToday(): void
    {
        $f = $this->lists();
        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);
        // The words travel in data-progress: the list's line under its title, the variant page's bar in its head (W12.1).
        $progress = static fn (UiResponse $r): string => trim((string) self::xp($r)->evaluate('string((//*[@data-progress])[1]/@data-progress)'));

        $list = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame(ReviewController::progress(3, 0), $progress($list));
        self::assertSame(Words::say('QUEUE', 'left_many', 3) . ' ' . Words::QUEUE['today_none'], $progress($list));
        // a search does not change what is left on the list
        self::assertSame(ReviewController::progress(3, 0), $progress($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt', 'q' => 'kiwi'])));

        // one answer on the variant's page: the next page and the list say it
        $form = $this->decideForm($web, $f['h1'], ['queue' => 'Check', 'channel' => 'alt']);
        $r = $web->post('/ui/review/listing/' . $f['h1'] . '/decide', ['action' => 'link', 'units_per_item' => '1'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $next = $web->follow($r);
        self::assertSame(200, $next->status, $next->describe());
        self::assertSame(Words::say('QUEUE', 'left_many', 2) . ' ' . Words::QUEUE['today_one'], $progress($next), 'the variant\'s page opened from the list');
        self::assertSame(ReviewController::progress(2, 1), $progress($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt'])));
        // an answer of yesterday (UK time) is not today's
        self::$db->exec('UPDATE match_decision SET created_at = NOW(6) - INTERVAL 2 DAY WHERE decided_by = ?', [$user['id']]);
        self::assertSame(ReviewController::progress(2, 0), $progress($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt'])));
        // the variant's page opened from somewhere else (no list) has no progress line
        self::assertSame(0, self::xp($web->get('/ui/review/listing/' . $f['h2']))->query('//*[@data-progress]')->length);

        // someone who can only look is told what is left, not what they answered: on the list and on a variant's page opened from it
        // (Codex's review of f46d19b: the page had no line for them)
        $look = $this->signIn($this->uiUser('viewer'));
        self::assertSame(Words::say('QUEUE', 'left_many', 2), $progress($look->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt'])));
        self::assertSame(Words::say('QUEUE', 'left_many', 2), $progress($look->get('/ui/review/listing/' . $f['h2'], ['queue' => 'Check', 'channel' => 'alt'])));
        self::assertSame([Words::QUEUE['left_none'], Words::QUEUE['left_one'], Words::say('QUEUE', 'left_many', 1200) . ' ' . Words::say('QUEUE', 'today_many', 23)],
            [ReviewController::progress(0, null), ReviewController::progress(1, null), ReviewController::progress(1200, 23)]);
    }

    /** What was typed is shown as text in the found line, never as markup, and the Clear link is the page's own address. */
    public function testTheFoundLineShowsTheSearchAsText(): void
    {
        $this->lists();
        $web = $this->signIn($this->uiUser('mapper'));
        $typed = 'hayati <>&"'; // (no letter or digit in the second word: it is no word, so the search still finds the two)
        $page = $web->get('/ui/review/store', ['channel' => 'alt', 'q' => $typed]);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringNotContainsString('hayati <>', $page->body);
        self::assertStringContainsString('hayati &lt;&gt;&amp;', $page->body);
        self::assertFound($page, Words::say('FIND', 'found_many', 2, Words::quoted($typed, '')), Html::url('/ui/review/store', ['channel' => 'alt']));
    }
}
