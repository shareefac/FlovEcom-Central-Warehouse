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
        self::assertSame(0, $xp->query('//header[@class="appbar"]//a[contains(@class, "bell")]')->length, "{$what}: no bell");
        self::assertSame(0, $xp->query('//main//header[contains(@class, "page-head")]//p[contains(@class, "ph-eyebrow")]')->length, "{$what}: a list is its title alone (RD3)");
        self::assertGreaterThan(0, $xp->query('//nav[@id="menu"]//a[contains(@class, "nav-item")]/span[@class="nav-chip"]/span[contains(@class, "ico")]')->length,
            "{$what}: Sidebar.dc.html's icon chips (RD1)");
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
        self::assertMockupHead($cards, 'Items', Words::NEW['add_item']);
        self::assertSame('/ui/items/new', self::xp($cards)->evaluate('string(//main//header[contains(@class, "page-head")]/div[@class="ph-actions"]/a/@href)'));
        self::assertSame(0, self::xp($cards)->query('//main//form[contains(@class, "toolbar")]/div[@class="tb-end"]/a')->length, 'no loose buttons in the toolbar');
        // The redesign's simple list (docs/decisions.md RD-A2, RD-A3): one search and ONE Show choice in the toolbar; every filter of before in More.
        self::assertSame(['tb-search', 'show-menu'], array_map(static fn (\DOMElement $d): string => $d->getAttribute('class'),
            iterator_to_array(self::xp($cards)->query('//main//form[contains(@class, "toolbar")]/*[not(self::input)]') ?: [])), 'one search, one Show');
        self::assertSame(['state', 'type', 'stock', 'warnings', 'blocked', 'discontinued'], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            iterator_to_array(self::xp($cards)->query('//main//header//details[contains(concat(" ", @class, " "), " more ")]//form//*[@name and not(@type="hidden")]') ?: [])), 'every filter inside More');
        $counts = $stock->get('/ui/stock/counts');
        self::assertMockupHead($counts, 'Counts', null);
        self::assertSame(0, self::xp($counts)->query('//main//nav[contains(@class, "tabs")]')->length, 'Counts: no tab row, no segment row (DC28)');
        self::assertSame(['Items to count', 'Count sheets', 'Differences'], array_column(self::segments($counts), 'label'), 'its pages: its Show (RD2)');

        $goods = $this->signIn($this->uiUser('goods_in'));
        $in = $goods->get('/ui/receiving');
        self::assertMockupHead($in, 'Goods received', Words::NEW['receiving']);
        $xp = self::xp($in);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")]')->length, 'GI_List: no tab row, no segment row (DC28)');
        self::assertSame([null, '/ui/receiving/bench', '/ui/receiving/incidents', '/ui/receiving/duty', '/ui/receiving/arriving'], array_column(self::segments($in), 'href'),
            'Goods received\'s pages: its Show, the one shown without a link (CV13)');
        $bench = $goods->get('/ui/receiving/bench');
        self::assertSame(200, $bench->status, $bench->describe());
        self::assertSame(['Purchases', 'Goods received'], [trim((string) self::xp($bench)->evaluate('string(//nav[@aria-label="Main"]//a[contains(@class, "open")]/span[@class="nav-label"])')),
            self::currentTab($bench)], 'Purchases open, Goods received its current entry');
        self::assertSame('/ui/receiving', self::xp($bench)->evaluate('string(//nav[@aria-label="Main"]//ul[contains(@class, "nav-sub")]/li/a[@aria-current]/@href)'), 'which leads back to the list');
    }

    public function testALookOnlyPageSaysWhoChangesItUnderTheHeadingsQuestionMark(): void
    {
        $viewer = $this->signIn($this->uiUser('auditor'));
        $orders = $viewer->get('/ui/purchasing/orders');
        self::assertMockupHead($orders, 'Purchase orders, looking', null);
        $xp = self::xp($orders);
        self::assertSame(0, $xp->query('//main//header[contains(@class, "page-head")]/div[@class="ph-actions"]')->length, 'nothing to create');
        $help = $xp->query('//main//header[contains(@class, "page-head")]//div[@class="ph-title"]/details[contains(@class, "help")]');
        self::assertSame(1, $help->length, 'ONE "?" beside the heading (DC34): the page\'s own help, who changes it its last line');
        self::assertStringContainsString('look-only', (string) $help->item(0)?->getAttribute('class'));
        self::assertStringStartsWith('You can look;', trim((string) $xp->evaluate('string(//main//header[contains(@class, "page-head")]//details[contains(@class, "look-only")]/div/p[last()])')));
        // The steps that keep their line: the password's rule.
        $password = $viewer->get('/ui/password');
        self::assertSame(Words::PAGE_INTRO['password'][0] . ' ' . Words::PAGE_INTRO['password'][1],
            trim((string) preg_replace('/\s+/u', ' ', (string) self::xp($password)->evaluate('string(//main//p[@class="lede"])'))));
    }

    /**
     * The Codex review of f4d1126 (docs/decisions.md DC30): the purchase orders' More keeps every filter on: at ?state=sent&drafts=1 "Hide the
     * drafts again" leads back to the sent orders (the old checkbox kept the hidden state field), with the search and the supplier.
     */
    public function testThePurchaseOrdersMoreKeepsEveryFilterOn(): void
    {
        $web = $this->signIn($this->uiUser('goods_in'));
        $page = $web->get('/ui/purchasing/orders', ['state' => 'sent', 'drafts' => '1', 'q' => 'abc', 'rejected' => '1']);
        self::assertSame(200, $page->status, $page->describe());
        $more = self::xp($page)->query('//main//form[contains(@class, "toolbar")]//details[contains(@class, "tb-more")]//a[@class="menu-item"]');
        $hrefs = array_map(static fn (\DOMElement $a): string => (string) $a->getAttribute('href'), iterator_to_array($more ?: []));
        self::assertContains('/ui/purchasing/orders?state=sent&q=abc&rejected=1', $hrefs, 'Hide the drafts again keeps the state, the search and the sent-back filter');
    }

    /**
     * The redesign's shared look as served (docs/decisions.md RD1-RD10): a list's status is a pill in its cell (no filled cell), its groups
     * dot headings over plain cards; a list is marked so its figure tiles are not drawn; a grouped page shows its group's Show under its
     * title when it draws none of its own (its page not a link); the phone's top bar and bottom bar of Phone.dc.html.
     */
    public function testTheRedesignsSharedPartsAsServed(): void
    {
        $web = $this->signIn($this->uiUser(['stock_controller', 'warehouse']));
        $stock = $web->get('/ui/stock');
        self::assertSame(200, $stock->status, $stock->describe());
        $xp = self::xp($stock);
        self::assertSame(1, $xp->query('//main[@id="main" and contains(concat(" ", @class, " "), " is-list ")]')->length, 'a list: no figure tiles (RD10)');
        foreach ($xp->query('//main//td[contains(concat(" ", @class, " "), " doc-st ")]') ?: [] as $td) {
            /** @var \DOMElement $td */
            self::assertSame(1, $xp->query('span[@class="chip"]', $td)->length, 'the status is a pill in its cell (RD8)');
        }
        $sheets = $web->get('/ui/stock/counts/sheets');
        $sx = self::xp($sheets);
        self::assertSame(1, $sx->query('//main//form[contains(@class, "toolbar")]/details[@class="show-menu"]')->length,
            'the group\'s Show in the toolbar beside the search (RD2; the integration, RD-I8)');
        self::assertSame(0, $sx->query('//main//div[@class="show-row"]')->length, 'the head draws no second Show');
        // A filter on behind a closed control is said on it (the fix round, docs/decisions.md RD-F5): History's kind and status in its Show,
        // Stock on hand's warehouse in the page head's More.
        $history = self::xp($web->get('/ui/documents', ['type' => 'GRN', 'status' => 'posted']));
        self::assertSame('2', trim((string) $history->evaluate('string(//main//details[contains(@class, "show-menu")]/summary/span[@class="count"]/text())')),
            'two filters on, said on the closed Show');
        $verify = (string) self::warehouseId('MAIN');
        $onHand = self::xp($web->get('/ui/stock', ['warehouse' => $verify]));
        self::assertSame('1', trim((string) $onHand->evaluate('string(//main//header[contains(@class, "page-head")]//details[contains(concat(" ", @class, " "), " more ")]/summary/span[@class="count"]/text())')),
            'the warehouse chosen, said on the closed More');
        self::assertSame(0, self::xp($web->get('/ui/stock'))->query('//main//header//details[contains(concat(" ", @class, " "), " more ")]/summary/span[@class="count"]')->length, 'none on');
        $arriving = self::xp($web->get('/ui/receiving/arriving'));
        self::assertSame(1, $arriving->query('//main//header[contains(@class, "page-head")]/following-sibling::div[@class="show-row"][1]/details[@class="show-menu"]')->length,
            'a grouped page without a toolbar: the group\'s Show right under the title (RD2)');
        self::assertSame(['Items to count', 'Count sheets', 'Differences'], array_column(self::segments($sheets), 'label'));
        self::assertSame(0, $sx->query('//main//header[contains(@class, "page-head")]//p[contains(@class, "ph-eyebrow")]')->length, 'a list is its title alone (RD3)');
        $home = $web->get('/ui/');
        $hx = self::xp($home);
        self::assertSame(['/ui/', '/ui/search', '#menu'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($hx->query('//header[@class="appbar"]/a'))), 'Phone.dc.html\'s top bar: the CW tile, Find, Menu');
        self::assertSame('Home', trim((string) $hx->evaluate('string(//header[@class="appbar"]/span[@class="appbar-title"])')));
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_column(self::tabs($home), 'label'));
        self::assertSame('Inventory', trim((string) self::xp($sheets)->evaluate('string(//header[@class="appbar"]/span[@class="appbar-title"])')), 'the section\'s name');
    }
}
