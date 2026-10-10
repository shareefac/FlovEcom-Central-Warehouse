<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\ByProductContext;
use PHPUnit\Framework\TestCase;

/**
 * "Opened from Products › By Item" (Ui\ByProductContext, docs/decisions.md U119): what a request must say to be one, which of its
 * values are kept (a store that is one, a filter that is one, texts cut to the search box's length, the picker's page as a number
 * above 1), and the only three places its links lead: By Item's list, the picker of that item on that store, and nothing else.
 * Crafted values are dropped or stay query values. Pure: no database.
 */
final class ByProductContextTest extends TestCase
{
    private const STORES = [['id' => 3, 'code' => 'alt', 'name' => 'Alt Store'], ['id' => 9, 'code' => 'vbig', 'name' => 'Big Store']];
    private const FROM = ['via' => 'product', 'product' => '41', 'channel' => 'alt'];

    public function testItIsNamedByATokenAnItemsNumberAndAStoreThatIsOne(): void
    {
        $c = ByProductContext::from(self::FROM, self::STORES);
        self::assertNotNull($c);
        self::assertSame([41, 'alt', 3, 'Alt Store', null, '', null, null], [$c->skuId, $c->channel, $c->channelId, $c->channelName, $c->coverage, $c->text, $c->pickerText, $c->pickerPage]);
        self::assertTrue(ByProductContext::named(self::FROM));
        foreach ([
            [], ['product' => '41', 'channel' => 'alt'], ['via' => 'products'] + self::FROM, ['via' => ['product']] + self::FROM, ['via' => 'PRODUCT'] + self::FROM,
            ['product' => '0'] + self::FROM, ['product' => '-4'] + self::FROM, ['product' => '4.0'] + self::FROM, ['product' => ' 41'] + self::FROM, ['product' => "41\n"] + self::FROM,
            ['product' => '//evil.example'] + self::FROM, ['product' => ['41']] + self::FROM, ['product' => str_repeat('9', 19)] + self::FROM, ['product' => 41] + self::FROM,
            ['channel' => 'nosuch'] + self::FROM, ['channel' => 'ALT'] + self::FROM, ['channel' => ''] + self::FROM, ['channel' => ['alt']] + self::FROM,
            ['channel' => 'https://evil.example'] + self::FROM, ['channel' => "alt' OR 1=1"] + self::FROM, ['via' => 'product', 'product' => '41'], ['via' => 'product', 'channel' => 'alt'],
        ] as $src) {
            self::assertNull(ByProductContext::from($src, self::STORES), json_encode($src));
        }
        self::assertNull(ByProductContext::from(self::FROM, []), 'no store at all');
        self::assertFalse(ByProductContext::named(['product' => '41', 'channel' => 'alt']));
    }

    public function testTheFilterTheTextsAndThePickersPageAreKeptOnlyWhenTheyAreOne(): void
    {
        $from = static fn (array $more): ByProductContext => ByProductContext::from($more + self::FROM, self::STORES) ?? throw new \LogicException('not a context');
        // The list's filter: one of the page's, or none.
        self::assertSame(['every', 'suggested', 'missing-vbig', null, null, null, null, null], array_map(static fn (mixed $v): ?string => $from(['cov' => $v])->coverage,
            ['every', 'suggested', 'missing-vbig', 'missing-nosuch', 'missing-', 'https://evil.example', ['every'], 'EVERY']));
        // The list's search: trimmed, cut to the box's length, a list is none.
        self::assertSame(['elux', '', '', str_repeat('é', 100), "//evil.example\r\nX: y"], array_map(static fn (mixed $v): string => $from(['lq' => $v])->text,
            ['  elux ', '   ', ['x'], str_repeat('é', 500), "//evil.example\r\nX: y"]));
        // The picker's own search: null when the request has none (the picker's first search), '' when the person emptied it.
        self::assertSame([null, null, 'pager item', '', '', '', str_repeat('é', 100), '//evil.example'], array_map(
            static fn (mixed $v): ?string => $from($v === null ? [] : ['pq' => $v])->pickerText,
            [null, ['a'], ' pager item ', ' ', '', "\t\n", str_repeat('é', 500), '//evil.example'],
        ));
        // The picker's page: a number above 1.
        self::assertSame([2, 200, 999999999999999999, null, null, null, null, null, null, null, null, null], array_map(
            static fn (mixed $v): ?int => $from(['pp' => $v])->pickerPage,
            ['2', '200', str_repeat('9', 18), '1', '0', '-2', '2.0', ' 2', 'x', ['2'], str_repeat('9', 19), 'https://evil.example'],
        ));
        self::assertSame(ByProductContext::MAX_TEXT, mb_strlen(ByProductContext::text(str_repeat('é', 500))));
        self::assertSame('a b', ByProductContext::text("  a b \n"));
    }

    public function testItsFieldsCarryThePickersSearchAndPageOnlyWhenThePersonHadThem(): void
    {
        $plain = ByProductContext::from(self::FROM, self::STORES);
        self::assertNotNull($plain);
        self::assertSame(['via' => 'product', 'product' => 41, 'channel' => 'alt', 'cov' => null, 'lq' => null], $plain->query(), 'neither `pq` nor `pp`');
        $full = ByProductContext::from(['cov' => 'missing-alt', 'lq' => 'elux', 'pq' => 'pager item', 'pp' => '3'] + self::FROM, self::STORES);
        self::assertNotNull($full);
        self::assertSame(['via' => 'product', 'product' => 41, 'channel' => 'alt', 'cov' => 'missing-alt', 'lq' => 'elux', 'pq' => 'pager item', 'pp' => 3], $full->query());
        // An emptied search travels as one space (an empty value is left out of a link and of a form), and is read back as empty.
        $emptied = $plain->withPicker('', 2);
        self::assertSame(['', 2], [$emptied->pickerText, $emptied->pickerPage]);
        self::assertSame(' ', $emptied->query()['pq']);
        $back = ByProductContext::from(array_map('strval', array_filter($emptied->query(), static fn (mixed $v): bool => $v !== null)), self::STORES);
        self::assertEquals($emptied, $back, 'what its fields say is the same context again');
        self::assertEquals($full, ByProductContext::from(array_map('strval', $full->query()), self::STORES));
        // withPicker(): the first page is no page, no search is no search, a long text is cut.
        self::assertSame([null, null], [$full->withPicker(null, 1)->pickerText, $full->withPicker(null, 1)->pickerPage]);
        self::assertSame(['zz', null], [$plain->withPicker(' zz ', 0)->pickerText, $plain->withPicker(' zz ', -3)->pickerPage]);
        self::assertSame(100, mb_strlen((string) $plain->withPicker(str_repeat('é', 500), 1)->pickerText));
        self::assertSame(['missing-alt', 'elux', 41, 'alt', 3, 'Alt Store'], [$full->withPicker('x', 9)->coverage, $full->withPicker('x', 9)->text, $full->withPicker('x', 9)->skuId,
            $full->withPicker('x', 9)->channel, $full->withPicker('x', 9)->channelId, $full->withPicker('x', 9)->channelName], 'the rest stays');
    }

    public function testTheListsAddressNeverHoldsThePickersSearchOrPage(): void
    {
        $c = ByProductContext::from(['cov' => 'missing-alt', 'lq' => 'elux', 'pq' => 'pager item', 'pp' => '3'] + self::FROM, self::STORES);
        self::assertNotNull($c);
        self::assertSame('/ui/review/products?cov=missing-alt&q=elux&at=41#p-41', $c->listUrl());
        self::assertSame('/ui/review/products?cov=missing-alt&q=elux&at=41&notice=decided_link&prev=7#p-41', $c->listUrl(['notice' => 'decided_link', 'prev' => 7]));
        self::assertSame('/ui/review/products?at=41#p-41', ByProductContext::from(['pq' => ' ', 'pp' => '2'] + self::FROM, self::STORES)?->listUrl());
        // A hostile text is only ever a query value of By Item.
        $evil = ByProductContext::from(['lq' => "//evil.example\r\nX: y", 'pq' => 'https://evil.example/?a=b&c=d#e', 'cov' => '//evil.example'] + self::FROM, self::STORES);
        self::assertNotNull($evil);
        self::assertSame('/ui/review/products?q=%2F%2Fevil.example%0D%0AX%3A%20y&at=41#p-41', $evil->listUrl());
        self::assertSame('/ui/review/products/41/map?channel=alt&lq=%2F%2Fevil.example%0D%0AX%3A%20y&q=https%3A%2F%2Fevil.example%2F%3Fa%3Db%26c%3Dd%23e', $evil->pickerUrl());
    }

    public function testThePickersAddressIsThePickerAsThePersonLeftIt(): void
    {
        $from = static fn (array $more): ByProductContext => ByProductContext::from($more + self::FROM, self::STORES) ?? throw new \LogicException('not a context');
        self::assertSame('/ui/review/products/41/map?channel=alt', $from([])->pickerUrl(), 'the first search, the first page');
        self::assertSame('/ui/review/products/41/map?channel=alt&q=pager%20item&page=2', $from(['pq' => 'pager item', 'pp' => '2'])->pickerUrl());
        self::assertSame('/ui/review/products/41/map?channel=alt&q=pager%20item', $from(['pq' => 'pager item', 'pp' => '1'])->pickerUrl());
        self::assertSame('/ui/review/products/41/map?channel=alt&cov=missing-alt&lq=elux&q=&page=2', $from(['cov' => 'missing-alt', 'lq' => 'elux', 'pq' => ' ', 'pp' => '2'])->pickerUrl(),
            'an emptied search is said (`q=`): without it the picker would run its first search');
        self::assertSame('/ui/review/products/41/map?channel=alt&q=', $from(['pq' => ' '])->pickerUrl());
        self::assertSame('/ui/review/products/41/map?channel=alt&page=4', $from(['pp' => '4'])->pickerUrl(), 'the first search, a later page');
        self::assertSame('/ui/review/products/41/map?channel=alt&q=a%26b%3Dc%20%2B%20d%23e%3F', $from(['pq' => 'a&b=c + d#e?'])->pickerUrl(), 'a text is one value, whatever it holds');
        // The picker's Previous / Next: another page of the same search.
        $pager = $from(['cov' => 'every'])->withPicker('', 1);
        self::assertSame(['/ui/review/products/41/map?channel=alt&cov=every&q=', '/ui/review/products/41/map?channel=alt&cov=every&q=&page=2',
            '/ui/review/products/41/map?channel=alt&cov=every&q=', '/ui/review/products/41/map?channel=alt&cov=every&q='],
            [$pager->pickerUrl(), $pager->pickerUrl(2), $pager->pickerUrl(1), $pager->pickerUrl(0)]);
        self::assertSame('/ui/review/products/41/map?channel=alt&q=pager%20item&page=3', $from(['pq' => 'pager item', 'pp' => '9'])->pickerUrl(3));
    }

    public function testACoverageFilterIsEveryStoreSuggestedOrMissingOnAStoreThatIsOne(): void
    {
        self::assertSame(['kind' => 'every', 'store' => null, 'value' => 'every', 'unknown' => false], ByProductContext::coverage('every', self::STORES));
        self::assertSame(['kind' => 'suggested', 'store' => null, 'value' => 'suggested', 'unknown' => false], ByProductContext::coverage('suggested', self::STORES));
        self::assertSame(['kind' => 'missing', 'store' => self::STORES[1], 'value' => 'missing-vbig', 'unknown' => false], ByProductContext::coverage('missing-vbig', self::STORES));
        foreach ([null, '', 'nonsense', 'EVERY', 'missing', '//evil.example'] as $v) {
            self::assertSame(['kind' => null, 'store' => null, 'value' => null, 'unknown' => false], ByProductContext::coverage($v, self::STORES), json_encode($v));
        }
        foreach (['missing-nosuch', 'missing-', 'missing-ALT', 'missing-//evil.example'] as $v) {
            $c = ByProductContext::coverage($v, self::STORES);
            self::assertSame([null, null, true], [$c['kind'], $c['value'], $c['unknown']], $v);
        }
        self::assertSame(self::STORES[0], ByProductContext::store(self::STORES, 'alt'));
        self::assertNull(ByProductContext::store(self::STORES, null));
        self::assertNull(ByProductContext::store([], 'alt'));
    }
}
