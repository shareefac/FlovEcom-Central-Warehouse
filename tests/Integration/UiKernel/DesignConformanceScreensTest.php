<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Design conformance (the owner, 30 Oct 2026: "strictly follow our design guidelines"; docs/decisions.md DC), the shared parts as the pages
 * are served: every board's "+ New" is in its header beside the title (never in the toolbar, never with a second More of other create
 * actions), no page has a sentence under its heading or a full stop on it, Purchasing has no buying-flow strip, Goods in's own Show replaces
 * its segment row (DC28: no page has a tab row; the sidebar holds the pages), the shell is the plain name and words, and a person who can only look finds
 * who may change it under the heading's "?".
 */
final class DesignConformanceScreensTest extends KernelUiTestCase
{
    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    /** The statement of the approved mockups: no sentence under it, no full stop, ONE "+ New …" in its header (or none) and none in the toolbar. */
    private static function assertMockupHead(UiResponse $page, string $what, ?string $new): void
    {
        self::assertSame(200, $page->status, $page->describe());
        $xp = self::xp($page);
        self::assertSame(0, $xp->query('//main//header[contains(@class, "page-head")]//p[contains(@class, "lede")]')->length, "{$what}: no sentence under the heading");
        self::assertSame(0, $xp->query('//main//h1//span[contains(@class, "stop")]')->length, "{$what}: no full stop");
        self::assertSame(0, $xp->query('//main//ol[contains(@class, "flow")]')->length, "{$what}: no buying-flow strip");
        self::assertSame(0, $xp->query('//main//form[contains(@class, "toolbar")]//a[contains(@class, "primary")]')->length, "{$what}: no create button in the toolbar");
        self::assertSame(0, $xp->query('//main//header[contains(@class, "page-head")]//details[contains(@class, "more")]/summary[@aria-label="' . Words::UI['more_new'] . '"]')->length,
            "{$what}: no second More of other create actions");
        if ($new !== null) {
            $btn = $xp->query('//main//header[contains(@class, "page-head")]/div[@class="ph-actions"]/a[contains(@class, "btn") and contains(@class, "primary")]');
            self::assertSame(1, $btn->length, "{$what}: \"+ New\" beside the title");
            self::assertSame($new, trim((string) $btn->item(0)?->textContent), $what);
        }
        self::assertSame(0, $xp->query('//header[@class="appbar"]//a[contains(@class, "bell")] | //nav[@id="menu"]//a[contains(@class, "side-find")]')->length, "{$what}: the plain shell");
        self::assertSame(0, $xp->query('//nav[@id="menu"]//a[@class="nav-item"]/span[contains(@class, "ico")]')->length, "{$what}: the menu is words only");
    }

    public function testEveryBoardsNewIsInItsHeaderAndNoPageHasASentenceUnderItsHeading(): void
    {
        $buyer = $this->signIn($this->uiUser(['buyer', 'purchasing_manager']));
        $orders = $buyer->get('/ui/purchasing/orders');
        self::assertMockupHead($orders, 'Purchase orders', Words::NEW['orders']);
        self::assertSame(Words::MENU['orders'], trim((string) self::xp($orders)->evaluate('string(//main//h1/span[@class="stmt"])')), 'sentence case: "Purchase orders"');
        self::assertMockupHead($buyer->get('/ui/purchasing/suppliers'), 'Suppliers', Words::NEW['suppliers']);
        self::assertMockupHead($buyer->get('/ui/purchasing/invoices'), 'Supplier invoices', null);

        $stock = $this->signIn($this->uiUser(['stock_controller', 'warehouse']));
        self::assertMockupHead($stock->get('/ui/stock/in'), 'Stock in', Words::NEW['stock_in']);
        self::assertMockupHead($stock->get('/ui/stock/adjustments'), 'Adjustments', Words::NEW['adjustments']);
        // All Products (w11): its main create action, Add Item, beside the title (DC27); its rare controls under the toolbar's More.
        $cards = $stock->get('/ui/items/cards');
        self::assertMockupHead($cards, 'All products', Words::NEW['add_item']);
        self::assertSame('/ui/items/new', self::xp($cards)->evaluate('string(//main//header[contains(@class, "page-head")]/div[@class="ph-actions"]/a/@href)'));
        self::assertSame(0, self::xp($cards)->query('//main//form[contains(@class, "toolbar")]/div[@class="tb-end"]/a')->length, 'no loose buttons in the toolbar');
        self::assertSame(['tb-search', 'tb-state', 'tb-end'], array_map(static fn (\DOMElement $d): string => $d->getAttribute('class'),
            iterator_to_array(self::xp($cards)->query('//main//form[contains(@class, "toolbar")]/div') ?: [])), 'one search, one Show, the More');
        self::assertSame(['state', 'type', 'stock', 'warnings', 'blocked', 'discontinued'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            iterator_to_array(self::xp($cards)->query('//main//form[contains(@class, "toolbar")]//details[contains(@class, "tb-show")]//*[@name]') ?: [])), 'every filter inside the one Show');
        $counts = $stock->get('/ui/stock/counts');
        self::assertMockupHead($counts, 'Counts', null);
        self::assertSame(0, self::xp($counts)->query('//main//nav[contains(@class, "tabs")]')->length, 'Counts: no tab row, no segment row (DC28)');
        self::assertSame(['Next to count', 'Count sheets', 'Differences'], array_column(self::segments($counts), 'label'), 'its pages in the sidebar, one indent under Counts');

        $goods = $this->signIn($this->uiUser('goods_in'));
        $in = $goods->get('/ui/receiving');
        self::assertMockupHead($in, 'Goods in', Words::NEW['receiving']);
        $xp = self::xp($in);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")]')->length, 'GI_List: no tab row, no segment row (DC28)');
        self::assertSame([null, '/ui/receiving/bench', '/ui/receiving/incidents', '/ui/receiving/duty', '/ui/receiving/arriving'], array_column(self::segments($in), 'href'),
            'Goods in\'s pages in the sidebar, the one shown without a link (CV13)');
        $bench = $goods->get('/ui/receiving/bench');
        self::assertSame(200, $bench->status, $bench->describe());
        self::assertSame(['Purchasing', 'Goods in'], [trim((string) self::xp($bench)->evaluate('string(//nav[@aria-label="Main"]//a[contains(@class, "open")]/span[@class="nav-label"])')),
            self::currentTab($bench)], 'Purchasing open, Goods in its current page');
        self::assertSame('/ui/receiving', self::xp($bench)->evaluate('string(//nav[@aria-label="Main"]//ul[@class="nav-sub"]/li/a[@aria-current]/@href)'), 'which leads back to the list');
    }

    public function testALookOnlyPageSaysWhoChangesItUnderTheHeadingsQuestionMark(): void
    {
        $viewer = $this->signIn($this->uiUser('auditor'));
        $orders = $viewer->get('/ui/purchasing/orders');
        self::assertMockupHead($orders, 'Purchase orders, looking', null);
        $xp = self::xp($orders);
        self::assertSame(0, $xp->query('//main//header[contains(@class, "page-head")]/div[@class="ph-actions"]')->length, 'nothing to create');
        $help = $xp->query('//main//header[contains(@class, "page-head")]//div[@class="ph-title"]/details[contains(@class, "look-only")]');
        self::assertSame(1, $help->length, 'the "?" beside the heading');
        self::assertStringStartsWith('You can look;', trim((string) $help->item(0)?->textContent));
        // The steps that keep their line: the password's rule.
        $password = $viewer->get('/ui/password');
        self::assertSame(Words::PAGE_INTRO['password'][0] . ' ' . Words::PAGE_INTRO['password'][1],
            trim((string) preg_replace('/\s+/u', ' ', (string) self::xp($password)->evaluate('string(//main//p[@class="lede"])'))));
    }
}
