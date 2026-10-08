<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\StockViews;
use CW\Ui\Words;

/**
 * Stock › Overview and Stock › Movements (the owner's request of 8 Oct 2026, first version) through the real /ui kernel as cw_app:
 * read only, for everyone who sees a product's stock (catalogue.view); each warehouse's totals and the four figures per product,
 * filtered by warehouse, product and "with stock"; the ledger newest first, by warehouse, product, kind of change and figure, paged by
 * the ledger id. The tabs not built yet (Adjustments, Counts, Transfers) are drawn without a link.
 */
final class StockScreensTest extends KernelUiTestCase
{
    /** @return list<string> the product names of a page's boards, in order */
    private static function names(UiResponse $r, string $table): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query("//table[contains(@class, \"{$table}\")]/tbody/tr/th/a")));
    }

    /** @return list<string> the group titles of a page's boards, in order */
    private static function groupTitles(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//h3[contains(@class, "grp-title")]/button')));
    }

    public function testTheOverviewShowsEveryProductWithItsStockInEachWarehouse(): void
    {
        $a = $this->item('legacy', 12, 'Alpha liquid 10ml');
        $b = $this->item('legacy', 5, 'Beta pod 2ml');
        $this->book('goods_in', $b, 2, 'VERIFY');
        $c = $this->item('legacy', 3, 'Gamma kit');
        $this->book('adjustment', $c, -3);
        $d = $this->item('legacy', 0, 'Delta coil');
        $this->book('goods_in', $d, 4, 'VERIFY');
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get('/ui/stock');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Stock', 'Overview'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame([['Overview', '/ui/stock'], ['Movements', '/ui/stock/movements'], ['Adjustments', null], ['Counts', null], ['Transfers', null]],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($page)), 'the tabs not built yet are "Soon", never links');
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::MENU['stock'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertStringStartsWith(Words::PAGE_INTRO['stock'][0], trim((string) $xp->evaluate('string(//main//p[@class="lede"])')));
        // The four figures: products, in stock (a sellable warehouse), units everywhere, with no stock.
        self::assertSame(['4', '2', '23', '2'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//main//li[@class="kpi"]/p[@class="kpi-value"]'))));
        // One row per product; nothing to sell first (Delta: only in a warehouse the websites do not sell from), then by name.
        self::assertSame([Words::STOCK_VIEW['group_out'], Words::STOCK_VIEW['group_in']], self::groupTitles($page));
        self::assertSame(['Delta coil', 'Alpha liquid 10ml', 'Beta pod 2ml'], self::names($page, 'stock-lines'), 'Gamma (nothing left anywhere) is left out');
        $beta = trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//table[contains(@class, "stock-lines")]/tbody/tr[th/a[. = "Beta pod 2ml"]])')));
        self::assertStringContainsString(Words::STOCK_VIEW['group_in'] . ' 5 2 0 0 5', $beta, 'Main warehouse 5, the check room 2, unstamped 0, reserved 0, available 5');
        self::assertSame('/ui/items/' . $a, $xp->query('//table[contains(@class, "stock-lines")]//th/a[. = "Alpha liquid 10ml"]')->item(0)?->getAttribute('href'));
        self::assertGreaterThan(0, $xp->query('//table[contains(@class, "stock-lines") and contains(@class, "stack")]//td[@data-label="' . Words::STOCK['available'] . '"]')->length,
            'one card per product on a phone');

        // Filters: a warehouse, a product (words or a CW number), and what to show.
        $verify = (int) self::$db->value("SELECT id FROM warehouse WHERE code = 'VERIFY'");
        self::assertSame(['Delta coil', 'Beta pod 2ml'], self::names($web->get('/ui/stock', ['warehouse' => (string) $verify]), 'stock-lines'));
        self::assertSame(['Beta pod 2ml'], self::names($web->get('/ui/stock', ['q' => 'pod beta']), 'stock-lines'));
        self::assertSame(['Alpha liquid 10ml'], self::names($web->get('/ui/stock', ['q' => sprintf('CW-%06d', $a)]), 'stock-lines'));
        $none = $web->get('/ui/stock', ['q' => 'nothing like this']);
        self::assertStringContainsString(Words::STOCK_VIEW['none_filtered'], $none->text());
        self::assertContains('/ui/stock', $none->hrefs(), 'Clear the filters');
        self::assertSame(['Delta coil', 'Gamma kit', 'Alpha liquid 10ml', 'Beta pod 2ml'], self::names($web->get('/ui/stock', ['show' => 'all']), 'stock-lines'),
            '"Every product" shows the ones with nothing at all too');
        self::assertSame([], self::names($web->get('/ui/stock', ['show' => 'negative']), 'stock-lines'));
        // Nonsense in the address is ignored, never an error.
        self::assertSame(200, $web->get('/ui/stock', ['warehouse' => '999', 'show' => 'x', 'page' => '-1'])->status);
    }

    public function testTheMovementsAreTheLedgerNewestFirstByDay(): void
    {
        $a = $this->item('legacy', 12, 'Alpha liquid 10ml');
        $b = $this->item('legacy', 5, 'Beta pod 2ml');
        $this->book('adjustment', $a, -2);
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/stock/movements');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Stock', 'Movements'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame(['Alpha liquid 10ml', 'Beta pod 2ml', 'Alpha liquid 10ml'], self::names($page, 'ledger'), 'newest first');
        self::assertNotSame([], self::groupTitles($page), 'grouped by day');
        $first = trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($page->dom()))->evaluate('string(//table[contains(@class, "ledger")]/tbody/tr[1])')));
        self::assertStringContainsString(Words::MOVEMENT['adjustment'], $first);
        self::assertStringContainsString('-2 10', $first, 'the change and the figure after it');
        self::assertSame(['Alpha liquid 10ml'], self::names($web->get('/ui/stock/movements', ['type' => 'adjustment']), 'ledger'));
        self::assertSame(['Beta pod 2ml'], self::names($web->get('/ui/stock/movements', ['q' => 'beta']), 'ledger'));
        $none = $web->get('/ui/stock/movements', ['type' => 'write_off']);
        self::assertStringContainsString(Words::STOCK_VIEW['none_filtered'], $none->text());
        // Older pages by the ledger id: never an OFFSET over the whole ledger.
        $newest = (int) self::$db->value("SELECT MAX(id) FROM stock_ledger WHERE bucket = 'on_hand'");
        self::assertSame(['Beta pod 2ml', 'Alpha liquid 10ml'], self::names($web->get('/ui/stock/movements', ['before' => (string) $newest]), 'ledger'));
        for ($i = 0; $i < StockViews::MOVES_PAGE; $i++) {
            $this->book('adjustment', $b, 1);
        }
        $full = $web->get('/ui/stock/movements');
        self::assertCount(StockViews::MOVES_PAGE, self::names($full, 'ledger'));
        $older = array_values(array_filter($full->hrefs(), static fn (string $h): bool => str_contains($h, 'before=')));
        self::assertCount(1, $older, 'a link to the older changes');
        parse_str((string) parse_url($older[0], PHP_URL_QUERY), $q);
        self::assertSame(['Alpha liquid 10ml', 'Beta pod 2ml', 'Alpha liquid 10ml'],
            self::names($web->get('/ui/stock/movements', array_map('strval', $q)), 'ledger'), 'the older changes');
        self::assertSame(200, $web->get('/ui/stock/movements', ['before' => 'x', 'figure' => 'all', 'type' => 'nope'])->status);
    }

    public function testEveryoneWhoSeesAProductsStockSeesTheStockPages(): void
    {
        foreach (['viewer', 'buyer', 'goods_in', 'admin', 'accountant'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            self::assertSame(200, $web->get('/ui/stock')->status, $role);
            self::assertSame(200, $web->get('/ui/stock/movements')->status, $role);
            self::assertArrayHasKey('Stock', self::nav($web->get('/ui/')), $role);
        }
        self::assertSame(303, $this->browser()->get('/ui/stock')->status, 'signed out: to the sign-in page');
    }
}
