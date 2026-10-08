<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\Kernel;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The navigation map (Ui\Sections; owner's request of 8 Oct 2026: seven standard sidebar names, everything else a tab): every page
 * it links is a live GET route guarded by exactly the permission the map names, every staff page belongs to one section and tab
 * (detail pages included), each name comes from Words, and the people of the owner's acceptance test see different menus.
 * Pure: no database.
 */
final class SectionsTest extends TestCase
{
    /** @return array<string, string> GET route pattern => its access */
    private static function gets(): array
    {
        $kernel = new Kernel(static fn (): never => throw new \RuntimeException('no database'), static fn (): ?string => null, static function (): void {
        });
        $out = [];
        foreach ($kernel->router()->routes() as $route) {
            if ($route->method === 'GET') {
                $out[$route->pattern] = $route->access;
            }
        }
        return $out;
    }

    public function testEveryPageIsALiveRouteGuardedByTheSamePermissionWithItsWords(): void
    {
        $gets = self::gets();
        self::assertSame(['dashboard', 'products', 'stock', 'purchasing', 'reports', 'approvals', 'settings'], array_keys(Sections::MAP));
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Reports', 'Approvals', 'Settings'], array_values(Words::SECTION),
            'the seven standard names (owner, 8 Oct 2026)');
        foreach (Sections::MAP as $s => $section) {
            self::assertArrayHasKey($s, Words::SECTION);
            self::assertNotSame('', Words::SECTION_DESC[$s] ?? '', "{$s}: its one line");
            self::assertSame(['tabs'], array_keys($section), $s);
            foreach ($section['tabs'] as $t => $tab) {
                $what = "{$s} / {$t}";
                self::assertArrayHasKey($t, Words::MENU, "{$what}: its name");
                if (($tab['soon'] ?? false) === true) {
                    self::assertSame(['soon' => true], $tab, "{$what}: a tab not built yet has no page");
                    continue;
                }
                self::assertSame([], array_diff(array_keys($tab), ['pages', 'segments']), $what);
                self::assertArrayHasKey($t, Words::MENU_HELP, "{$what}: its line on the Dashboard");
                $keys = [];
                foreach ($tab['pages'] as $page) {
                    $key = (string) ($page['key'] ?? $t);
                    $keys[] = $key;
                    $where = "{$what} / {$key}";
                    self::assertSame([], array_diff(array_keys($page), ['perm', 'path', 'query', 'match', 'badge', 'key', 'new']), $where);
                    self::assertArrayHasKey($page['perm'], Permissions::MAP, $where);
                    self::assertArrayHasKey($page['path'], $gets, "{$where}: a real GET route");
                    self::assertTrue($gets[$page['path']] === $page['perm'] || $gets[$page['path']] === 'any',
                        "{$where}: the link is shown to exactly the people the route lets in");
                    if (($tab['segments'] ?? false) === true) {
                        self::assertArrayHasKey($key, Words::SEGMENT, "{$where}: the segment's name");
                    }
                    if (isset($page['badge'])) {
                        self::assertContains($page['badge'], ['linking_pending', 'linking_duplicates', 'reviews_open', 'barcodes_open', 'incidents_open'],
                            "{$where}: a count Ui\\Context::badges() computes");
                        self::assertArrayHasKey($page['badge'], Words::BADGE, "{$where}: the words a screen reader hears");
                    }
                    if (isset($page['new'])) {
                        self::assertArrayHasKey($page['new']['perm'], Permissions::MAP, $where);
                        self::assertArrayHasKey($page['new']['label'], Words::NEW, $where);
                        self::assertStringStartsWith($page['path'], $page['new']['href'], "{$where}: the create button leads to the page's own form");
                    }
                    // The page's own link belongs to it.
                    self::assertSame(['section' => $s, 'tab' => $t, 'page' => $key], Sections::locate($page['path'], $page['query'] ?? []), "{$where}: its link");
                }
                self::assertSame(array_values(array_unique($keys)), $keys, "{$what}: a key marks one page");
            }
        }
        self::assertSame(['linking_pending', 'linking_duplicates', 'barcodes_open', 'incidents_open', 'reviews_open'], Sections::badgeNames());
    }

    /** Every staff page belongs to one section, tab and page: the current ones stay marked on a detail page (with its back link). */
    public function testEveryStaffPageBelongsToItsSectionAndTab(): void
    {
        $outside = ['/ui/login', '/ui/logout', '/ui/enrol', '/ui/new-code', '/ui/password'];
        foreach (self::gets() as $pattern => $access) {
            $path = str_replace('{id}', '12', $pattern);
            $here = Sections::locate($path, $pattern === '/ui/review' ? ['queue' => 'Key'] : []);
            if (in_array($pattern, $outside, true)) {
                self::assertNull($here, $pattern);
                continue;
            }
            self::assertNotNull($here, "{$pattern} belongs to a section");
        }
        $expect = [
            ['/ui', [], 'dashboard', 'home', 'home'],
            ['/ui/', [], 'dashboard', 'home', 'home'],
            ['/ui/review', ['queue' => 'Key'], 'products', 'mapping', 'review'],
            ['/ui/review', ['queue' => 'Check', 'channel' => '2'], 'products', 'mapping', 'review'],
            ['/ui/review', ['queue' => 'pending'], 'products', 'mapping', 'pending'],
            ['/ui/review/listing/12', ['queue' => 'Key'], 'products', 'mapping', 'review'],
            ['/ui/review/decision/12/approve', [], 'products', 'mapping', 'review'],
            ['/ui/review/samples', [], 'products', 'mapping', 'samples'],
            ['/ui/review/samples/12', [], 'products', 'mapping', 'samples'],
            ['/ui/review/duplicates/12', [], 'products', 'duplicates', 'duplicates'],
            ['/ui/items/cards', [], 'products', 'cards', 'cards'],
            ['/ui/items/cards/import', [], 'products', 'cards', 'cards'],
            ['/ui/items/12', [], 'products', 'cards', 'cards'],
            ['/ui/items/12/card', [], 'products', 'cards', 'cards'],
            ['/ui/search', ['q' => 'x'], 'products', 'cards', 'cards'],
            ['/ui/items/barcodes', [], 'products', 'barcodes', 'barcodes'],
            ['/ui/stock', [], 'stock', 'stock', 'stock'],
            ['/ui/stock/movements', ['type' => 'goods_in'], 'stock', 'movements', 'movements'],
            ['/ui/purchasing/reorder', [], 'purchasing', 'reorder', 'reorder'],
            ['/ui/purchasing/reorder/items/12', [], 'purchasing', 'reorder', 'reorder'],
            ['/ui/purchasing/reorder/brands', [], 'purchasing', 'reorder', 'brands'],
            ['/ui/purchasing/reorder/anomalies/12/end', [], 'purchasing', 'reorder', 'anomalies'],
            ['/ui/purchasing/sales-history', [], 'purchasing', 'reorder', 'sales_history'],
            ['/ui/purchasing/orders/12', [], 'purchasing', 'orders', 'orders'],
            ['/ui/receiving', [], 'purchasing', 'goods_in', 'receiving'],
            ['/ui/receiving/12', [], 'purchasing', 'goods_in', 'receiving'],
            ['/ui/receiving/bench', [], 'purchasing', 'goods_in', 'bench'],
            ['/ui/receiving/12/bench', [], 'purchasing', 'goods_in', 'bench'],
            ['/ui/receiving/incidents', [], 'purchasing', 'goods_in', 'incidents'],
            ['/ui/purchasing/suppliers/12/items', [], 'purchasing', 'suppliers', 'suppliers'],
            ['/ui/purchasing/supplier-items/12', [], 'purchasing', 'suppliers', 'suppliers'],
            ['/ui/system/audit', [], 'reports', 'audit', 'audit'],
            ['/ui/documents/reviews', [], 'approvals', 'reviews', 'reviews'],
            ['/ui/documents/12', [], 'approvals', 'documents', 'documents'],
            ['/ui/staff-requests', [], 'approvals', 'staff_requests', 'staff_requests'],
            ['/ui/reference/company/edit', [], 'settings', 'company', 'company'],
            ['/ui/reference/warehouses/12', [], 'settings', 'warehouses', 'warehouses'],
            ['/ui/system/sites', [], 'settings', 'sites', 'sites'],
            ['/ui/people/12', [], 'settings', 'people', 'people'],
            ['/ui/reference/access', [], 'settings', 'people', 'access'],
            ['/ui/reference/approvals', [], 'settings', 'approvals', 'approvals'],
            ['/ui/reference/reasons/reason', ['code' => 'x'], 'settings', 'reasons', 'reasons'],
            ['/ui/reference/settings/setting', ['key' => 'x'], 'settings', 'system', 'settings'],
            ['/ui/reference/series', [], 'settings', 'system', 'series'],
            ['/ui/system/checks', [], 'settings', 'system', 'integrity'],
        ];
        foreach ($expect as [$path, $query, $s, $t, $p]) {
            self::assertSame(['section' => $s, 'tab' => $t, 'page' => $p], Sections::locate($path, $query), $path . ' ' . json_encode($query));
        }
        self::assertNull(Sections::locate('/ui/password'));
        self::assertNull(Sections::fit('/ui/receiving/{id}/bench', '/ui/receiving/x/bench'), '{id} is a number');
        self::assertNull(Sections::fit('/ui/review?queue=pending', '/ui/review', ['queue' => 'Key']));
    }

    /**
     * The owner's acceptance test (I-1 §4.4) on the new navigation: buyer, purchasing desk and reviewer see different menus (the
     * desk has Goods In and no Reorder; the reviewer also Reports and Waiting for me); nobody sees a section with nothing for them.
     */
    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $sections = static fn (array $roles): array => array_column(Sections::menu($roles), 'section');
        $tabs = static function (array $roles, string $section): array {
            foreach (Sections::menu($roles) as $s) {
                if ($s['key'] === $section) {
                    return array_column($s['items'], 'label');
                }
            }
            return [];
        };
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Approvals', 'Settings'], $sections(['buyer']));
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Approvals', 'Settings'], $sections(['purchasing_desk']));
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Reports', 'Approvals', 'Settings'], $sections(['reviewer']));
        self::assertSame(['Reorder', 'Purchase Orders', 'Suppliers'], $tabs(['buyer'], 'purchasing'));
        self::assertSame(['Purchase Orders', 'Goods In', 'Suppliers'], $tabs(['purchasing_desk'], 'purchasing'), 'no Reorder for the desk');
        self::assertSame(['Reorder', 'Purchase Orders', 'Goods In', 'Suppliers'], $tabs(['reviewer'], 'purchasing'));
        self::assertSame(['History'], $tabs(['buyer'], 'approvals'));
        self::assertSame(['Waiting for me', 'History', 'Staff requests'], $tabs(['reviewer'], 'approvals'));
        self::assertNotSame(Sections::menu(['buyer']), Sections::menu(['purchasing_desk']));
        self::assertNotSame(Sections::menu(['purchasing_desk']), Sections::menu(['reviewer']));
        self::assertSame(['All Products'], $tabs(['buyer'], 'products'), 'no matching and no barcodes to decide for a buyer');
        self::assertSame(['All Products', 'Barcodes'], $tabs(['stock_controller'], 'products'));
        self::assertSame(['All Products', 'Mapping', 'Duplicates'], $tabs(['mapper'], 'products'));
        self::assertSame(['All Products', 'Mapping', 'Duplicates', 'Barcodes'], $tabs(['mapping_lead'], 'products'));
        self::assertSame(['Overview', 'Movements'], $tabs(['viewer'], 'stock'), 'everyone who sees a product\'s stock');

        // Admin: people and settings, no buying and no approvals (I12); Reports for the audit log.
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Reports', 'Settings'], $sections(['admin']));
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Users', 'Approval Rules', 'Reasons', 'System'], $tabs(['admin'], 'settings'));
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Settings'], $sections(['mapper']), 'no Reports without the audit log');
        self::assertSame(['Company', 'Warehouses', 'Users', 'Approval Rules', 'Reasons', 'System'], $tabs(['buyer'], 'settings'),
            'Users opens "Roles and permissions" for people who do not manage staff; no Stores without system.view');
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Reports', 'Approvals', 'Settings'], $sections(['auditor']));
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'Reports', 'Approvals', 'Settings'], $sections(['mapping_lead', 'reviewer']),
            'the owner\'s daily view');
        self::assertSame([], Sections::menu([]), 'no roles, no menu');

        // A tab links to the first page the person may open.
        $users = static fn (array $roles): ?string => array_column(array_column(Sections::menu($roles), 'items', 'key')['settings'], 'path', 'key')['people'] ?? null;
        self::assertSame('/ui/people', $users(['admin']));
        self::assertSame('/ui/reference/access', $users(['buyer']));
    }

    public function testTheFrameOfAPage(): void
    {
        // A mapping lead on Second approval: Products is current, Mapping is the current tab, its segment too, all with the count.
        $f = Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/review', ['queue' => 'pending']);
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Settings'], array_column($f['nav'], 'label'));
        $products = array_column($f['nav'], null, 'key')['products'];
        self::assertSame([true, 5, '/ui/items/cards'], [$products['current'], $products['count'], $products['href']], 'the section counts everything waiting in it');
        self::assertSame('Products', $f['pagebar']['label']);
        self::assertSame(Words::SECTION_DESC['products'], $f['pagebar']['desc']);
        self::assertFalse($f['pagebar']['flow']);
        self::assertSame([['All Products', false, 0], ['Mapping', true, 3], ['Duplicates', false, 2], ['Barcodes', false, 0]],
            array_map(static fn (array $t): array => [$t['label'], $t['current'], $t['count']], $f['pagebar']['tabs']));
        self::assertSame([['To review', false, 0, '/ui/review'], ['Spot check', false, 0, '/ui/review/samples'], ['Second approval', true, 3, '/ui/review']],
            array_map(static fn (array $s): array => [$s['label'], $s['current'], $s['count'], $s['href']], $f['segments']));
        self::assertSame([['queue' => 'Key'], [], ['queue' => 'pending']], array_column($f['segments'], 'query'));
        self::assertSame(Words::BADGE['linking_pending'], $f['segments'][2]['countWords']);
        self::assertSame([], $f['actions'], 'nothing to create on Mapping');
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Settings', 'More'], array_column($f['phone'], 'label'));
        self::assertSame('#menu', $f['phone'][4]['href']);

        // Stock: the tabs not built yet are drawn without a link.
        $f = Sections::frame(['buyer'], [], '/ui/stock');
        self::assertSame([['Overview', '/ui/stock', true, false], ['Movements', '/ui/stock/movements', false, false], ['Adjustments', null, false, true],
            ['Counts', null, false, true], ['Transfers', null, false, true]],
            array_map(static fn (array $t): array => [$t['label'], $t['href'], $t['current'], $t['soon']], $f['pagebar']['tabs']));
        self::assertSame([], $f['segments']);
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Purchasing', 'More'], array_column($f['phone'], 'label'), 'the phone bar the owner approved');

        // The create button: on the list itself, for the people who may create, never on a detail page.
        self::assertSame([['label' => 'New purchase order', 'href' => '/ui/purchasing/orders#new'], ['label' => 'New supplier', 'href' => '/ui/purchasing/suppliers/new']],
            Sections::frame(['buyer'], [], '/ui/purchasing/orders')['actions'], 'the page\'s own first, then the section\'s others (the split button\'s menu)');
        self::assertSame(['New purchase order', 'Receive delivery', 'New supplier'], array_column(Sections::frame(['purchasing_manager'], [], '/ui/purchasing/orders')['actions'], 'label'));
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/reorder')['actions'], 'no create action of its own: no toolbar button');
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/orders/12')['actions']);
        self::assertSame([], Sections::frame(['reviewer'], [], '/ui/purchasing/orders')['actions'], 'a reviewer does not order');
        self::assertTrue(Sections::frame(['buyer'], [], '/ui/purchasing/orders')['pagebar']['flow'], 'the buying flow on Purchasing');
        self::assertSame([['label' => 'Receive delivery', 'href' => '/ui/receiving#new']], Sections::frame(['goods_in'], [], '/ui/receiving')['actions']);
        self::assertSame(['/ui/people#new', '/ui/reference/warehouses#new', '/ui/reference/reasons#new'], array_column(Sections::frame(['admin'], [], '/ui/people')['actions'], 'href'));
        self::assertSame([], Sections::frame(['auditor'], [], '/ui/people')['actions']);
        self::assertSame(['To receive', 'Checking', 'Issues'], array_column(Sections::frame(['goods_in'], ['incidents_open' => 1], '/ui/receiving/12/bench')['segments'], 'label'));
        self::assertTrue(Sections::frame(['goods_in'], [], '/ui/receiving/12/bench')['segments'][1]['current'], 'a delivery\'s bench check is Checking');
        self::assertSame(['To receive', 'Issues'], array_column(Sections::frame(['reviewer'], [], '/ui/receiving')['segments'], 'label'), 'the bench is for the people who receive');

        // The Dashboard has no page bar (its title is the page's own); a page outside every section has none either.
        self::assertNull(Sections::frame(['buyer'], [], '/ui/')['pagebar']);
        self::assertTrue(array_column(Sections::frame(['buyer'], [], '/ui/')['nav'], null, 'key')['dashboard']['current']);
        $f = Sections::frame(['buyer'], [], '/ui/password');
        self::assertNull($f['pagebar']);
        self::assertSame([], array_filter(array_column($f['nav'], 'current')));

        // A page the person cannot open (403) keeps its section when they have the section, without a current tab.
        $f = Sections::frame(['buyer'], [], '/ui/review', ['queue' => 'Key']);
        self::assertSame('Products', $f['pagebar']['label']);
        self::assertSame([], array_filter(array_column($f['pagebar']['tabs'], 'current')));
        self::assertSame([], $f['segments']);
        self::assertNull(Sections::frame(['buyer'], [], '/ui/system/audit')['pagebar'], 'no Reports for a buyer: no page bar');

        // Admin's phone bar: no Purchasing, so the next sections it has.
        self::assertSame(['Dashboard', 'Products', 'Stock', 'Reports', 'More'], array_column(Sections::frame(['admin'], [], '/ui/')['phone'], 'label'));
        self::assertSame([], Sections::frame([], [], '/ui/')['phone'], 'no roles, no bar');
    }
}
