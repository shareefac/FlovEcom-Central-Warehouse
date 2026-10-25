<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * Store Products' Previous and Next (the bug the owner hit, 25 Oct 2026; docs/decisions.md SM8): the links were built as
 * `($base + ['page' => null]) + ['page' => N]`, and PHP's `+` keeps the left side's key, so every link lost its page number: Next on
 * page 1 and on page 2 both opened page 1. Through the real /ui kernel: 120 products of one store are three pages of 50.
 */
final class StoreProductsPagerTest extends KernelUiTestCase
{
    /** @return list<int> the listings of the list's rows, in order */
    private static function rows(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "store-products")]/tbody/tr[@id]')));
    }

    private static function pagerLink(UiResponse $r, string $rel): ?string
    {
        $a = (new \DOMXPath($r->dom()))->query('//nav[contains(@class, "pager")]//a[@rel="' . $rel . '"]')->item(0);
        return $a instanceof \DOMElement ? $a->getAttribute('href') : null;
    }

    /** @return array<string, string> */
    private static function queryOf(?string $href): array
    {
        parse_str((string) parse_url((string) $href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $query;
    }

    private static function open(KernelBrowser $web, ?string $href): UiResponse
    {
        self::assertNotNull($href, 'the link is there');
        return $web->get((string) parse_url($href, PHP_URL_PATH), self::queryOf($href));
    }

    public function testNextAndPreviousCarryThePageNumber(): void
    {
        $site = $this->site('vapebig', 'shadow');
        $ids = [];
        for ($i = 1; $i <= 120; $i++) {
            $ids[] = $this->profiled($site, sprintf('V%03d', $i), ['product_title' => sprintf('Pager product %03d', $i)]);
        }
        $web = $this->signIn($this->uiUser('viewer'));
        $p1 = $web->get('/ui/review/store', ['channel' => 'vapebig']);
        self::assertSame(200, $p1->status, $p1->describe() . implode("\n", self::$log));
        self::assertSame(array_slice($ids, 0, 50), self::rows($p1));
        self::assertNull(self::pagerLink($p1, 'prev'));
        self::assertSame(['channel' => 'vapebig', 'page' => '2'], self::queryOf(self::pagerLink($p1, 'next')), 'Next on page 1 is page 2');

        $p2 = self::open($web, self::pagerLink($p1, 'next'));
        self::assertSame(array_slice($ids, 50, 50), self::rows($p2));
        self::assertSame(['channel' => 'vapebig', 'page' => '3'], self::queryOf(self::pagerLink($p2, 'next')), 'Next on page 2 is page 3');
        self::assertSame(array_slice($ids, 0, 50), self::rows(self::open($web, self::pagerLink($p2, 'prev'))), 'Previous on page 2 is page 1');

        $p3 = self::open($web, self::pagerLink($p2, 'next'));
        self::assertSame(array_slice($ids, 100), self::rows($p3));
        self::assertNull(self::pagerLink($p3, 'next'));
        self::assertSame(['channel' => 'vapebig', 'page' => '2'], self::queryOf(self::pagerLink($p3, 'prev')), 'Previous on page 3 is page 2');

        // The state, the sort and the search travel with the page number.
        $found = $web->get('/ui/review/store', ['channel' => 'vapebig', 'state' => 'not_matched', 'sort' => 'sold_365', 'q' => 'pager product']);
        self::assertSame(['channel' => 'vapebig', 'state' => 'not_matched', 'sort' => 'sold_365', 'q' => 'pager product', 'page' => '2'],
            self::queryOf(self::pagerLink($found, 'next')));
        self::assertSame(array_slice($ids, 50, 50), self::rows(self::open($web, self::pagerLink($found, 'next'))));
    }
}
