<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Staff\StaffAdmin;
use CW\Tests\Support\KernelUiTestCase;

/**
 * Role-aware menus and permission-guarded routes (I11, I14), through the real /ui kernel as cw_app. Mirrors the
 * owner's acceptance test of Phase I-1: "sign in as buyer, desk and reviewer and see different menus"; none of them
 * sees Linking or Admin, and a page left out of a menu is also refused (403), not only hidden.
 */
final class MenusTest extends KernelUiTestCase
{
    private const SOON = ' · coming in Phase ';

    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $buyer = $this->signIn($this->uiUser('buyer'));
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $reviewer = $this->signIn($this->uiUser('reviewer'));

        $b = self::nav($buyer->get('/ui/'));
        $d = self::nav($desk->get('/ui/'));
        $r = self::nav($reviewer->get('/ui/'));
        self::assertSame(['Items', 'Purchasing', 'Documents', 'Reference'], array_keys($b));
        self::assertSame(['Items', 'Purchasing', 'Receiving', 'Trade', 'Documents', 'Reference'], array_keys($d));
        self::assertSame(['Items', 'Purchasing', 'Document reviews', 'Documents', 'Reference'], array_keys($r));
        foreach ([$b, $d, $r] as $nav) {
            self::assertArrayNotHasKey('Linking', $nav);
            self::assertArrayNotHasKey('Admin', $nav);
            self::assertSame([['label' => 'Search', 'href' => '/ui/search']], $nav['Items']);
        }
        self::assertSame([
            ['label' => 'Suppliers', 'href' => '/ui/purchasing/suppliers'],
            ['label' => 'Purchase orders', 'href' => '/ui/purchasing/orders'],
            ['label' => 'Reorder list', 'href' => '/ui/purchasing/reorder'],
            ['label' => 'Sales history', 'href' => '/ui/purchasing/sales-history'],
        ], $b['Purchasing'], 'every Purchasing item is live (the I-2 suppliers, pos and reorder tasks)');
        self::assertSame([
            ['label' => 'Suppliers', 'href' => '/ui/purchasing/suppliers'],
            ['label' => 'Purchase orders', 'href' => '/ui/purchasing/orders'],
        ], $d['Purchasing'], 'the desk sees suppliers and purchase orders, not the reorder list');
        self::assertSame($b['Purchasing'], $r['Purchasing'], 'the reviewer reads the purchasing screens');
        self::assertSame([
            ['label' => 'Receive + invoice' . self::SOON . 'I-3', 'href' => null],
            ['label' => 'Supplier invoices' . self::SOON . 'I-4', 'href' => null],
            ['label' => 'Supplier returns' . self::SOON . 'I-4', 'href' => null],
        ], $d['Receiving']);
        self::assertSame([['label' => 'Trade and inter-site issues' . self::SOON . 'I-6', 'href' => null]], $d['Trade']);
        // Live since the documents task (0008, I27): the review queue, the documents list and the reference lists.
        self::assertSame([['label' => 'Review queue', 'href' => '/ui/documents/reviews']], $r['Document reviews']);
        self::assertSame([['label' => 'All documents', 'href' => '/ui/documents']], $r['Documents']);
        self::assertSame([['label' => 'Reason codes', 'href' => '/ui/reference/reasons'], ['label' => 'Number series', 'href' => '/ui/reference/series'],
            ['label' => 'Settings', 'href' => '/ui/reference/settings'], ['label' => 'Company details', 'href' => '/ui/reference/company']], $b['Reference']);
        self::assertSame($b['Reference'], $r['Reference'], 'every role reads the company details (I90); only the reviewer changes them, on the page');
        self::assertNotSame($b, $d);
        self::assertNotSame($d, $r);
        self::assertNotSame($b, $r);
    }

    public function testPagesOutsideTheMenuAreRefusedNotOnlyHidden(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'M1', 'Key', $this->item('legacy', 0, 'Menu item'), ['product_title' => 'Menu item']);
        $u = $this->uiUser('buyer');
        $buyer = $this->signIn($u);
        foreach (['/ui/review' => ['queue' => 'Key'], '/ui/review/listing/' . $l => [], '/ui/people' => [], '/ui/people/' . $u['id'] => []] as $path => $query) {
            $page = $buyer->get($path, $query);
            self::assertSame(403, $page->status, "{$path}: " . $page->describe());
            self::assertStringContainsString('role_not_allowed', $page->text());
            self::assertStringContainsString('your role (buyer) does not open this page', $page->text());
            self::assertSame(['Items', 'Purchasing', 'Documents', 'Reference'], array_keys(self::nav($page)), 'the error page keeps the menu');
        }
        self::assertSame(200, $buyer->get('/ui/search', ['q' => 'Menu'])->status);
        $decide = $buyer->post("/ui/review/listing/{$l}/decide", ['csrf' => $this->token($buyer), 'action' => 'ignore', 'reason' => 'x']);
        self::assertSame(403, $decide->status);
        self::assertStringContainsString('your role (buyer) cannot make mapping decisions', $decide->text());
        $approve = $buyer->post('/ui/review/decision/1/approve', ['csrf' => $this->token($buyer)]);
        self::assertSame(403, $approve->status);
        self::assertStringContainsString('lead_required', $approve->text());

        // Several roles: the union; the message names them all.
        $both = $this->signIn($this->uiUser(['buyer', 'stock_controller']));
        $page = $both->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(403, $page->status);
        self::assertStringContainsString('your roles (buyer, stock_controller) do not open this page', $page->text());
        self::assertSame(['Items', 'Purchasing', 'Stock control', 'Documents', 'Reference'], array_keys(self::nav($both->get('/ui/'))));
    }

    public function testTheHomePageForRolesWithoutLinkingAndTheDashboardForMappers(): void
    {
        $site = $this->site('vpg');
        $this->queued($site, 'H1', 'Key', $this->item('legacy', 0, 'Home item'), ['product_title' => 'Home item']);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $home = $buyer->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        self::assertStringContainsString('Signed in as Buyer 1 (buyer).', $home->text());
        self::assertStringNotContainsString('Listings waiting for a decision', $home->text());
        self::assertStringNotContainsString('coming in Phase I-2', $home->text());
        $xp = new \DOMXPath($home->dom());
        self::assertSame(4, $xp->query('//main//section[contains(@class, "card")]')->length, 'one card per menu section');
        self::assertSame(['/ui/search', '/ui/purchasing/suppliers', '/ui/purchasing/orders', '/ui/purchasing/reorder', '/ui/purchasing/sales-history', '/ui/documents',
            '/ui/reference/reasons', '/ui/reference/series', '/ui/reference/settings', '/ui/reference/company'], array_values(array_filter(array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xp->query('//main//a'))))), 'the live links of a buyer after the I-2 reorder task');
        self::assertSame(1, $xp->query('//header//form[@action="/ui/search"]')->length, 'the quick search box: catalogue.view');

        $mapper = $this->signIn($this->uiUser('mapper'));
        $dash = $mapper->get('/ui/');
        self::assertSame(200, $dash->status);
        self::assertStringContainsString('Listings waiting for a decision', $dash->text());
        $nav = self::nav($dash);
        self::assertSame(['Linking', 'Items', 'Reference'], array_keys($nav));
        self::assertSame(['/ui/', '/ui/review?queue=Key', '/ui/review?queue=pending', '/ui/review/samples'], array_column($nav['Linking'], 'href'));
        $current = (new \DOMXPath($dash->dom()))->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('Dashboard', trim((string) $current->item(0)?->textContent));

        // A person whose roles were all taken away (admin SQL only) is told so and sees no menu.
        $none = $this->uiUser('viewer');
        $web = $this->signIn($none);
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$none['id']]);
        $page = $web->get('/ui/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('You have no roles yet: ask an admin', $page->text());
        self::assertSame([], self::nav($page));
        self::assertSame(403, $web->get('/ui/search')->status);
    }

    public function testAdminSeesAdminAndLinkingButNoPurchasingAndTheBadgeFollowsLinkingView(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'B1', 'Key', $this->item('legacy', 0, 'Badge item'), ['product_title' => 'Badge item']);
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'link', $l, ['sku_id' => $this->item('legacy', 0, 'Other'), 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$l])]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'pending_second'"));

        $admin = $this->signIn($this->uiUser('admin'));
        $nav = self::nav($admin->get('/ui/'));
        self::assertSame(['Linking', 'Items', 'Reference', 'Admin'], array_keys($nav));
        self::assertArrayNotHasKey('Purchasing', $nav);
        self::assertSame([['label' => 'People and roles', 'href' => '/ui/people']], $nav['Admin']);
        self::assertSame('Second approval 1', $nav['Linking'][2]['label'], 'the waiting count');

        $reviewer = $this->signIn($this->uiUser('reviewer'));
        self::assertStringNotContainsString('class="badge"', $reviewer->get('/ui/')->body, 'no linking.view, no linking count');
        $auditor = $this->signIn($this->uiUser('auditor'));
        self::assertSame(['Linking', 'Items', 'Purchasing', 'Documents', 'Accounts', 'Reference', 'Admin'], array_keys(self::nav($auditor->get('/ui/'))));
        self::assertSame(['/ui/', '/ui/review?queue=Key', '/ui/review?queue=pending', '/ui/review/samples'], array_column(self::nav($auditor->get('/ui/'))['Linking'], 'href'));
    }

    public function testARoleTakenAwayStopsWorkingOnTheNextRequest(): void
    {
        $u = $this->uiUser(['mapper', 'buyer']);
        $web = $this->signIn($u);
        self::assertSame(200, $web->get('/ui/review', ['queue' => 'Key'])->status);
        $admin = $this->uiUser('admin');
        (new StaffAdmin(self::$db))->setRoles(Caller::staff($admin['id']), $u['id'], ['buyer'], null);
        self::assertSame(403, $web->get('/ui/review', ['queue' => 'Key'])->status, 'the same session, the next request');
        self::assertArrayNotHasKey('Linking', self::nav($web->get('/ui/')));
    }
}
