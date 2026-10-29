<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Catalogue\ItemCards;
use CW\Settings;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\NewVariantsController;
use CW\Ui\Controller\SimpleMatchController;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * The matching lists leave out what does not matter (docs/decisions.md HU1-HU9; the owner's "Yes, build it" of 26 Oct 2026), through the
 * real /ui kernel as cw_app:
 *  - a website product not matched yet, with no units sold in the last year and first seen by CW more than a year ago, or already on its
 *    store when CW first loaded that store (HU9, the owner's answer "b": `channel.first_load_at`), is left out of
 *    Store Products (its two waiting states and All), Mapping › To review, New Variants and Simple matching's choices; a new product
 *    with no sales yet, a sold one, a matched, ignored or waiting one never is; its exact store ID or barcode still finds it; Store
 *    Products says how many at the end of the list with "Show them"; the setting at 0 turns it off;
 *  - a warehouse product that is not sold any more (its product card) or joined into another one is not offered to match to: not on
 *    Simple matching, not in the website product's page's picker; its exact CW number shows it there with no box and no "Use".
 * Nothing is deleted or changed in the data.
 */
final class HideUnsoldScreenTest extends KernelUiTestCase
{
    private const STORE = '/ui/review/store';

    protected function tearDown(): void
    {
        self::setting(365);
    }

    private static function setting(int $days): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [(string) $days, Queries::UNSOLD_SETTING]);
    }

    /** Says CW first saw the website product $days days ago. */
    private function seen(int $listing, int $days): int
    {
        self::$db->exec('UPDATE channel_listing SET created_at = UTC_TIMESTAMP(6) - INTERVAL ? DAY WHERE id = ?', [$days, $listing]);
        return $listing;
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** @return list<int> Store Products' rows (listing ids), sorted */
    private static function storeRows(UiResponse $r): array
    {
        $ids = array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "store-products")]/tbody/tr[@id]')));
        sort($ids);
        return $ids;
    }

    /** @return array<string, int> Store Products' state pills: label => count */
    private static function pills(UiResponse $r): array
    {
        $out = [];
        $xp = self::xp($r);
        foreach ($xp->query('//nav[contains(@class, "state-tabs")]/a') as $a) {
            /** @var \DOMElement $a */
            $count = (int) str_replace(',', '', self::squash($xp->evaluate('string(span[@class="count"])', $a)));
            $out[trim(str_replace((string) $count, '', self::squash($a->textContent)))] = $count;
        }
        return $out;
    }

    /**
     * The one quiet line at the end of Store Products' list (HU3): its words and its one link, or null. The line is a paragraph of its
     * own after the list (never inside the table, the bar or the pager), and there is at most one.
     *
     * @return array{text: string, link: string, href: string}|null
     */
    private static function unsoldLine(UiResponse $r): ?array
    {
        $xp = self::xp($r);
        $ps = $xp->query('//main//p[@data-unsold]');
        self::assertLessThanOrEqual(1, $ps->length, 'one line at most');
        $p = $ps->item(0);
        if (!$p instanceof \DOMElement) {
            return null;
        }
        self::assertSame(0, $xp->query('ancestor::table|ancestor::form|ancestor::nav', $p)->length, 'a line of its own, outside the list and its forms');
        self::assertSame(1, $xp->query('.//a', $p)->length, 'one plain link');
        self::assertSame('muted unsold-line', $p->getAttribute('class'), 'quiet');
        $a = $xp->query('.//a', $p)->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);
        return ['text' => self::squash($p->textContent), 'link' => self::squash($a->textContent), 'href' => $a->getAttribute('href')];
    }

    /** @return array<string, string> the query of a link */
    private static function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    private static function open(KernelBrowser $web, string $href): UiResponse
    {
        return $web->get((string) parse_url($href, PHP_URL_PATH), self::queryOf($href));
    }

    /** "3 products not sold in a year are hidden. Show them" (one: "1 product ... is hidden. Show it"). */
    private static function hiddenLine(int $n): string
    {
        return $n === 1 ? Words::STORE_PRODUCTS['unsold_one'] . ' ' . Words::STORE_PRODUCTS['unsold_show_one']
            : Words::say('STORE_PRODUCTS', 'unsold_many', $n) . ' ' . Words::STORE_PRODUCTS['unsold_show'];
    }

    /** @return list<int> the rows of a To review list (listing ids), sorted */
    private static function queueRows(UiResponse $r): array
    {
        $ids = array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "queue")]/tbody/tr[starts-with(@id, "l-")]')));
        sort($ids);
        return $ids;
    }

    /** @return list<int> New Variants' rows (listing ids, from each row's link), sorted */
    private static function newRows(UiResponse $r): array
    {
        $ids = array_map(static fn (\DOMElement $a): int => (int) substr((string) parse_url($a->getAttribute('href'), PHP_URL_PATH), strlen('/ui/review/listing/')),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "new-variants")]/tbody/tr/th/a[@class="o-name"]')));
        sort($ids);
        return $ids;
    }

    /** @return list<int> Simple matching's choices for one cell (listing ids), sorted */
    private static function choices(KernelBrowser $web, int $sku, string $code, ?string $q = null): array
    {
        $r = $web->get(SimpleMatchController::OPTIONS, ['sku' => $sku, 'channel' => $code] + ($q === null ? [] : ['q' => $q]));
        self::assertSame(200, $r->status, $r->describe());
        $ids = array_map(static fn (\DOMElement $li): int => (int) $li->getAttribute('data-listing-id'),
            iterator_to_array(self::xp($r)->query('//*[@data-sm-fragment]//ul[@data-sm-choices]/li[@data-listing-id]')));
        sort($ids);
        return $ids;
    }

    /** @return list<int> Simple matching's rows (warehouse products), in order */
    private static function matchRows(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "simple-match")]/tbody/tr')));
    }

    /** What every table of the data looks like (nothing is deleted or changed by looking). @return array<string, mixed> */
    private function data(): array
    {
        return ['listings' => self::$db->all('SELECT id, channel_id, external_variant_id, sku_id, status, map_version, created_at, updated_at FROM channel_listing ORDER BY id'),
            'profiles' => self::$db->all('SELECT listing_id, units_30d, units_365d, updated_at FROM listing_profile ORDER BY listing_id'),
            'decisions' => (int) self::$db->value('SELECT COUNT(*) FROM match_decision'), 'cards' => self::$db->all('SELECT sku_id, discontinued, version FROM item_card ORDER BY sku_id')];
    }

    /** Its item card says "Not sold any more" (or not), saved as the item card's form saves it (ItemCards: the card, its history, its audit). */
    private function card(int $sku, bool $stopped = true): int
    {
        (new ItemCards(self::$db))->save($this->staffUser('stock_controller'), $sku, 0, ['discontinued' => $stopped ? 'yes' : 'no']);
        return $sku;
    }

    // ------------------------------------------------------------------------------------------

    public function testStoreProductsHidesAnOldProductNotSoldInAYearAndOneQuietLineShowsThem(): void
    {
        $site = $this->site('electrofag', 'shadow');
        $sku = $this->item('legacy', 0, 'Matched kit');
        $counted = $this->item('strict', 0, 'Counted kit');
        $old = $this->seen($this->profiled($site, '101', ['product_title' => 'Old unsold']), 400);
        $bare = $this->seen($this->listing($site, '102', null), 400); // no profile at all: nothing sold
        $oldSuggested = $this->seen($this->queued($site, '103', 'Check', $sku, ['product_title' => 'Old suggested']), 400);
        $new = $this->profiled($site, '104', ['product_title' => 'New unsold']); // first seen now: never hidden
        $sold = $this->seen($this->profiled($site, '105', ['product_title' => 'Old but sold', 'units_365d' => 3]), 400);
        $matched = $this->seen($this->profiled($site, '106', ['product_title' => 'Old matched'], $sku), 400);
        $ignored = $this->seen($this->listing($site, '107', null, 1, 'ignored'), 400);
        $waiting = $this->profiled($site, '108', ['product_title' => 'Old waiting']);
        self::assertSame('pending_second', $this->decide($this->staffUser('mapping_lead'), 'link', $waiting, ['sku_id' => $counted])['state']);
        $this->seen($waiting, 400);
        $yearAgo = $this->seen($this->profiled($site, '109', ['product_title' => 'First seen 360 days ago']), 360); // less than a year: shown
        $before = $this->data();

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::STORE, ['channel' => 'electrofag']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame([$new, $sold, $matched, $ignored, $waiting, $yearAgo], self::storeRows($page), 'the old products not sold in a year are left out; nothing else is');
        $pills = self::pills($page);
        self::assertSame([Words::STORE_STATE['all'] => 6, Words::STORE_STATE['waiting'] => 1, Words::STORE_STATE['quarantined'] => 0, Words::STORE_STATE['ignored'] => 1,
            Words::STORE_STATE['linked'] => 1, Words::STORE_STATE['suggested'] => 0, Words::STORE_STATE['not_matched'] => 3], $pills, 'the counts are of what is listed');
        self::assertStringContainsString(Words::say('STORE_PRODUCTS', 'shown', 6, 6), $page->text());
        $line = self::unsoldLine($page);
        self::assertSame(self::hiddenLine(3), $line['text'] ?? null, 'one quiet line says how many are hidden');
        self::assertSame(Words::STORE_PRODUCTS['unsold_show'], $line['link'] ?? null);
        self::assertSame(['channel' => 'electrofag', 'unsold' => '1'], self::queryOf((string) ($line['href'] ?? '')));
        self::assertSame(0, self::xp($page)->query('//main//details[contains(@class, "help")]')->length, 'no help to unfold');

        // "Show them": every product, the counts as they are, and the line to hide them again; the choices keep showing them.
        $all = self::open($web, (string) $line['href']);
        self::assertSame(200, $all->status, $all->describe());
        self::assertSame([$old, $bare, $oldSuggested, $new, $sold, $matched, $ignored, $waiting, $yearAgo], self::storeRows($all));
        self::assertSame(1, self::pills($all)[Words::STORE_STATE['suggested']]);
        self::assertSame(5, self::pills($all)[Words::STORE_STATE['not_matched']]);
        $hide = self::unsoldLine($all);
        self::assertSame([Words::STORE_PRODUCTS['unsold_shown'] . ' ' . Words::STORE_PRODUCTS['unsold_hide'], Words::STORE_PRODUCTS['unsold_hide']],
            [$hide['text'] ?? null, $hide['link'] ?? null], 'the same line hides them again');
        self::assertSame([self::STORE, ['channel' => 'electrofag']], [parse_url((string) $hide['href'], PHP_URL_PATH), self::queryOf((string) $hide['href'])]);
        foreach (self::xp($all)->query('//nav[contains(@class, "state-tabs")]/a/@href') as $href) {
            self::assertSame('1', self::queryOf($href->nodeValue ?? '')['unsold'] ?? null, 'a state chosen while they are shown still shows them');
        }
        // (Codex's review of d65d1f2, MEDIUM) the search box and the sort are one GET form: while they are shown it sends `unsold=1` too, so a
        // search or a new sort keeps showing them; while they are hidden it sends no such field.
        self::assertSame('1', $all->form(self::STORE)['unsold'] ?? null, 'the toolbar keeps "Show them"');
        self::assertArrayNotHasKey('unsold', $page->form(self::STORE));
        $searched = $web->get(self::STORE, array_replace($all->form(self::STORE), ['q' => 'old unsold']));
        self::assertSame([$old, $new], self::storeRows($searched), 'a search after "Show them" finds an old product by its name ("unsold" holds "old")');
        self::assertSame('1', $searched->form(self::STORE)['unsold'] ?? null);
        self::assertSame([$old, $bare, $oldSuggested, $new, $sold, $matched, $ignored, $waiting, $yearAgo],
            self::storeRows($web->get(self::STORE, ['sort' => 'sold_365'] + $all->form(self::STORE))), 'and so does a new sort');

        // Each waiting state says how many of ITS rows are hidden; a state nothing is hidden from has no line.
        $notMatched = $web->get(self::STORE, ['channel' => 'electrofag', 'state' => 'not_matched']);
        self::assertSame([$new, $sold, $yearAgo], self::storeRows($notMatched));
        self::assertSame(self::hiddenLine(2), self::unsoldLine($notMatched)['text'] ?? null);
        self::assertSame(['channel' => 'electrofag', 'state' => 'not_matched', 'unsold' => '1'], self::queryOf((string) self::unsoldLine($notMatched)['href']));
        $suggested = $web->get(self::STORE, ['channel' => 'electrofag', 'state' => 'suggested']);
        self::assertSame([], self::storeRows($suggested));
        self::assertSame(self::hiddenLine(1), self::unsoldLine($suggested)['text'] ?? null, 'an empty list still says what it hides');
        self::assertSame([$oldSuggested], self::storeRows(self::open($web, (string) self::unsoldLine($suggested)['href'])));
        foreach (['linked', 'ignored', 'waiting'] as $state) {
            $r = $web->get(self::STORE, ['channel' => 'electrofag', 'state' => $state]);
            self::assertNull(self::unsoldLine($r), "{$state}: nothing is hidden there");
        }
        self::assertSame([$matched], self::storeRows($web->get(self::STORE, ['channel' => 'electrofag', 'state' => 'linked'])), 'a matched product is never hidden');

        self::assertSame($before, $this->data(), 'nothing is deleted or changed in the data');
    }

    /**
     * HU9, the owner's answer "b" ("Hide them now"): a product that was already on its store when CW first loaded that store counts as old
     * (`channel.first_load_at`: first seen at or before it), so it is hidden at once if it sold nothing in a year; a product added after that
     * load is shown until it is a year old; a store with no first load recorded has the year alone. Its exact ID still finds it, and a sale
     * brings it back. Every list, each store with its own cut-off (the lists of every store too).
     */
    public function testAProductAlreadyThereAtItsStoresFirstLoadCountsAsOld(): void
    {
        $ef = $this->site('electrofag', 'shadow');
        $vb = $this->site('vapebig', 'shadow');
        $sku = $this->item('legacy', 0, 'Suggested kit');
        $atLoad = $this->seen($this->queued($ef, '401', 'Check', $sku, ['product_title' => 'At the load, unsold', 'barcodes' => ['5056168800059']]), 40);
        $atLoadSold = $this->seen($this->queued($ef, '402', 'Check', $sku, ['product_title' => 'At the load, sold', 'units_365d' => 2]), 40);
        $after = $this->seen($this->queued($ef, '403', 'Check', $sku, ['product_title' => 'Added the day after the load']), 39);
        $today = $this->queued($ef, '404', 'Check', $sku, ['product_title' => 'Added today']);
        $matched = $this->seen($this->profiled($ef, '405', ['product_title' => 'At the load, matched'], $sku), 40);
        // The load ended with the last of those rows: a row first seen exactly then is one of it.
        self::$db->exec('UPDATE channel SET first_load_at = (SELECT MAX(created_at) FROM channel_listing WHERE id IN (?, ?, ?)) WHERE id = ?',
            [$atLoad, $atLoadSold, $matched, $ef->channelId]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing cl JOIN channel c ON c.id = cl.channel_id WHERE cl.created_at = c.first_load_at'));
        // Another store, its first load not recorded: the year alone (a product first seen 40 days ago is shown).
        $vbOld = $this->seen($this->queued($vb, '501', 'Check', $sku, ['product_title' => 'Vape Big, 40 days, unsold']), 40);
        $vbYear = $this->seen($this->queued($vb, '502', 'Check', $sku, ['product_title' => 'Vape Big, 400 days, unsold']), 400);
        $web = $this->signIn($this->uiUser('mapper'));

        $store = $web->get(self::STORE, ['channel' => 'electrofag']);
        self::assertSame([$atLoadSold, $after, $today, $matched], self::storeRows($store));
        self::assertSame(self::hiddenLine(1), self::unsoldLine($store)['text'] ?? null);
        self::assertSame([$atLoad], self::storeRows($web->get(self::STORE, ['channel' => 'electrofag', 'q' => '401'])), 'its exact ID finds it');
        self::assertSame([$atLoad], self::storeRows($web->get(self::STORE, ['channel' => 'electrofag', 'q' => '5056168800059'])), 'and its barcode');
        self::assertSame([$vbOld], self::storeRows($web->get(self::STORE, ['channel' => 'vapebig'])), 'no first load recorded: the year alone');

        // To review: one store, and every store at once (each store with its own cut-off), with its counts.
        self::assertSame([$atLoadSold, $after, $today], self::queueRows($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'electrofag'])));
        $q = new Queries(self::$db);
        $every = array_map(static fn (array $r): int => (int) $r['listing_id'], $q->queue('Check', null, '', null, 0, 1)['rows']);
        sort($every);
        self::assertSame([$atLoadSold, $after, $today, $vbOld], $every);
        $byStore = $q->bandCounts()['Check'] ?? [];
        ksort($byStore);
        self::assertSame([(int) $ef->channelId => 3, (int) $vb->channelId => 1], $byStore);
        self::assertSame([4, 3], [$q->listLeft('Check', null), $q->listLeft('Check', (int) $ef->channelId)]);
        // New Variants (any time) and Simple matching's choices.
        self::assertSame([$atLoadSold, $after, $today], self::newRows($web->get(NewVariantsController::PATH, ['channel' => 'electrofag', 'period' => 'all'])));
        self::assertSame([$atLoadSold, $after, $today], self::choices($web, $sku, 'electrofag'), 'an empty box: the item\'s suggestions');
        self::assertSame([$atLoad], self::choices($web, $sku, 'electrofag', '401'));
        self::assertSame(['all' => 1, 'open' => 1, 'new' => 0], $q->unsoldHidden((int) $ef->channelId));
        // A store loaded more than a year ago: a product added after the load, first seen more than a year ago, is old by the year.
        self::$db->exec('UPDATE channel SET first_load_at = UTC_TIMESTAMP(6) - INTERVAL 500 DAY WHERE id = ?', [$ef->channelId]);
        self::$db->exec('UPDATE channel_listing SET created_at = UTC_TIMESTAMP(6) - INTERVAL 400 DAY WHERE id = ?', [$after]);
        self::assertSame([$atLoad, $atLoadSold, $today, $matched], self::storeRows($web->get(self::STORE, ['channel' => 'electrofag'])));
        // Its first sale brings it back.
        self::$db->exec('UPDATE listing_profile SET units_365d = 1 WHERE listing_id = ?', [$after]);
        self::assertContains($after, self::storeRows($web->get(self::STORE, ['channel' => 'electrofag'])));
        unset($vbYear);
    }

    public function testASearchFindsAHiddenProductByItsExactIdOrBarcodeAndOnlyThen(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $dusty = $this->seen($this->profiled($site, '7001', ['product_title' => 'Dusty kit', 'barcodes' => ['5056168800011']]), 400);
        $sold = $this->seen($this->profiled($site, '7002', ['product_title' => 'Dusty kit two', 'units_365d' => 1]), 400);
        $web = $this->signIn($this->uiUser('viewer'));

        $words = $web->get(self::STORE, ['channel' => 'vapebig', 'q' => 'dusty']);
        self::assertSame([$sold], self::storeRows($words), 'its words alone do not bring it back');
        self::assertSame(self::hiddenLine(1), self::unsoldLine($words)['text'] ?? null, 'the line counts what the search hides');
        self::assertSame(['channel' => 'vapebig', 'q' => 'dusty', 'unsold' => '1'], self::queryOf((string) self::unsoldLine($words)['href']));
        self::assertSame([$dusty, $sold], self::storeRows(self::open($web, (string) self::unsoldLine($words)['href'])));
        foreach (['7001', '5056168800011', '05056168800011'] as $exact) {
            $r = $web->get(self::STORE, ['channel' => 'vapebig', 'q' => $exact]);
            self::assertSame([$dusty], self::storeRows($r), "{$exact}: its exact ID or barcode finds it");
            self::assertNull(self::unsoldLine($r), 'found, so nothing is hidden');
        }
    }

    public function testTheSettingAtZeroTurnsItOffAndTheSettingSaysTheYear(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $old = $this->seen($this->profiled($site, '7001', ['product_title' => 'Dusty kit']), 400);
        $web = $this->signIn($this->uiUser('viewer'));
        self::assertSame(365, (new Settings(self::$db))->get(Queries::UNSOLD_SETTING), 'on by default: a year');
        self::assertSame([], self::storeRows($web->get(self::STORE, ['channel' => 'vapebig'])));
        self::setting(0);
        $off = $web->get(self::STORE, ['channel' => 'vapebig']);
        self::assertSame([$old], self::storeRows($off));
        self::assertNull(self::unsoldLine($off), 'off: no line');
        self::assertNull(self::unsoldLine($web->get(self::STORE, ['channel' => 'vapebig', 'unsold' => '1'])), 'off: "show them" has nothing to say');
        self::assertSame(['in' => [0, 365]], Settings::RULES[Queries::UNSOLD_SETTING], 'on (365 days) or off (0): the websites send the units of one year');
        self::assertFalse((new Queries(self::$db))->unsoldOn(), 'off: nothing is hidden anywhere');
        self::assertNull((new Queries(self::$db))->unsoldBefore((int) $site->channelId));
    }

    public function testToReviewLeavesThemOutOfTheListAndItsCountsButTheExactIdOrBarcodeFindsThem(): void
    {
        $site = $this->site('electrofag', 'shadow');
        $sku = $this->item('legacy', 0, 'Suggested kit');
        $old = $this->seen($this->queued($site, '201', 'Check', $sku, ['product_title' => 'Elux old', 'barcodes' => ['5056168800028']]), 400);
        $new = $this->queued($site, '202', 'Check', $sku, ['product_title' => 'Elux new']);
        $sold = $this->seen($this->queued($site, '203', 'Check', $sku, ['product_title' => 'Elux sold', 'units_365d' => 2]), 400);
        $web = $this->signIn($this->uiUser('mapper'));

        $list = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'electrofag']);
        self::assertSame(200, $list->status, $list->describe() . implode("\n", self::$log));
        self::assertSame([$new, $sold], self::queueRows($list));
        $legend = self::squash(self::xp($list)->evaluate('string(//nav[contains(@class, "legend")]/a[@aria-current="page"]/span[@class="count"])'));
        self::assertSame('2', $legend, 'the list\'s own count leaves them out too');
        self::assertSame(2, (new Queries(self::$db))->listLeft('Check', (int) $site->channelId), 'and the progress line of a product opened from it');
        self::assertSame([$new, $sold], self::queueRows($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'electrofag', 'q' => 'elux'])), 'words do not bring it back');
        foreach (['201', '5056168800028'] as $exact) {
            self::assertSame([$old], self::queueRows($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'electrofag', 'q' => $exact])), $exact);
        }
        self::setting(0);
        self::assertSame([$old, $new, $sold], self::queueRows($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'electrofag'])), 'off: every one');
    }

    public function testNewVariantsLeavesThemOutOfAnyTimeButTheExactIdFindsThem(): void
    {
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy', 0, 'Matched kit');
        $old = $this->seen($this->profiled($site, '301', ['product_title' => 'Old unsold', 'barcodes' => ['5056168800035']]), 400);
        $new = $this->profiled($site, '302', ['product_title' => 'New unsold']);
        $sold = $this->seen($this->profiled($site, '303', ['product_title' => 'Old sold', 'units_365d' => 5]), 400);
        $matched = $this->seen($this->profiled($site, '304', ['product_title' => 'Old matched'], $sku), 400);
        $web = $this->signIn($this->uiUser('mapper'));
        $path = NewVariantsController::PATH;

        $any = $web->get($path, ['channel' => 'alt', 'period' => 'all']);
        self::assertSame(200, $any->status, $any->describe() . implode("\n", self::$log));
        self::assertSame([$new, $sold], self::newRows($any), 'still to match, any time: not the old product nobody bought');
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'shown', 2, 2), $any->text(), 'its count leaves it out');
        self::assertSame([$new, $sold, $matched], self::newRows($web->get($path, ['channel' => 'alt', 'period' => 'all', 'state' => 'all'])), 'a matched one is never hidden');
        self::assertSame([$new], self::newRows($web->get($path, ['channel' => 'alt'])), 'the period the page opens with');
        foreach (['301', '5056168800035'] as $exact) {
            self::assertSame([$old], self::newRows($web->get($path, ['channel' => 'alt', 'period' => 'all', 'q' => $exact])), $exact);
        }
        $q = new Queries(self::$db);
        $counts = $q->newVariantCounts((int) $site->channelId, null);
        self::assertSame(2, Queries::newVariantTotal($counts, 'unmatched'));
        self::assertSame([$new, $sold], $q->newVariants((int) $site->channelId, null, 'unmatched', '', null, $counts, 50)['ids'], 'the CSV\'s list too, newest first');
        self::setting(0);
        self::assertSame([$old, $new, $sold], self::newRows($web->get($path, ['channel' => 'alt', 'period' => 'all'])));
    }

    public function testSimpleMatchingsChoicesLeaveThemOutButTheExactIdOrBarcodeFindsThem(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $old = $this->seen($this->profiled($site, '5001', ['product_title' => 'Dusty kit', 'barcodes' => ['5056168800042']]), 400);
        $new = $this->profiled($site, '5002', ['product_title' => 'Dusty fresh']);
        $sold = $this->seen($this->profiled($site, '5003', ['product_title' => 'Dusty sold', 'units_365d' => 4]), 400);
        $oldSuggestion = $this->seen($this->queued($site, '5004', 'Check', $alpha, ['product_title' => 'Old suggestion']), 400);
        $newSuggestion = $this->queued($site, '5005', 'Check', $alpha, ['product_title' => 'New suggestion']);
        $web = $this->signIn($this->uiUser('mapper'));

        self::assertSame([$new, $sold], self::choices($web, $alpha, 'vapebig', 'dusty'), 'the words of a name');
        self::assertSame([$newSuggestion], self::choices($web, $alpha, 'vapebig'), 'an empty box: the item\'s own suggestions, not the old one');
        self::assertSame([$old], self::choices($web, $alpha, 'vapebig', '5001'), 'its exact ID');
        self::assertSame([$old], self::choices($web, $alpha, 'vapebig', '5056168800042'), 'its barcode');
        self::assertSame([$oldSuggestion], self::choices($web, $alpha, 'vapebig', '5004'));
        // By Item's picker is a lead's page and stays as it was (HU2).
        self::assertSame([$old, $new, $sold], array_values(array_intersect([$old, $new, $sold],
            (new Queries(self::$db))->waitingListings((int) $site->channelId, 'dusty', 1)['ids'])));
        self::setting(0);
        self::assertSame([$old, $new, $sold], self::choices($web, $alpha, 'vapebig', 'dusty'));
        self::assertSame([$oldSuggestion, $newSuggestion], self::choices($web, $alpha, 'vapebig'));
    }

    public function testSimpleMatchingLeavesOutItemsNotSoldAnyMoreOrJoinedButTheirExactCwNumberShowsThemWithNoBox(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $carded = $this->item('legacy', 0, 'Carded kit');
        $this->card($carded, false);
        $stopped = $this->card($this->item('legacy', 0, 'Stopped kit'));
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, source) VALUES ('5060000000024', ?, 'test')", [$stopped]);
        $joined = $this->item('legacy', 0, 'Joined kit');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$alpha, $joined]);
        $sold = $this->profiled($site, '6001', ['product_title' => 'Stopped kit on Vape Big'], $stopped);
        $waiting = $this->profiled($site, '6002', ['product_title' => 'Waiting product']);
        $code = static fn (int $id): string => sprintf('CW-%06d', $id);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(SimpleMatchController::PATH);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame([$alpha, $carded], self::matchRows($page), 'not sold any more, or joined into another: not offered');
        $q = new Queries(self::$db);
        self::assertSame([3, 2], [$q->productTotal(), $q->productTotal(true)], 'the page counts what it lists (By Item\'s count is as it was)');
        self::assertSame([], self::matchRows($web->get(SimpleMatchController::PATH, ['q' => 'stopped'])), 'its name does not bring it back');
        self::assertSame([], self::matchRows($web->get(SimpleMatchController::PATH, ['q' => '5060000000024'])), 'nor its barcode');

        foreach ([[$stopped, $code($stopped), Words::SIMPLE_MATCH['stopped']], [$stopped, 'cw ' . $stopped, Words::SIMPLE_MATCH['stopped']],
            [$joined, $code($joined), Words::say('SIMPLE_MATCH', 'joined', $code($alpha))]] as [$id, $typed, $note]) {
            $r = $web->get(SimpleMatchController::PATH, ['q' => $typed]);
            self::assertSame([$id], self::matchRows($r), "{$typed}: its exact CW number shows it");
            $xp = self::xp($r);
            self::assertSame($note, self::squash($xp->evaluate('string(//tr[@id="m-' . $id . '"]/th/span[contains(@class, "sm-stop")])')), 'it says why it is not matched here');
            self::assertSame(0, $xp->query('//tr[@id="m-' . $id . '"]//form')->length, 'read only: no box, no "+", no Apply');
            self::assertSame(0, $xp->query('//tr[@id="m-' . $id . '"]//details')->length);
        }
        $shown = $web->get(SimpleMatchController::PATH, ['q' => $code($stopped)]);
        self::assertStringContainsString('Stopped kit on Vape Big', self::squash(self::xp($shown)->evaluate('string(//tr[@id="m-' . $stopped . '"]/td[@data-channel="vapebig"])')),
            'what is matched to it is still shown');

        // A hand-made request: the item's choices say why there are none, and Apply saves nothing.
        $frag = $web->get(SimpleMatchController::OPTIONS, ['sku' => $stopped, 'channel' => 'vapebig', 'q' => '6002']);
        self::assertSame(200, $frag->status, $frag->describe());
        self::assertSame(0, self::xp($frag)->query('//*[@data-sm-fragment]//li[@data-listing-id]')->length);
        self::assertSame(Words::SIMPLE_MATCH['stopped_choices'], self::squash(self::xp($frag)->evaluate('string(//*[@data-sm-fragment]//*[@data-sm-says])')));
        $form = $page->form(SimpleMatchController::APPLY);
        self::assertNotSame([], $form, 'a mapper has a box on the other rows');
        $before = $this->data();
        $r = $web->post(SimpleMatchController::APPLY, ['sku_id' => (string) $stopped, 'channel' => 'vapebig', 'listing_id' => (string) $waiting,
            'expected_map_version' => (string) $this->version($waiting), 'proposal_id' => '', 'pick' => '6002'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('stopped', self::queryOf((string) $r->location())['said'] ?? null);
        self::assertSame($before, $this->data(), 'nothing was saved');
        $back = self::open($web, (string) $r->location());
        self::assertStringContainsString(Words::SIMPLE_MATCH['said_stopped'], $back->text(), 'said in words, above the list (the row is not on it)');
        self::assertSame([null, 'unmapped'], [$this->link($waiting)['sku_id'], $this->link($waiting)['status']]);
        self::assertSame($stopped, $this->link($sold)['sku_id']);
    }

    public function testTheWebsiteProductsPickerLeavesThemOutButTheirExactCwNumberShowsThemWithoutUse(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $alpha = $this->item('legacy', 0, 'Picker kit one');
        $stopped = $this->card($this->item('legacy', 0, 'Picker kit two'));
        $joined = $this->item('legacy', 0, 'Picker kit three');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$alpha, $joined]);
        $l = $this->profiled($site, '8001', ['product_title' => 'Picker kit']);
        $code = static fn (int $id): string => sprintf('CW-%06d', $id);
        $web = $this->signIn($this->uiUser('mapper'));
        $found = static function (UiResponse $r): array {
            $xp = self::xp($r);
            $out = [];
            foreach ($xp->query('//table[contains(@class, "found")]/tbody/tr') as $tr) {
                $out[] = [self::squash($xp->evaluate('string(th/a)', $tr)), $xp->query('.//a[contains(@href, "pick=")]', $tr)->length === 1,
                    self::squash($xp->evaluate('string(td[contains(@class, "c-next")])', $tr))];
            }
            return $out;
        };

        $words = $web->get('/ui/review/listing/' . $l, ['s' => 'picker kit']);
        self::assertSame(200, $words->status, $words->describe() . implode("\n", self::$log));
        self::assertSame([[$code($alpha), true, Words::LISTING['use']]], $found($words), 'not sold any more, or joined: not offered');
        self::assertSame([[$code($stopped), false, Words::LISTING['found_stopped']]], $found($web->get('/ui/review/listing/' . $l, ['s' => $code($stopped)])),
            'its exact CW number shows it, with no "Use"');
        self::assertSame([[$code($joined), false, Words::say('LISTING', 'found_joined', $code($alpha))]], $found($web->get('/ui/review/listing/' . $l, ['s' => $code($joined)])));
        // A "pick" that names it (an old link): the page says so and compares nothing with it.
        $picked = $web->get('/ui/review/listing/' . $l, ['pick' => $stopped]);
        self::assertStringContainsString(Words::LISTING['pick_stopped'], $picked->text());
        self::assertStringNotContainsString($code($stopped), self::squash(self::xp($picked)->evaluate('string(//p[@class="title"])')));
        self::assertNotSame((string) $stopped, $picked->form('/ui/review/listing/' . $l . '/decide')['sku_id'] ?? '');
    }

    /**
     * Speed (HU7): the count of what Store Products hides is ONE range of the index of 0051 (store, status, first seen), never a pass over
     * the store, and the units are asked of the narrow index of 0020; the products not sold any more are one range of item_card's index.
     */
    public function testTheCountsReadTheirIndexes(): void
    {
        $site = $this->site('vapebig', 'shadow');
        for ($i = 1; $i <= 30; $i++) {
            $this->seen($this->profiled($site, 'v' . $i, ['units_365d' => $i % 3]), $i % 2 === 0 ? 400 : 1);
        }
        $q = new Queries(self::$db);
        $before = (string) $q->unsoldBefore((int) $site->channelId);
        $plan = self::$db->all('EXPLAIN ' . Queries::UNSOLD_HIDDEN_SQL, [(int) $site->channelId, $before]);
        $by = array_column($plan, null, 'table');
        self::assertSame('ix_channel_listing_unsold', $by['cl']['key'] ?? null, json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertSame('range', $by['cl']['type']);
        self::assertStringContainsString('Using index', (string) $by['cl']['Extra'], 'the listing rows are not read');
        self::assertSame('ix_listing_profile_units', $by['lu']['key'] ?? null, json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Using index', (string) $by['lu']['Extra']);
        self::assertSame(['all' => 5, 'open' => 0, 'new' => 0], array_intersect_key($q->unsoldHidden((int) $site->channelId), ['all' => 1, 'open' => 1, 'new' => 1]));
        $this->card($this->item('legacy'), false);
        $this->card($this->item('legacy'));
        self::assertSame(1, (int) self::$db->value(Queries::STOPPED_SQL));
        $stopped = self::$db->all('EXPLAIN ' . Queries::STOPPED_SQL);
        self::assertSame('ix_item_card_discontinued', array_column($stopped, null, 'table')['c']['key'] ?? null, json_encode($stopped, JSON_THROW_ON_ERROR));
    }
}
