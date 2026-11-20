<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\UiResponse;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * Store Products' All leaves out the ignored products (docs/decisions.md HI1-HI4; the owner, 20 Nov 2026, of the three Vape and Go "Nic Nic"
 * multipack pages ignored on purpose since the shop sells single shots: "why are we keeping it still in CW to confuse staff"; he chose to hide
 * Ignored from the normal list and show it only when someone picks Ignored). Through the real /ui kernel as cw_app, the same for every store:
 *  - All, with or without a search, and its count and its pages, leave out a product whose state is Ignored (ignored and no decision waiting
 *    for a second OK: one waiting stays on All);
 *  - the Ignored choice keeps its count and lists them, with its bulk actions (take off the ignored list);
 *  - ONE quiet line at the end of All says how many it left out, with "Show them" to the Ignored choice, keeping the search (and the sort);
 *  - the old products not sold in a year (HU1-HU4) are hidden next to it as before, each with its own line;
 *  - a row's Open (StoreContext) and a bulk action lead back to the list they came from;
 *  - the All list stays on the store's (channel_id, status) index.
 * Nothing is deleted or changed in the data: the shops send every page every night, so a deleted row would come back as not matched.
 */
final class HideIgnoredScreenTest extends KernelUiTestCase
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

    /** A website product (with its profile) that is ignored, as a decision leaves it. @param array<string, mixed> $p */
    private function ignored(Caller $site, string $variant, array $p = []): int
    {
        $id = $this->profiled($site, $variant, $p);
        self::$db->exec("UPDATE channel_listing SET status = 'ignored' WHERE id = ?", [$id]);
        return $id;
    }

    /** Says CW first saw the website product $days days ago. */
    private function seen(int $listing, int $days): int
    {
        self::$db->exec('UPDATE channel_listing SET created_at = UTC_TIMESTAMP(6) - INTERVAL ? DAY WHERE id = ?', [$days, $listing]);
        return $listing;
    }

    private function listingStatus(int $listing): string
    {
        return (string) self::$db->value('SELECT status FROM channel_listing WHERE id = ?', [$listing]);
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
        $ids = self::orderedRows($r);
        sort($ids);
        return $ids;
    }

    /** @return list<int> Store Products' rows (listing ids), in the page's order */
    private static function orderedRows(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array(self::xp($r)->query('//table[contains(@class, "store-products")]/tbody/tr[@id]')));
    }

    /** @return array<string, int> Store Products' states (the Show row's choice): label => count */
    private static function pills(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//form[contains(@class, "toolbar")]//select[@name="state"]/option') as $o) {
            /** @var \DOMElement $o */
            preg_match('/^(.*?)\s*\((\d[\d,]*)\)$/', self::squash($o->textContent), $m);
            $out[$m[1] ?? self::squash($o->textContent)] = (int) str_replace(',', '', $m[2] ?? '0');
        }
        return $out;
    }

    /**
     * A quiet line at the end of the list ($data: `data-ignored` for the ignored products, `data-unsold` for the old ones not sold in a
     * year): its words and its one link, or null. A paragraph of its own after the list, never inside the table, a form or the pager;
     * at most one of each.
     *
     * @return array{text: string, link: string, href: string}|null
     */
    private static function quietLine(UiResponse $r, string $data, string $class): ?array
    {
        $xp = self::xp($r);
        $ps = $xp->query('//main//p[@' . $data . ']');
        self::assertLessThanOrEqual(1, $ps->length, 'one line at most');
        $p = $ps->item(0);
        if (!$p instanceof \DOMElement) {
            return null;
        }
        self::assertSame(0, $xp->query('ancestor::table|ancestor::form|ancestor::nav', $p)->length, 'a line of its own, outside the list and its forms');
        self::assertSame(1, $xp->query('.//a', $p)->length, 'one plain link');
        self::assertSame('muted ' . $class, $p->getAttribute('class'), 'quiet');
        $a = $xp->query('.//a', $p)->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);
        return ['text' => self::squash($p->textContent), 'link' => self::squash($a->textContent), 'href' => $a->getAttribute('href')];
    }

    /** @return array{text: string, link: string, href: string}|null the line of the ignored products left out of All */
    private static function ignoredLine(UiResponse $r): ?array
    {
        return self::quietLine($r, 'data-ignored', 'ignored-line');
    }

    /** @return array{text: string, link: string, href: string}|null the line of the old products not sold in a year (HU3) */
    private static function unsoldLine(UiResponse $r): ?array
    {
        return self::quietLine($r, 'data-unsold', 'unsold-line');
    }

    /** "3 ignored products are not shown here. Show them" (one: "1 ignored product is not shown here. Show it"). */
    private static function ignoredWords(int $n): string
    {
        return $n === 1 ? Words::STORE_PRODUCTS['ignored_one'] . ' ' . Words::STORE_PRODUCTS['ignored_show_one']
            : Words::say('STORE_PRODUCTS', 'ignored_many', $n) . ' ' . Words::STORE_PRODUCTS['ignored_show'];
    }

    /** "3 products not sold in a year are hidden. Show them" (HU3). */
    private static function unsoldWords(int $n): string
    {
        return $n === 1 ? Words::STORE_PRODUCTS['unsold_one'] . ' ' . Words::STORE_PRODUCTS['unsold_show_one']
            : Words::say('STORE_PRODUCTS', 'unsold_many', $n) . ' ' . Words::STORE_PRODUCTS['unsold_show'];
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

    /** The search note's words (SX5), or '' when there is none. */
    private static function found(UiResponse $r): string
    {
        return self::squash(self::xp($r)->evaluate('string(//p[@class="search-note"]/span[@class="search-found"])'));
    }

    private static function pagerText(UiResponse $r): ?string
    {
        $nav = self::xp($r)->query('//nav[contains(@class, "pager")]/span')->item(0);
        return $nav === null ? null : self::squash($nav->textContent);
    }

    private static function pagerLink(UiResponse $r, string $rel): ?string
    {
        $a = self::xp($r)->query('//nav[contains(@class, "pager")]//a[@rel="' . $rel . '"]')->item(0);
        return $a instanceof \DOMElement ? $a->getAttribute('href') : null;
    }

    /** A row's Open (its name's link). */
    private static function rowLink(UiResponse $r, int $listing): string
    {
        return (string) self::xp($r)->evaluate('string(//tr[@id="l-' . $listing . '"]//a[@class="o-name"]/@href)');
    }

    /** The page's way back (the eyebrow's or the crumbs' first link). */
    private static function backLink(UiResponse $r): string
    {
        return (string) self::xp($r)->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a[1]/@href)');
    }

    /** @return list<string> the actions of the page's bulk bar */
    private static function barActions(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array(self::xp($r)->query('//div[@data-bulkbar]//button[@name="action"]')));
    }

    /** @return list<int> the rows that can be ticked, sorted */
    private static function tickable(UiResponse $r): array
    {
        $ids = array_map(static fn (\DOMElement $i): int => (int) substr($i->getAttribute('name'), 5), iterator_to_array(self::xp($r)->query('//input[@data-bulk-row]')));
        sort($ids);
        return $ids;
    }

    /** @param list<int> $ids @return array<string, string> */
    private static function ticked(UiResponse $page, array $ids, string $action): array
    {
        $form = $page->form('/ui/review/bulk');
        self::assertNotSame([], $form, 'the page has the bulk form: ' . $page->describe());
        foreach ($ids as $id) {
            self::assertArrayHasKey('ver_' . $id, $form, "row {$id} is on the page");
            $form['pick_' . $id] = '1';
        }
        return $form + ['action' => $action];
    }

    /** What the data looks like (nothing is deleted or changed by looking). @return array<string, mixed> */
    private function data(): array
    {
        return ['listings' => self::$db->all('SELECT id, channel_id, external_variant_id, sku_id, status, map_version, created_at, updated_at FROM channel_listing ORDER BY id'),
            'decisions' => self::$db->all('SELECT id, listing_id, state FROM match_decision ORDER BY id')];
    }

    /** @param list<int> $ids @return list<int> */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    // ------------------------------------------------------------------------------------------

    public function testAllLeavesOutTheIgnoredProductsAndOneQuietLineLeadsToThem(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $sku = $this->item('legacy', 0, 'Matched kit');
        $counted = $this->item('strict', 0, 'Counted kit');
        // The three multipack pages, ignored on purpose; best sellers, so they would be first on All.
        $nic = [$this->ignored($site, 'N10', ['product_title' => 'Nic Nic 10 pack 10mg', 'units_30d' => 50]),
            $this->ignored($site, 'N18', ['product_title' => 'Nic Nic 10 pack 18mg', 'units_30d' => 40]),
            $this->ignored($site, 'N20', ['product_title' => 'Nic Nic 10 pack 20mg', 'units_30d' => 30])];
        $single = $this->profiled($site, 'S18', ['product_title' => 'Nic Nic single shot 18mg', 'units_30d' => 5]);
        $suggested = $this->queued($site, 'K1', 'Check', $sku, ['product_title' => 'Suggested kit']);
        $matched = $this->profiled($site, 'M1', ['product_title' => 'Matched kit'], $sku);
        $waiting = $this->ignored($site, 'W1', ['product_title' => 'Ignored, waiting for a second OK']);
        self::assertSame('pending_second', $this->decide($this->staffUser('mapping_lead'), 'link', $waiting, ['sku_id' => $counted])['state']);
        self::assertSame('ignored', $this->listingStatus($waiting), 'still ignored until the second OK');
        $before = $this->data();

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::STORE, ['channel' => 'vapeandgo']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame(self::sorted([$single, $suggested, $matched, $waiting]), self::storeRows($page),
            'All leaves out the ignored products; one waiting for a second OK is not Ignored and stays');
        self::assertSame([Words::STORE_STATE['all'] => 4, Words::STORE_STATE['waiting'] => 1, Words::STORE_STATE['quarantined'] => 0, Words::STORE_STATE['ignored'] => 3,
            Words::STORE_STATE['linked'] => 1, Words::STORE_STATE['suggested'] => 1, Words::STORE_STATE['not_matched'] => 1], self::pills($page),
            'All counts what it lists; Ignored keeps its own count');
        $groups = array_map(static fn (\DOMNode $b): string => self::squash($b->textContent), iterator_to_array(self::xp($page)->query('//div[contains(@class, "doc-group")]//button[@class="grp-toggle"]')));
        self::assertNotContains(Words::STORE_STATE['ignored'], $groups, 'no Ignored group on the board');
        self::assertSame(4, self::xp($page)->query('//div[contains(@class, "doc-group")]//tbody/tr')->length, 'the board lists what is counted');
        self::assertSame(['3 ignored products are not shown here.', '1 ignored product is not shown here.', 'Show them', 'Show it'],
            [Words::say('STORE_PRODUCTS', 'ignored_many', 3), Words::STORE_PRODUCTS['ignored_one'], Words::STORE_PRODUCTS['ignored_show'],
                Words::STORE_PRODUCTS['ignored_show_one']], 'plain words');
        $line = self::ignoredLine($page);
        self::assertSame(self::ignoredWords(3), $line['text'] ?? null, 'one quiet line says how many are left out');
        self::assertSame(Words::STORE_PRODUCTS['ignored_show'], $line['link'] ?? null);
        self::assertSame([self::STORE, ['channel' => 'vapeandgo', 'state' => 'ignored']],
            [parse_url((string) ($line['href'] ?? ''), PHP_URL_PATH), self::queryOf((string) ($line['href'] ?? ''))], '"Show them" is the Ignored choice');
        self::assertNull(self::unsoldLine($page), 'nothing old is hidden here: the ignored products are not "not sold in a year"');
        self::assertSame(0, self::xp($page)->query('//main//details[contains(@class, "help")]')->length, 'no help to unfold');
        self::assertStringNotContainsString('Nic Nic 10 pack', $page->text(), 'their names are nowhere on All');

        // The Ignored choice lists them with its count, each can be ticked, and the bar takes them off the ignored list.
        $ign = self::open($web, (string) $line['href']);
        self::assertSame(200, $ign->status, $ign->describe());
        self::assertSame(self::sorted($nic), self::storeRows($ign));
        self::assertSame($nic, self::orderedRows($ign), 'best sellers first');
        self::assertSame(3, self::pills($ign)[Words::STORE_STATE['ignored']]);
        self::assertSame(4, self::pills($ign)[Words::STORE_STATE['all']], 'All is counted the same from every choice');
        self::assertSame(self::sorted($nic), self::tickable($ign), 'each can be ticked');
        self::assertContains('unignore', self::barActions($ign), 'take off the ignored list');
        self::assertNull(self::ignoredLine($ign), 'no line where they are listed');
        foreach (['waiting', 'quarantined', 'linked', 'suggested', 'not_matched'] as $state) {
            $r = $web->get(self::STORE, ['channel' => 'vapeandgo', 'state' => $state]);
            self::assertSame(200, $r->status, $r->describe());
            self::assertNull(self::ignoredLine($r), "{$state}: an ignored product is never in it, so nothing is said");
        }
        self::assertSame([$waiting], self::storeRows($web->get(self::STORE, ['channel' => 'vapeandgo', 'state' => 'waiting'])));
        self::assertSame($before, $this->data(), 'nothing is deleted or changed in the data');

        // The same on another store, with nothing ignored: no line.
        $other = $this->site('vapebig', 'shadow');
        $plain = $this->profiled($other, 'B1', ['product_title' => 'Big kit']);
        $big = $web->get(self::STORE, ['channel' => 'vapebig']);
        self::assertSame([$plain], self::storeRows($big));
        self::assertNull(self::ignoredLine($big));
        $hidden = $this->ignored($other, 'B2', ['product_title' => 'Big parent page']);
        $big = $web->get(self::STORE, ['channel' => 'vapebig']);
        self::assertSame([$plain], self::storeRows($big), 'every store the same');
        self::assertSame(self::ignoredWords(1), self::ignoredLine($big)['text'] ?? null);
        self::assertSame(Words::STORE_PRODUCTS['ignored_show_one'], self::ignoredLine($big)['link'] ?? null);
        self::assertSame([$hidden], self::storeRows(self::open($web, (string) self::ignoredLine($big)['href'])));

        // A store whose every product is ignored: All lists nothing and says so (not "This store has no website products"), and the line.
        $third = $this->site('alt', 'shadow');
        $this->ignored($third, 'A1', ['product_title' => 'Alt parent page']);
        $none = $web->get(self::STORE, ['channel' => 'alt']);
        self::assertSame([], self::storeRows($none));
        self::assertStringContainsString(Words::STORE_PRODUCTS['empty_hidden'], $none->text());
        self::assertStringNotContainsString(Words::STORE_PRODUCTS['empty'], $none->text());
        self::assertSame(self::ignoredWords(1), self::ignoredLine($none)['text'] ?? null);

    }

    public function testASearchOnAllLeavesThemOutTooAndTheLineKeepsTheSearch(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $nic10 = $this->ignored($site, 'N10', ['product_title' => 'Nic Nic 10 pack 10mg', 'units_30d' => 9]);
        $nic20 = $this->ignored($site, 'N20', ['product_title' => 'Nic Nic 10 pack 20mg', 'units_30d' => 8]);
        $parent = $this->ignored($site, 'X1', ['product_title' => 'Old parent page']);
        $single = $this->profiled($site, 'S18', ['product_title' => 'Nic Nic single shot 18mg']);
        $web = $this->signIn($this->uiUser('viewer'));

        $found = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'nic nic']);
        self::assertSame(200, $found->status, $found->describe() . implode("\n", self::$log));
        self::assertSame([$single], self::storeRows($found), 'a search on All leaves them out');
        self::assertSame(self::squash(Words::say('FIND', 'found_one', Words::quoted('nic nic', ''))), self::found($found), 'the search note counts what is listed');
        $line = self::ignoredLine($found);
        self::assertSame(self::ignoredWords(2), $line['text'] ?? null, 'the line counts what the search left out');
        self::assertSame(['channel' => 'vapeandgo', 'state' => 'ignored', 'q' => 'nic nic'], self::queryOf((string) ($line['href'] ?? '')), '"Show them" keeps the search');
        $shown = self::open($web, (string) $line['href']);
        self::assertSame([$nic10, $nic20], self::storeRows($shown));
        self::assertSame('nic nic', $shown->form(self::STORE)['q'] ?? null, 'the search box still holds it');
        self::assertNull(self::ignoredLine($shown));

        // The sort is kept too; one is said as one.
        $sorted = $web->get(self::STORE, ['channel' => 'vapeandgo', 'sort' => 'sold_365', 'q' => 'nic nic']);
        self::assertSame(['channel' => 'vapeandgo', 'state' => 'ignored', 'sort' => 'sold_365', 'q' => 'nic nic'], self::queryOf((string) self::ignoredLine($sorted)['href']));
        $onlyIgnored = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'parent page']);
        self::assertSame([], self::storeRows($onlyIgnored));
        self::assertStringContainsString(Words::STORE_PRODUCTS['empty_filter'], $onlyIgnored->text(), 'nothing listed: the empty list says so');
        self::assertSame(self::ignoredWords(1), self::ignoredLine($onlyIgnored)['text'] ?? null, 'and the line says where it is');
        self::assertSame([$parent], self::storeRows(self::open($web, (string) self::ignoredLine($onlyIgnored)['href'])));
        // Its exact store ID does not bring it back to All either: the line leads to it.
        $exact = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'N10']);
        self::assertSame([], self::storeRows($exact));
        self::assertSame(self::ignoredWords(1), self::ignoredLine($exact)['text'] ?? null);
        self::assertSame([$nic10], self::storeRows(self::open($web, (string) self::ignoredLine($exact)['href'])));
        // A search that leaves nothing out says nothing.
        $plain = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'single shot']);
        self::assertSame([$single], self::storeRows($plain));
        self::assertNull(self::ignoredLine($plain));
    }

    /**
     * HU1-HU4 next to it: an old product not sold in a year is hidden as before, with its own line; an ignored product is never counted as
     * one of those (its state is Ignored, not waiting for a match); "Show them" (`unsold=1`) brings the old ones back and still leaves the
     * ignored ones out, and the way to them keeps it; the setting off changes nothing for the ignored ones.
     */
    public function testTheOldUnsoldProductsAreStillHiddenNextToItEachWithItsOwnLine(): void
    {
        $site = $this->site('electrofag', 'shadow');
        $oldUnsold = $this->seen($this->profiled($site, 'U1', ['product_title' => 'Old unsold kit']), 400);
        $oldIgnored = $this->seen($this->ignored($site, 'I1', ['product_title' => 'Old ignored kit']), 400);
        $ignored = $this->ignored($site, 'I2', ['product_title' => 'New ignored kit']);
        $new = $this->profiled($site, 'P1', ['product_title' => 'New kit']);
        $web = $this->signIn($this->uiUser('viewer'));

        $page = $web->get(self::STORE, ['channel' => 'electrofag']);
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame([$new], self::storeRows($page));
        $pills = self::pills($page);
        self::assertSame([1, 2, 1], [$pills[Words::STORE_STATE['all']], $pills[Words::STORE_STATE['ignored']], $pills[Words::STORE_STATE['not_matched']]],
            'All leaves out both; Ignored counts both of its own');
        self::assertSame(self::unsoldWords(1), self::unsoldLine($page)['text'] ?? null, 'the line of the old ones, as before');
        self::assertSame(['channel' => 'electrofag', 'unsold' => '1'], self::queryOf((string) self::unsoldLine($page)['href']));
        self::assertSame(self::ignoredWords(2), self::ignoredLine($page)['text'] ?? null, 'and its own line for the ignored ones');
        self::assertSame(['channel' => 'electrofag', 'state' => 'ignored'], self::queryOf((string) self::ignoredLine($page)['href']));
        $lines = array_map(static fn (\DOMElement $p): string => $p->hasAttribute('data-unsold') ? 'unsold' : 'ignored',
            iterator_to_array(self::xp($page)->query('//main//p[@data-unsold or @data-ignored]')));
        self::assertSame(['unsold', 'ignored'], $lines, 'two quiet lines, each saying one thing');

        // "Show them": the old one is back; the ignored ones are still left out, and the way to them keeps "Show them".
        $shown = self::open($web, (string) self::unsoldLine($page)['href']);
        self::assertSame([$oldUnsold, $new], self::storeRows($shown));
        self::assertSame(2, self::pills($shown)[Words::STORE_STATE['all']]);
        self::assertSame(Words::STORE_PRODUCTS['unsold_shown'] . ' ' . Words::STORE_PRODUCTS['unsold_hide'], self::unsoldLine($shown)['text'] ?? null);
        self::assertSame(self::ignoredWords(2), self::ignoredLine($shown)['text'] ?? null);
        self::assertSame(['channel' => 'electrofag', 'state' => 'ignored', 'unsold' => '1'], self::queryOf((string) self::ignoredLine($shown)['href']));
        self::assertSame([$oldIgnored, $ignored], self::storeRows(self::open($web, (string) self::ignoredLine($shown)['href'])), 'an old ignored product is listed under Ignored');

        // A search: each line counts what the search left out.
        $found = $web->get(self::STORE, ['channel' => 'electrofag', 'q' => 'kit']);
        self::assertSame([$new], self::storeRows($found));
        self::assertSame(self::squash(Words::say('FIND', 'found_one', Words::quoted('kit', ''))), self::found($found));
        self::assertSame(self::unsoldWords(1), self::unsoldLine($found)['text'] ?? null);
        self::assertSame(['channel' => 'electrofag', 'q' => 'kit', 'unsold' => '1'], self::queryOf((string) self::unsoldLine($found)['href']));
        self::assertSame(self::ignoredWords(2), self::ignoredLine($found)['text'] ?? null);
        self::assertSame(['channel' => 'electrofag', 'state' => 'ignored', 'q' => 'kit'], self::queryOf((string) self::ignoredLine($found)['href']));
        $foundShown = $web->get(self::STORE, ['channel' => 'electrofag', 'q' => 'kit', 'unsold' => '1']);
        self::assertSame([$oldUnsold, $new], self::storeRows($foundShown));
        self::assertSame(self::ignoredWords(2), self::ignoredLine($foundShown)['text'] ?? null);

        // The setting off: nothing old is hidden, the ignored ones still are.
        self::setting(0);
        $off = $web->get(self::STORE, ['channel' => 'electrofag']);
        self::assertSame([$oldUnsold, $new], self::storeRows($off));
        self::assertNull(self::unsoldLine($off));
        self::assertSame(self::ignoredWords(2), self::ignoredLine($off)['text'] ?? null);
        self::assertSame(2, self::pills($off)[Words::STORE_STATE['all']]);
    }

    /** All's pages are counted without them: 60 to list and 45 ignored best sellers are two pages of All (not three), every row one to list. */
    public function testAllsPagesAreCountedWithoutThem(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $ids = [];
        for ($i = 1; $i <= 60; $i++) {
            $ids[] = $this->profiled($site, sprintf('V%03d', $i), ['product_title' => sprintf('Pager product %03d', $i)]);
        }
        $ign = [];
        for ($i = 1; $i <= 45; $i++) {
            $ign[] = $this->ignored($site, sprintf('I%03d', $i), ['product_title' => sprintf('Pager product ignored %03d', $i), 'units_30d' => 100]);
        }
        $web = $this->signIn($this->uiUser('viewer'));

        $p1 = $web->get(self::STORE, ['channel' => 'vapebig']);
        self::assertSame(200, $p1->status, $p1->describe() . implode("\n", self::$log));
        self::assertSame(array_slice($ids, 0, 50), self::orderedRows($p1), 'page 1: fifty to list, none ignored (they sold most)');
        self::assertSame(60, self::pills($p1)[Words::STORE_STATE['all']]);
        self::assertSame(Words::say('QUEUE', 'page', 1, 2), self::pagerText($p1), 'two pages, not three');
        self::assertSame(self::ignoredWords(45), self::ignoredLine($p1)['text'] ?? null);
        $p2 = self::open($web, (string) self::pagerLink($p1, 'next'));
        self::assertSame(array_slice($ids, 50), self::orderedRows($p2), 'page 2: the last ten');
        self::assertNull(self::pagerLink($p2, 'next'));
        self::assertSame(Words::say('QUEUE', 'page', 2, 2), self::pagerText($p2));
        self::assertSame(self::ignoredWords(45), self::ignoredLine($p2)['text'] ?? null, 'the line is on every page');
        $far = $web->get(self::STORE, ['channel' => 'vapebig', 'page' => '3']);
        self::assertSame(array_slice($ids, 50), self::orderedRows($far), 'a page past the end is the last one');

        // A search: the same pages.
        $s1 = $web->get(self::STORE, ['channel' => 'vapebig', 'q' => 'pager product']);
        self::assertSame(array_slice($ids, 0, 50), self::orderedRows($s1));
        self::assertSame(self::squash(Words::say('FIND', 'found_many', 60, Words::quoted('pager product', ''))), self::found($s1), 'the search counts what it lists');
        self::assertSame(Words::say('QUEUE', 'page', 1, 2), self::pagerText($s1));
        self::assertSame(['channel' => 'vapebig', 'q' => 'pager product', 'page' => '2'], self::queryOf((string) self::pagerLink($s1, 'next')));
        self::assertSame(array_slice($ids, 50), self::orderedRows(self::open($web, (string) self::pagerLink($s1, 'next'))));
        self::assertSame(['channel' => 'vapebig', 'state' => 'ignored', 'q' => 'pager product'], self::queryOf((string) self::ignoredLine($s1)['href']), 'its line goes to page 1 of Ignored');

        // The Ignored choice: its own 45, one page.
        $list = $web->get(self::STORE, ['channel' => 'vapebig', 'state' => 'ignored']);
        self::assertSame($ign, self::orderedRows($list));
        self::assertNull(self::pagerText($list));
    }

    /**
     * StoreContext (CM3): a row's Open from the Ignored choice leads back to the Ignored choice with its search, and from All to All; a bulk
     * action leads back to the list it was done on. Taking one off the ignored list puts it on All again; ignoring one takes it off All and
     * the line counts it.
     */
    public function testOpenAndBulkActionsLeadBackToTheListTheyCameFrom(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $nic = $this->ignored($site, 'N10', ['product_title' => 'Nic Nic 10 pack 10mg', 'units_30d' => 2]);
        $nic2 = $this->ignored($site, 'N20', ['product_title' => 'Nic Nic 10 pack 20mg', 'units_30d' => 1]);
        $single = $this->profiled($site, 'S18', ['product_title' => 'Nic Nic single shot 18mg']);
        $web = $this->signIn($this->uiUser('mapper'));

        $list = $web->get(self::STORE, ['channel' => 'vapeandgo', 'state' => 'ignored', 'q' => 'nic nic']);
        self::assertSame(200, $list->status, $list->describe() . implode("\n", self::$log));
        self::assertSame([$nic, $nic2], self::storeRows($list));
        $open = self::rowLink($list, $nic);
        self::assertSame(['/ui/review/listing/' . $nic, ['via' => 'store', 'channel' => 'vapeandgo', 'state' => 'ignored', 'lq' => 'nic nic']],
            [parse_url($open, PHP_URL_PATH), self::queryOf($open)], 'Open names the Ignored choice and the search');
        $variant = self::open($web, $open);
        self::assertSame(200, $variant->status, $variant->describe());
        $back = self::backLink($variant);
        self::assertSame([self::STORE, ['channel' => 'vapeandgo', 'state' => 'ignored', 'q' => 'nic nic'], 'l-' . $nic],
            [parse_url($back, PHP_URL_PATH), self::queryOf($back), parse_url($back, PHP_URL_FRAGMENT)], 'its way back is the Ignored choice, at the row');
        self::assertSame([$nic, $nic2], self::storeRows(self::open($web, $back)));

        $all = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'nic nic']);
        self::assertSame([$single], self::storeRows($all));
        $openAll = self::rowLink($all, $single);
        self::assertSame(['via' => 'store', 'channel' => 'vapeandgo', 'lq' => 'nic nic'], self::queryOf($openAll), 'from All: no state');
        $backAll = self::backLink(self::open($web, $openAll));
        self::assertSame(['channel' => 'vapeandgo', 'q' => 'nic nic'], self::queryOf($backAll), 'back to All with the search');

        // Taken off the ignored list from the Ignored choice: back to it, with the search; the product is on All again.
        $r = $web->post('/ui/review/bulk', self::ticked($list, [$nic], 'unignore'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('unmapped', $this->listingStatus($nic));
        $done = $web->follow($r);
        self::assertSame(['channel' => 'vapeandgo', 'state' => 'ignored', 'q' => 'nic nic'], self::queryOf(self::backLink($done)), 'the bulk result leads back to the Ignored choice');
        self::assertSame([$nic2], self::storeRows(self::open($web, self::backLink($done))));
        $again = $web->get(self::STORE, ['channel' => 'vapeandgo', 'q' => 'nic nic']);
        self::assertSame([$nic, $single], self::storeRows($again), 'back on All');
        self::assertSame(self::ignoredWords(1), self::ignoredLine($again)['text'] ?? null);

        // Ignored from All: it leaves All, and the line counts it.
        $page = $web->get(self::STORE, ['channel' => 'vapeandgo']);
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$single], 'ignore'));
        self::assertSame(200, $step->status, $step->describe());
        $ok = $web->post('/ui/review/bulk', ['reason' => 'Sold as single shots'] + $step->form('/ui/review/bulk'));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('ignored', $this->listingStatus($single));
        $back = self::backLink($web->follow($ok));
        self::assertSame(['channel' => 'vapeandgo'], self::queryOf($back), 'back to All');
        $after = self::open($web, $back);
        self::assertSame([$nic], self::storeRows($after));
        self::assertSame(self::ignoredWords(2), self::ignoredLine($after)['text'] ?? null);
    }

    /**
     * Speed (HI3): All of a store of thousands is read from the store's (channel_id, status) index (the index of 0051 begins with the same two
     * columns), the listing rows never read ("Using index"); a waiting decision is looked up by its unique key; All's total is the three
     * narrow counts (no COUNT of its own).
     */
    public function testAllStaysOnTheStoresStatusIndex(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $sku = $this->item('legacy', 0, 'Plan kit');
        $ch = (int) $site->channelId;
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 '
            . 'UNION ALL SELECT 8 UNION ALL SELECT 9)';
        self::$db->exec('INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status, created_at) '
            . "SELECT ?, CONCAT('P', n), IF(n % 100 <> 7 AND n % 10 < 3, ?, NULL), 1, "
            . "CASE WHEN n % 100 = 7 THEN 'ignored' WHEN n % 10 < 3 THEN 'mapped' WHEN n % 10 = 3 THEN 'suggested' ELSE 'unmapped' END, "
            . 'IF(n % 2 = 0, UTC_TIMESTAMP(6) - INTERVAL 400 DAY, UTC_TIMESTAMP(6)) '
            . "FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d AS n FROM {$digits} a, {$digits} b, {$digits} c, {$digits} e) t WHERE n < 5000",
            [$ch, $sku]);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, units_30d, units_365d, barcodes, features, features_version) '
            . "SELECT id, CONCAT('Plan product ', external_variant_id), id % 7, IF(id % 3 = 0, 0, id % 13), JSON_ARRAY(), JSON_OBJECT(), 'n2.0' FROM channel_listing WHERE channel_id = ?",
            [$ch]);
        self::$db->exec('ANALYZE TABLE channel_listing, listing_profile, match_decision');
        $before = (string) (new Queries(self::$db))->unsoldBefore($ch);
        $web = $this->signIn($this->uiUser('viewer'));
        foreach (['unsold hidden' => [], 'unsold shown' => ['unsold' => '1']] as $name => $extra) {
            $seen = [];
            self::$appDb->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen): void {
                $seen[] = (string) preg_replace('/\s+/', ' ', $sql);
            }]]);
            try {
                $page = $web->get(self::STORE, ['channel' => 'vapeandgo'] + $extra);
            } finally {
                self::$appDb->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
            }
            self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
            self::assertSame(50, count(self::orderedRows($page)), $name);
            self::assertSame(self::ignoredWords(50), self::ignoredLine($page)['text'] ?? null, $name);
            $lists = array_values(array_filter($seen, static fn (string $s): bool => str_contains($s, 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE')));
            self::assertCount(1, $lists, $name . ': one statement reads the page\'s ids');
            self::assertSame([], array_values(array_filter($seen, static fn (string $s): bool => str_contains($s, 'SELECT COUNT(*) FROM channel_listing cl WHERE'))),
                $name . ': All\'s total is the narrow counts, never a count of its own');
            $sql = $lists[0];
            self::assertStringContainsString("cl.status <> 'ignored'", $sql, $name . ': All leaves Ignored out in the statement');
            $n = substr_count($sql, '?');
            $plan = self::$appDb->all('EXPLAIN ' . $sql, [$ch, ...array_fill(0, $n - 3, $before), 50, 0]);
            $by = array_column($plan, null, 'table');
            self::assertContains($by['cl']['key'] ?? null, ['ix_channel_listing_status', 'ix_channel_listing_unsold'], $name . ': ' . json_encode($plan, JSON_THROW_ON_ERROR));
            self::assertContains($by['cl']['type'], ['ref', 'range'], $name);
            self::assertStringContainsString('Using index', (string) $by['cl']['Extra'], $name . ': the listing rows are not read');
            self::assertSame('ix_listing_profile_units', $by['lp']['key'] ?? null, $name . ': ' . json_encode($plan, JSON_THROW_ON_ERROR));
            self::assertSame('uq_match_decision_pending', $by['pw']['key'] ?? null, $name . ': the waiting decision by its unique key: ' . json_encode($plan, JSON_THROW_ON_ERROR));
        }
    }
}
