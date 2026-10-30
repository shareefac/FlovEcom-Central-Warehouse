<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\Kernel;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The navigation map (Ui\Sections; owner's request of 8 Oct 2026: standard sidebar names, everything else a tab; the shorter menu of
 * 29 Oct 2026, docs/decisions.md HV2-HV4): every page it links is a live GET route guarded by exactly the permission the map names, every
 * staff page belongs to one section and tab (detail pages included), each name comes from Words, the people of the owner's acceptance test
 * see different menus, the sidebar is Home, Every day and More with one current entry, a section's set-up and specialist pages are under
 * its row's More, and the bell leads where it says. Pure: no database.
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
        self::assertSame(['dashboard', 'products', 'matching', 'stock', 'purchasing', 'sales', 'reports', 'approvals', 'settings'], array_keys(Sections::MAP));
        self::assertSame(['Home', 'Products', 'Matching', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], array_values(Words::SECTION),
            'the standard names (owner, 8 Oct 2026); Home, and Matching and Sales of their own (HV2, HV3)');
        // The sidebar (HV2): Home, the daily entries (a tab or a whole section each), the other sections under More; every section is one of them.
        self::assertSame(['goods_in', 'counts', 'approvals', 'orders', 'products'], array_keys(Sections::DAILY));
        self::assertSame(['Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products'], array_map(static fn (string $k): string => Words::NAV[$k],
            array_keys(Sections::DAILY)), 'the approved HM_Home');
        self::assertSame(['stock', 'purchasing', 'sales', 'matching', 'reports', 'settings'], Sections::MORE);
        self::assertSame(['goods_in', 'counts', 'approvals'], Sections::PHONE, 'HM_Phone: Home, Goods in, Counts, Approvals, More');
        $placed = ['dashboard', ...Sections::MORE];
        foreach (Sections::DAILY as $key => [$s, $t]) {
            self::assertArrayHasKey($s, Sections::MAP, $key);
            if ($t === null) {
                $placed[] = $s;
            } else {
                self::assertArrayHasKey('pages', Sections::MAP[$s]['tabs'][$t] ?? [], "{$key}: a built tab");
            }
        }
        $sorted = $placed;
        sort($sorted);
        $all = array_keys(Sections::MAP);
        sort($all);
        self::assertSame($all, $sorted, 'every section has one sidebar entry: Home, a daily one or one under More');
        foreach (Sections::MAP as $s => $section) {
            self::assertArrayHasKey($s, Words::SECTION);
            self::assertNotSame('', Words::SECTION_DESC[$s] ?? '', "{$s}: its one line");
            self::assertSame([], array_diff(array_keys($section), ['tabs', 'count', 'keep']), $s);
            if (isset($section['count'])) {
                self::assertArrayHasKey($section['count'], $section['tabs'], "{$s}: the tab whose count is the section's (docs/decisions.md AV10)");
            }
            foreach ($section['tabs'] as $t => $tab) {
                $what = "{$s} / {$t}";
                self::assertArrayHasKey($t, Words::MENU, "{$what}: its name");
                if (($tab['soon'] ?? false) === true) {
                    self::assertSame([], array_diff(array_keys($tab), ['soon', 'more']), "{$what}: a tab not built yet has no page");
                    continue;
                }
                self::assertSame([], array_diff(array_keys($tab), ['pages', 'segments', 'keep', 'more']), $what);
                self::assertArrayHasKey($t, Words::MENU_HELP, "{$what}: its line under the row's More");
                $keys = [];
                foreach ($tab['pages'] as $page) {
                    $key = (string) ($page['key'] ?? $t);
                    $keys[] = $key;
                    $where = "{$what} / {$key}";
                    self::assertSame([], array_diff(array_keys($page), ['perm', 'path', 'query', 'match', 'badge', 'key', 'new']), $where);
                    self::assertArrayHasKey($page['path'], $gets, "{$where}: a real GET route");
                    if (is_array($page['perm'])) {
                        // Any of a list (Approvals › Waiting for you, AV1): a route open to every signed-in person, whose page refuses the rest.
                        foreach ($page['perm'] as $perm) {
                            self::assertArrayHasKey($perm, Permissions::MAP, $where);
                        }
                        self::assertSame('any', $gets[$page['path']], "{$where}: a page for the holders of any of several permissions checks them itself");
                    } else {
                        self::assertArrayHasKey($page['perm'], Permissions::MAP, $where);
                        self::assertTrue($gets[$page['path']] === $page['perm'] || $gets[$page['path']] === 'any',
                            "{$where}: the link is shown to exactly the people the route lets in");
                    }
                    if (($tab['segments'] ?? false) === true) {
                        self::assertArrayHasKey($key, Words::SEGMENT, "{$where}: the segment's name");
                    }
                    if (isset($page['badge'])) {
                        self::assertContains($page['badge'], ['linking_pending', 'linking_duplicates', 'reviews_open', 'barcodes_open', 'incidents_open', 'counts_open',
                            'approvals_inbox', // AV10
                            ...array_keys(\CW\Ui\Context::STOCK_BADGES), 'invoices_to_match',
                            'arrivals_late', 'invoices_timed_failed', // Pack J (PJ10, PJ14)
                            // Pack F (PF5)
                            'queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold',
                            'queue_landing'], // 0050 (LP8)
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
        self::assertSame(['barcodes_open', 'linking_pending', 'linking_duplicates', 'stock_in_open', 'stock_out_open', 'transfers_open', 'releases_open',
            'adjustments_open', 'counts_open', 'incidents_open', 'arrivals_late', 'invoices_to_match', 'invoices_timed_failed', 'approvals_inbox', 'reviews_open', // Pack J: 2 badges
            'queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold', 'queue_landing'], Sections::badgeNames());
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
            ['/ui/review', ['queue' => 'Key'], 'matching', 'mapping', 'review'],
            ['/ui/review', ['queue' => 'Check', 'channel' => '2'], 'matching', 'mapping', 'review'],
            ['/ui/review', ['queue' => 'pending'], 'matching', 'mapping', 'pending'],
            ['/ui/review/listing/12', ['queue' => 'Key'], 'matching', 'mapping', 'review'],
            ['/ui/review/decision/12/approve', [], 'matching', 'mapping', 'review'],
            ['/ui/review/samples', [], 'matching', 'mapping', 'samples'],
            ['/ui/review/samples/12', [], 'matching', 'mapping', 'samples'],
            ['/ui/review/duplicates/12', [], 'matching', 'duplicates', 'duplicates'],
            ['/ui/review/products', ['cov' => 'every'], 'matching', 'by_product', 'by_product'],
            ['/ui/review/products.csv', [], 'matching', 'by_product', 'by_product'],
            ['/ui/review/products/12/map', ['channel' => 'x'], 'matching', 'by_product', 'by_product'],
            ['/ui/review/products/bulk', [], 'matching', 'by_product', 'by_product'],
            ['/ui/review/products/bulk/apply', [], 'matching', 'by_product', 'by_product'],
            ['/ui/review/batches/12', ['from' => 'item', 'cov' => 'every'], 'matching', 'by_product', 'by_product'],
            ['/ui/review/batches/12', ['from' => 'store'], 'matching', 'mapping', 'review'],
            ['/ui/review/new-variants', ['channel' => 'x', 'period' => 'all'], 'matching', 'new_variants', 'new_variants'],
            ['/ui/review/new-variants.csv', [], 'matching', 'new_variants', 'new_variants'],
            ['/ui/items/cards', [], 'products', 'cards', 'cards'],
            ['/ui/items/cards/import', [], 'products', 'cards', 'cards'],
            ['/ui/items/12', [], 'products', 'cards', 'cards'],
            ['/ui/items/12/card', [], 'products', 'cards', 'cards'],
            ['/ui/search', ['q' => 'x'], 'products', 'cards', 'cards'],
            ['/ui/items/barcodes', [], 'products', 'barcodes', 'barcodes'],
            ['/ui/stock', [], 'stock', 'stock', 'stock'],
            ['/ui/stock/movements', ['type' => 'goods_in'], 'stock', 'movements', 'movements'],
            ['/ui/stock/in/12', [], 'stock', 'stock_in', 'stock_in'],
            ['/ui/stock/in.csv', [], 'stock', 'stock_in', 'stock_in'],
            ['/ui/stock/out/12/add', [], 'stock', 'stock_out', 'stock_out'],
            ['/ui/stock/adjustments/12', [], 'stock', 'adjustments', 'adjustments'],
            ['/ui/stock/transfers/12/pdf', [], 'stock', 'transfers', 'transfers'],
            ['/ui/stock/releases/12', [], 'stock', 'transfers', 'releases'],
            ['/ui/stock/accounts', [], 'stock', 'transfers', 'accounts'],
            ['/ui/stock/reservations', ['channel' => 'vapeandgo', 'state' => 'held'], 'stock', 'reservations', 'reservations'],
            ['/ui/stock/reservations.csv', [], 'stock', 'reservations', 'reservations'],
            ['/ui/stock/reservations/12', [], 'stock', 'reservations', 'reservations'],
            // ---- Pack D (docs/decisions.md PD1): a section of its own since HV3, its pages its tabs ----
            ['/ui/stock/sales', ['channel' => 'vpg', 'state' => 'paid'], 'sales', 'sales_orders', 'sales_orders'],
            ['/ui/stock/sales.csv', [], 'sales', 'sales_orders', 'sales_orders'],
            ['/ui/stock/sales/12', ['unit' => 'u1'], 'sales', 'sales_orders', 'sales_orders'],
            ['/ui/stock/sales/dispatch', ['show' => 'today'], 'sales', 'sales_dispatch', 'sales_dispatch'],
            ['/ui/stock/sales/returns', [], 'sales', 'sales_returns', 'sales_returns'],
            ['/ui/stock/sales/returns.csv', ['kind' => 'returned'], 'sales', 'sales_returns', 'sales_returns'],
            // ---- end of pack D ----
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
            // Pack C: Invoices and its segments, Returns.
            ['/ui/purchasing/invoices', ['q' => 'x'], 'purchasing', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/12', [], 'purchasing', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/12/add-delivery', [], 'purchasing', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/from-receipt', [], 'purchasing', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/to-match', ['show' => 'accepted'], 'purchasing', 'invoices', 'to_match'],
            ['/ui/purchasing/invoices/to-match/recheck', [], 'purchasing', 'invoices', 'to_match'],
            ['/ui/purchasing/invoices/not-invoiced', [], 'purchasing', 'invoices', 'not_invoiced'],
            ['/ui/purchasing/credit-notes/12', [], 'purchasing', 'invoices', 'credit_notes'],
            ['/ui/purchasing/credit-notes/from-return', [], 'purchasing', 'invoices', 'credit_notes'],
            ['/ui/purchasing/returns/12', [], 'purchasing', 'returns', 'returns'],
            ['/ui/purchasing/returns/from-receipt', [], 'purchasing', 'returns', 'returns'],
            ['/ui/purchasing/supplier-items/12', [], 'purchasing', 'suppliers', 'suppliers'],
            ['/ui/system/audit', [], 'reports', 'audit', 'audit'],
            ['/ui/documents/reviews', [], 'approvals', 'reviews', 'reviews'],
            ['/ui/approvals', ['open' => 'corrections-12'], 'approvals', 'inbox', 'inbox'],
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
        self::assertNull(Sections::fit('/ui/review?queue=pending', '/ui/review', ['queue' => ['pending']]), 'a list is not a value: no match, no error');
    }

    /**
     * The owner's acceptance test (I-1 §4.4) on the new navigation: buyer, purchasing desk and reviewer see different menus (the
     * desk has Goods In and no Reorder; the reviewer also Reports and Documents to review); nobody sees a section with nothing for them.
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
        // Pack E (PE1): the store reports are for buyers and the desk too (reports.view); Sales (pack D) for the people who look at sales.
        self::assertSame(['Home', 'Products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], $sections(['buyer']));
        self::assertSame(['Home', 'Products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], $sections(['purchasing_desk']));
        self::assertSame(['Home', 'Products', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], $sections(['reviewer']));
        self::assertSame(['Reorder', 'Purchase orders', 'Invoices', 'Returns', 'Suppliers'], $tabs(['buyer'], 'purchasing'));
        self::assertSame(['Purchase orders', 'Goods in', 'Invoices', 'Returns', 'Suppliers'], $tabs(['purchasing_desk'], 'purchasing'), 'no Reorder for the desk');
        self::assertSame(['Reorder', 'Purchase orders', 'Goods in', 'Invoices', 'Returns', 'Suppliers'], $tabs(['reviewer'], 'purchasing'));
        // Pack C: goods in and the stock controller read returns (they pack the goods), not invoices (money).
        self::assertSame(['Purchase orders', 'Goods in', 'Returns', 'Suppliers'], $tabs(['goods_in'], 'purchasing'));
        // Pack F (PF5): From the stores, for everyone who reads records.
        self::assertSame(['History', 'From the stores'], $tabs(['buyer'], 'approvals'));
        self::assertSame(['Waiting for you', 'Documents to review', 'History', 'Staff requests', 'From the stores'], $tabs(['reviewer'], 'approvals'));
        // Approvals › Waiting for you (AV1): for the people who answer any of its kinds, first; a buyer answers none.
        self::assertSame(['Waiting for you', 'History', 'From the stores'], $tabs(['purchasing_desk'], 'approvals'));
        self::assertSame(['Waiting for you', 'History'], $tabs(['warehouse'], 'approvals'), 'the count differences: a job without the stores\' queues');
        self::assertNotSame(Sections::menu(['buyer']), Sections::menu(['purchasing_desk']));
        self::assertNotSame(Sections::menu(['purchasing_desk']), Sections::menu(['reviewer']));
        // Pack B: Families and Multipacks for everyone who sees products; Add Item for the catalogue team. Matching has its own section (HV3).
        self::assertSame(['All products', 'Families', 'Multipacks'], $tabs(['buyer'], 'products'), 'no barcodes to decide for a buyer');
        self::assertSame(['All products', 'Barcodes', 'Add item', 'Families', 'Multipacks'], $tabs(['stock_controller'], 'products'));
        self::assertSame(['All products', 'Families', 'Multipacks'], $tabs(['mapper'], 'products'));
        self::assertSame(['Simple matching', 'Mapping', 'Store products', 'By item', 'New variants', 'Duplicates'], $tabs(['mapper'], 'matching'));
        self::assertSame(['All products', 'Barcodes', 'Add item', 'Families', 'Multipacks'], $tabs(['mapping_lead'], 'products'));
        self::assertSame([], $tabs(['buyer'], 'matching'), 'no matching for a buyer');
        self::assertSame(['Overview', 'Movements', 'Reservations'], $tabs(['viewer'], 'stock'), 'everyone who sees a product\'s stock');
        // ---- Pack D (docs/decisions.md PD2): Sales is for the people who look at sales, not the matching jobs and not admin ----
        self::assertSame(['Store orders', 'Dispatch', 'Returns and cancels'], $tabs(['warehouse'], 'sales'), 'its three tabs (HV3)');
        self::assertSame(['Store orders', 'Dispatch', 'Returns and cancels'], $tabs(['viewer'], 'sales'));
        self::assertSame([], $tabs(['mapper'], 'sales'));
        self::assertSame([], $tabs(['admin'], 'sales'));
        // ---- end of pack D ----

        // Admin: people and settings, no buying and no approvals (I12); Reports for the audit log.
        self::assertSame(['Home', 'Products', 'Matching', 'Stock', 'Reports', 'Settings'], $sections(['admin']));
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Users', 'Approval rules', 'Reasons', 'System'], $tabs(['admin'], 'settings'));
        self::assertSame(['Home', 'Products', 'Matching', 'Stock', 'Settings'], $sections(['mapper']), 'no Reports without the audit log');
        self::assertSame(['Company', 'Warehouses', 'Users', 'Approval rules', 'Reasons', 'System'], $tabs(['buyer'], 'settings'),
            'Users opens "Roles and permissions" for people who do not manage staff; no Stores without system.view');
        self::assertSame(['Home', 'Products', 'Matching', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], $sections(['auditor']));
        self::assertSame(['Home', 'Products', 'Matching', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Approvals', 'Settings'], $sections(['mapping_lead', 'reviewer']),
            'the owner\'s daily view');
        self::assertSame([], Sections::menu([]), 'no roles, no menu');

        // A tab links to the first page the person may open.
        $users = static fn (array $roles): ?string => array_column(array_column(Sections::menu($roles), 'items', 'key')['settings'], 'path', 'key')['people'] ?? null;
        self::assertSame('/ui/people', $users(['admin']));
        self::assertSame('/ui/reference/access', $users(['buyer']));
    }

    /**
     * The sidebar of the approved HM_Home (docs/decisions.md HV2): Home, then "Every day" (Goods in, Counts, Approvals, Purchase orders, All
     * products), then "More" (Stock, Purchasing, Sales, Matching, Reports, Settings), each only for the people who may open it; ONE entry is
     * current (the daily entry of the page's tab, else its section's); a thing waiting is counted once (a daily tab on its own entry, not
     * again on its section's); the phone bar (HM_Phone); the bell leads to the inbox with the inbox's count (HV4).
     */
    public function testTheSidebarIsHomeEveryDayAndMore(): void
    {
        $owner = ['mapping_lead', 'reviewer'];
        $groups = static function (array $f): array {
            $out = [];
            foreach ($f['nav'] as $item) {
                $out[$item['group']][] = $item['label'];
            }
            return $out;
        };
        $f = Sections::frame($owner, [], '/ui/');
        self::assertSame(['home' => ['Home'], 'daily' => ['Goods in', 'Counts', 'Approvals', 'Purchase orders', 'All products'],
            'more' => ['Stock', 'Purchasing', 'Sales', 'Matching', 'Reports', 'Settings']], $groups($f), 'the owner\'s menu: 12 entries, was 7 sections and 46 tabs');
        $by = array_column($f['nav'], null, 'key');
        self::assertSame(['/ui/', '/ui/receiving', '/ui/stock/counts', '/ui/approvals', '/ui/purchasing/orders', '/ui/items/cards'],
            [$by['home']['href'], $by['goods_in']['href'], $by['counts']['href'], $by['approvals']['href'], $by['orders']['href'], $by['products']['href']]);
        self::assertSame(['/ui/stock', '/ui/purchasing/reorder', '/ui/stock/sales', '/ui/match', '/ui/reports/stock-by-store', '/ui/reference/company'],
            [$by['stock']['href'], $by['purchasing']['href'], $by['sales']['href'], $by['matching']['href'], $by['reports']['href'], $by['settings']['href']],
            'a section opens its first tab');
        self::assertSame(['home', 'goods-in', 'counts', 'approvals', 'orders', 'products', 'stock', 'purchasing', 'sales', 'matching', 'reports', 'settings'],
            array_column($f['nav'], 'icon'), 'the sprite\'s names');
        self::assertSame(['home'], array_keys(array_filter(array_column($f['nav'], 'current', 'key'))));
        self::assertSame(['Home', 'Goods in', 'Counts', 'Approvals', 'More'], array_column($f['phone'], 'label'), 'HM_Phone');
        self::assertSame(['/ui/', '/ui/receiving', '/ui/stock/counts', '/ui/approvals', '#menu'], array_column($f['phone'], 'href'));
        self::assertTrue($f['phone'][0]['current']);

        // Each only for the people who may open it.
        self::assertSame(['home' => ['Home'], 'daily' => ['Counts', 'Approvals', 'Purchase orders', 'All products'],
            'more' => ['Stock', 'Purchasing', 'Sales', 'Reports', 'Settings']], $groups(Sections::frame(['buyer'], [], '/ui/')), 'a buyer has no Goods In and no matching');
        self::assertSame(['home' => ['Home'], 'daily' => ['All products'], 'more' => ['Stock', 'Matching', 'Reports', 'Settings']],
            $groups(Sections::frame(['admin'], [], '/ui/')), 'admin: no buying, no approvals (I12)');
        self::assertSame(['Home', 'Counts', 'Approvals', 'More'], array_column(Sections::frame(['buyer'], [], '/ui/')['phone'], 'label'));
        self::assertSame(['Home', 'More'], array_column(Sections::frame(['mapper'], [], '/ui/')['phone'], 'label'), 'only the entries the person has');
        self::assertSame([], Sections::frame([], [], '/ui/')['nav'], 'no roles, no menu');
        self::assertSame([], Sections::frame([], [], '/ui/')['phone'], 'no roles, no bar');

        // ONE current entry: the daily entry of the page's tab (on its records too), else the page's section.
        $current = static fn (array $roles, string $path, array $query = []): array
            => array_keys(array_filter(array_column(Sections::frame($roles, [], $path, $query)['nav'], 'current', 'key')));
        foreach ([['/ui/receiving', [], 'goods_in'], ['/ui/receiving/12/bench', [], 'goods_in'], ['/ui/receiving/arriving', [], 'goods_in'],
            ['/ui/purchasing/orders/12', [], 'orders'], ['/ui/purchasing/orders/new', [], 'orders'], ['/ui/purchasing/invoices', [], 'purchasing'],
            ['/ui/purchasing/reorder', [], 'purchasing'], ['/ui/stock/counts/sheets', [], 'counts'], ['/ui/stock/counts/12', [], 'counts'], ['/ui/stock', [], 'stock'],
            ['/ui/stock/handovers', [], 'stock'], ['/ui/approvals', [], 'approvals'], ['/ui/documents/12', [], 'approvals'], ['/ui/queues/landing', [], 'approvals'],
            ['/ui/items/cards', [], 'products'], ['/ui/items/12', [], 'products'], ['/ui/items/families', [], 'products'], ['/ui/search', ['q' => 'x'], 'products'],
            ['/ui/review', ['queue' => 'pending'], 'matching'], ['/ui/match', [], 'matching'], ['/ui/stock/sales/dispatch', [], 'sales'],
            ['/ui/system/audit', [], 'reports'], ['/ui/system/checks', [], 'settings'], ['/ui/', [], 'home']] as [$path, $query, $key]) {
            self::assertSame([$key], $current($owner, $path, $query), $path);
        }
        self::assertSame(['purchasing'], $current(['buyer'], '/ui/receiving'), 'a page of a tab the person does not have: its section');
        self::assertSame([], $current(['buyer'], '/ui/review', ['queue' => 'Key']), 'a page of a section the person does not have: none');
        self::assertSame([], $current($owner, '/ui/password'));
        self::assertSame(['goods_in'], array_keys(array_filter(array_column(Sections::frame($owner, [], '/ui/receiving')['phone'], 'current', 'key'))),
            'the phone bar marks the same entry');
        self::assertSame([], array_filter(array_column(Sections::frame($owner, [], '/ui/stock')['phone'], 'current')), 'a page under More: no bar entry is current');

        // A thing waiting is counted once: a daily tab on its own entry, not again on its section's; Approvals counts its inbox (AV10).
        $badges = ['incidents_open' => 2, 'arrivals_late' => 1, 'invoices_to_match' => 4, 'counts_open' => 3, 'stock_in_open' => 5, 'approvals_inbox' => 6,
            'reviews_open' => 9, 'barcodes_open' => 7, 'linking_pending' => 8, 'linking_duplicates' => 1];
        $counts = array_column(Sections::frame(['stock_controller', 'purchasing_desk', 'mapping_lead'], $badges, '/ui/')['nav'], 'count', 'key');
        self::assertSame(3, $counts['goods_in'], 'the issues and the late arrivals of Goods In');
        self::assertSame(4, $counts['purchasing'], 'Purchasing: the invoices to match, not Goods In\'s again');
        self::assertSame(3, $counts['counts']);
        self::assertSame(5, $counts['stock'], 'Stock: its records, not the counts again');
        self::assertSame(6, $counts['approvals'], 'the inbox, once');
        self::assertSame(7, $counts['products'], 'the barcodes to check');
        self::assertSame(9, $counts['matching'], 'the second approvals and the duplicates');
        self::assertSame(Words::BADGE['section'], array_column(Sections::frame(['stock_controller'], $badges, '/ui/')['nav'], 'countWords', 'key')['goods_in'],
            'two kinds counted: "waiting for you"');
        self::assertSame(Words::BADGE['counts_open'], array_column(Sections::frame(['stock_controller'], $badges, '/ui/')['nav'], 'countWords', 'key')['counts'],
            'one kind: its own words');

        // The bell (HV4): Approvals › Waiting for you and its count, for the people who answer there; nobody else has one.
        self::assertSame(['href' => '/ui/approvals', 'query' => [], 'count' => 6, 'countWords' => Words::BADGE['approvals_inbox']],
            Sections::frame(['stock_controller'], $badges, '/ui/stock')['bell']);
        self::assertNull(Sections::frame(['buyer'], $badges, '/ui/')['bell'], 'a buyer answers nothing there');
        self::assertNull(Sections::frame(['admin'], $badges, '/ui/')['bell']);
        foreach (\CW\Auth\Permissions::ROLES as $role) {
            self::assertSame(Sections::may([$role], \CW\Ui\Controller\InboxController::PERMS), Sections::frame([$role], [], '/ui/')['bell'] !== null, $role);
        }
    }

    /**
     * A section's set-up, import and specialist pages under its tab row's More (docs/decisions.md HV3): the row holds the section's daily
     * tabs, then the current one when it is a More one; More holds the rest the person may open, with their count; a person with none of
     * the row's tabs sees the others in the row. Sales carries the store chosen through its three tabs.
     */
    public function testTheTabRowsMoreHoldsTheSetUpAndSpecialistPages(): void
    {
        $row = static fn (array $f): array => array_map(static fn (array $t): array => [$t['label'], $t['current']], $f['pagebar']['tabs']);
        $more = static fn (array $f): array => array_column($f['pagebar']['more'], 'label');
        $f = Sections::frame(['stock_controller'], ['barcodes_open' => 2], '/ui/items/cards');
        self::assertSame([['All products', true], ['Barcodes', false]], $row($f));
        self::assertSame(['Add item', 'Families', 'Multipacks'], $more($f));
        self::assertSame(['/ui/items/new', '/ui/items/families', '/ui/items/packs'], array_column($f['pagebar']['more'], 'href'));
        self::assertSame(0, $f['pagebar']['moreCount']);
        $f = Sections::frame(['stock_controller'], [], '/ui/items/families/12');
        self::assertSame([['All products', false], ['Barcodes', false], ['Families', true]], $row($f), 'the page shown is in the row, after the row\'s own');
        self::assertSame(['Add item', 'Multipacks'], $more($f));
        self::assertSame([['All products', true]], $row(Sections::frame(['buyer'], [], '/ui/items/cards')));
        self::assertSame(['Families', 'Multipacks'], $more(Sections::frame(['buyer'], [], '/ui/items/cards')));

        // Matching: the daily three in the row; By Item, New Variants and Duplicates under More, its count theirs.
        $f = Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/match');
        self::assertSame([['Simple matching', true], ['Mapping', false], ['Store products', false]], $row($f));
        self::assertSame(['By item', 'New variants', 'Duplicates'], $more($f));
        self::assertSame([0, 0, 2], array_column($f['pagebar']['more'], 'count'));
        self::assertSame(2, $f['pagebar']['moreCount']);
        self::assertSame(['Matching', Words::SECTION_DESC['matching']], [$f['pagebar']['label'], $f['pagebar']['desc']]);

        // Stock: the records in the row; the hand-overs, transfers, reservations, Quality (not built yet) and repacks under More.
        $f = Sections::frame(['buyer'], [], '/ui/stock');
        self::assertSame([['Overview', true], ['Movements', false], ['Stock in', false], ['Stock out', false], ['Adjustments', false], ['Counts', false]], $row($f));
        self::assertSame(['Hand-overs', 'Transfers', 'Reservations', 'Quality', 'Repack'], $more($f));
        self::assertSame([false, false, false, true, false], array_column($f['pagebar']['more'], 'soon'));
        self::assertSame([['Overview', false], ['Movements', false], ['Reservations', true]], $row(Sections::frame(['viewer'], [], '/ui/stock/reservations/12')));
        self::assertSame([], $more(Sections::frame(['viewer'], [], '/ui/stock/reservations/12')), 'nothing else for a viewer: a More of Quality (not built yet) alone is not drawn');
        self::assertSame(['Reservations', 'Quality'], $more(Sections::frame(['viewer'], [], '/ui/stock')));

        // Reports: the audit log under More; admin, who has only it, sees it in the row.
        self::assertSame(['Audit log'], $more(Sections::frame(['reviewer'], [], '/ui/reports/low-stock')));
        $f = Sections::frame(['admin'], [], '/ui/system/audit');
        self::assertSame([['Audit log', true]], $row($f));
        self::assertSame([], $more($f));
        // Purchasing, Approvals and Settings keep every tab in the row.
        foreach ([['reviewer', '/ui/purchasing/orders'], ['reviewer', '/ui/approvals'], ['admin', '/ui/reference/company']] as [$role, $path]) {
            self::assertSame([], $more(Sections::frame([$role], [], $path)), $path);
        }

        // Sales: its three tabs carry the store chosen (a plain code only), on its own pages.
        $f = Sections::frame(['warehouse'], [], '/ui/stock/sales/dispatch', ['channel' => 'alt', 'show' => 'today']);
        self::assertSame([['Store orders', false], ['Dispatch', true], ['Returns and cancels', false]], $row($f));
        self::assertSame([['channel' => 'alt'], ['channel' => 'alt'], ['channel' => 'alt']], array_column($f['pagebar']['tabs'], 'query'));
        self::assertSame([false, true, false], array_column($f['pagebar']['tabs'], 'here'), 'the tab of the page shown has no link (CV13)');
        self::assertSame([[], [], []], array_column(Sections::frame(['warehouse'], [], '/ui/stock/sales', ['channel' => 'x"><b'])['pagebar']['tabs'], 'query'),
            'a value that is not a code is not carried');
        self::assertSame('/ui/stock/sales', array_column(Sections::frame(['warehouse'], [], '/ui/stock', ['channel' => 'alt'])['nav'], 'href', 'key')['sales'],
            'the sidebar\'s Sales carries nothing');
        self::assertSame([], Sections::frame(['warehouse'], [], '/ui/stock/sales')['segments'], 'tabs, not segments');
    }

    public function testTheFrameOfAPage(): void
    {
        // A mapping lead on Second approval: Matching is current, Mapping is the current tab, its segment too, all with the count.
        $f = Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/review', ['queue' => 'pending']);
        self::assertSame(['Home', 'All products', 'Stock', 'Matching', 'Settings'], array_column($f['nav'], 'label'));
        $matching = array_column($f['nav'], null, 'key')['matching'];
        self::assertSame([true, 5, '/ui/match'], [$matching['current'], $matching['count'], $matching['href']], 'the section counts everything waiting in it');
        self::assertSame('Matching', $f['pagebar']['label']);
        self::assertSame(Words::SECTION_DESC['matching'], $f['pagebar']['desc']);
        self::assertArrayNotHasKey('flow', $f['pagebar'], 'no buying-flow strip anywhere (DC5)');
        self::assertSame([['Simple matching', false, 0], ['Mapping', true, 3], ['Store products', false, 0]],
            array_map(static fn (array $t): array => [$t['label'], $t['current'], $t['count']], $f['pagebar']['tabs']));
        self::assertSame([['By item', 0], ['New variants', 0], ['Duplicates', 2]], array_map(static fn (array $t): array => [$t['label'], $t['count']], $f['pagebar']['more']));
        self::assertSame([['To review', false, 0, '/ui/review'], ['Spot check', false, 0, '/ui/review/samples'], ['Second approval', true, 3, '/ui/review']],
            array_map(static fn (array $s): array => [$s['label'], $s['current'], $s['count'], $s['href']], $f['segments']));
        self::assertSame([[], [], ['queue' => 'pending']], array_column($f['segments'], 'query'), 'To review opens the "By store" overview (U107)');
        // The store chosen travels through the segments (U106): a plain code only.
        $kept = Sections::frame(['mapping_lead'], [], '/ui/review', ['queue' => 'Check', 'channel' => 'electrofag']);
        self::assertSame([['channel' => 'electrofag'], ['channel' => 'electrofag'], ['queue' => 'pending', 'channel' => 'electrofag']],
            array_column($kept['segments'], 'query'));
        self::assertSame([[], [], ['queue' => 'pending']], array_column(Sections::frame(['mapping_lead'], [], '/ui/review', ['channel' => 'x"><b'])['segments'], 'query'),
            'a value that is not a code is not carried');
        self::assertSame([], Sections::frame(['mapping_lead'], [], '/ui/review/store', ['channel' => 'electrofag'])['segments'], 'Store Products has no segments');
        self::assertSame(['section' => 'matching', 'tab' => 'store_products', 'page' => 'store_products'], Sections::locate('/ui/review/store', ['channel' => 'x']));
        // By Item (U113): under More; on its own pages it is the current tab in the row; its CSV and its picker belong to it, a website
        // product's page to Mapping.
        $byProduct = Sections::frame(['mapper'], [], '/ui/review/products/12/map', ['channel' => 'electrofag']);
        self::assertSame(['By item'], array_column(array_filter($byProduct['pagebar']['tabs'], static fn (array $t): bool => $t['current']), 'label'));
        self::assertSame('/ui/review/products', array_column($byProduct['pagebar']['tabs'], 'href', 'label')['By item']);
        self::assertSame([], $byProduct['segments'], 'By Item has no segments');
        // New Variants (U131): its CSV belongs to it, the bulk action and its result page to Mapping.
        $newVariants = Sections::frame(['mapper'], [], '/ui/review/new-variants', ['channel' => 'electrofag']);
        self::assertSame(['New variants'], array_column(array_filter($newVariants['pagebar']['tabs'], static fn (array $t): bool => $t['current']), 'label'));
        self::assertSame('/ui/review/new-variants', array_column($newVariants['pagebar']['tabs'], 'href', 'label')['New variants']);
        self::assertSame([], $newVariants['segments'], 'New Variants has no segments');
        self::assertSame(['section' => 'matching', 'tab' => 'mapping', 'page' => 'review'], Sections::locate('/ui/review/bulk'));
        self::assertSame(['section' => 'matching', 'tab' => 'mapping', 'page' => 'review'],
            Sections::locate('/ui/review/listing/7', ['pick' => '3', 'via' => 'product', 'product' => '3', 'channel' => 'electrofag']));
        self::assertSame(['section' => 'matching', 'tab' => 'mapping', 'page' => 'review'], Sections::locate('/ui/review/batches/7'));
        // The batch page of a bulk action started on By Item (U127) keeps the By Item tab; a value that is no plain value names nothing.
        self::assertSame(['section' => 'matching', 'tab' => 'by_product', 'page' => 'by_product'], Sections::locate('/ui/review/batches/7', ['from' => 'item']));
        self::assertSame(['section' => 'matching', 'tab' => 'mapping', 'page' => 'review'], Sections::locate('/ui/review/batches/7', ['from' => ['item']]));
        self::assertSame(Words::BADGE['linking_pending'], $f['segments'][2]['countWords']);
        self::assertSame([], $f['actions'], 'nothing to create on Mapping');
        self::assertSame(['Home', 'More'], array_column($f['phone'], 'label'));
        self::assertSame('#menu', $f['phone'][1]['href']);

        // Stock: the tab not built yet (Quality) is drawn without a link (under More); Counts is built (pack A2a) and open to the people who
        // read records; Reservations (read only) is for everyone who sees the stock.
        $f = Sections::frame(['buyer'], [], '/ui/stock');
        self::assertSame([['Overview', '/ui/stock', true, false], ['Movements', '/ui/stock/movements', false, false], ['Stock in', '/ui/stock/in', false, false],
            ['Stock out', '/ui/stock/out', false, false], ['Adjustments', '/ui/stock/adjustments', false, false], ['Counts', '/ui/stock/counts', false, false]],
            array_map(static fn (array $t): array => [$t['label'], $t['href'], $t['current'], $t['soon']], $f['pagebar']['tabs']));
        self::assertSame([['Hand-overs', '/ui/stock/handovers', false], ['Transfers', '/ui/stock/transfers', false], ['Reservations', '/ui/stock/reservations', false],
            ['Quality', null, true], ['Repack', '/ui/stock/repack', false]],
            array_map(static fn (array $t): array => [$t['label'], $t['href'], $t['soon']], $f['pagebar']['more']));
        self::assertSame([], $f['segments']);
        self::assertSame(['Home', 'Counts', 'Approvals', 'More'], array_column($f['phone'], 'label'), 'the phone bar of HM_Phone');
        $f = Sections::frame(['viewer'], [], '/ui/stock/reservations/12', ['channel' => ['x']]);
        self::assertSame(['Reservations'], array_column(array_filter($f['pagebar']['tabs'], static fn (array $t): bool => $t['current']), 'label'), 'one reservation keeps its tab');
        self::assertSame([[], []], [$f['segments'], $f['actions']], 'no segments, and nothing to create: the screen only shows');

        // The create button: on the list itself, for the people who may create, never on a detail page.
        // PO on one page (docs/decisions.md PX1): "New purchase order" opens the page itself, not a fold of the list.
        self::assertSame([['label' => 'New purchase order', 'href' => '/ui/purchasing/orders/new']],
            Sections::frame(['buyer'], [], '/ui/purchasing/orders')['actions'], 'the page\'s own only: no second More of the section\'s others (DC4)');
        self::assertSame(['New purchase order'], array_column(Sections::frame(['purchasing_manager'], [], '/ui/purchasing/orders')['actions'], 'label'));
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/reorder')['actions'], 'no create action of its own: no toolbar button');
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/orders/12')['actions']);
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/orders/new')['actions'], 'the new order\'s page has no create button of its own');
        self::assertSame('orders', Sections::frame(['buyer'], [], '/ui/purchasing/orders/new')['here']['tab'] ?? null, 'it keeps Purchase Orders marked');
        self::assertSame([], Sections::frame(['reviewer'], [], '/ui/purchasing/orders')['actions'], 'a reviewer does not order');
        // The supplier invoice on one page (docs/decisions.md PV): "New supplier invoice" opens the page itself, not a fold of the list.
        self::assertSame(['label' => 'New supplier invoice', 'href' => '/ui/purchasing/invoices/new'], Sections::frame(['purchasing_desk'], [], '/ui/purchasing/invoices')['actions'][0] ?? null);
        self::assertSame([], Sections::frame(['purchasing_desk'], [], '/ui/purchasing/invoices/new')['actions'], 'the new invoice\'s page has no create button of its own');
        self::assertSame(['invoices', 'invoices'], [Sections::frame(['purchasing_desk'], [], '/ui/purchasing/invoices/new')['here']['tab'] ?? null,
            Sections::frame(['purchasing_desk'], [], '/ui/purchasing/invoices/new')['here']['page'] ?? null], 'it keeps Invoices marked');
        self::assertSame([['label' => 'New delivery', 'href' => '/ui/receiving/new']], Sections::frame(['goods_in'], [], '/ui/receiving')['actions']);
        self::assertSame([], Sections::frame(['goods_in'], [], '/ui/receiving/new')['actions'], 'the start page has no create button of its own (GV13)');
        self::assertSame(Sections::frame(['goods_in'], [], '/ui/receiving')['here']['tab'] ?? null, Sections::frame(['goods_in'], [], '/ui/receiving/new')['here']['tab'] ?? null,
            'it keeps Goods In marked');
        self::assertSame(['/ui/people#new'], array_column(Sections::frame(['admin'], [], '/ui/people')['actions'], 'href'));
        self::assertSame([], Sections::frame(['auditor'], [], '/ui/people')['actions']);
        self::assertSame(['To receive', 'Checking', 'Issues', 'Duty register', 'Arriving'], array_column(Sections::frame(['goods_in'], ['incidents_open' => 1], '/ui/receiving/12/bench')['segments'], 'label')); // Pack J: Arriving
        self::assertTrue(Sections::frame(['goods_in'], [], '/ui/receiving/12/bench')['segments'][1]['current'], 'a delivery\'s bench check is Checking');
        self::assertSame(['To receive', 'Issues', 'Duty register', 'Arriving'], array_column(Sections::frame(['reviewer'], [], '/ui/receiving')['segments'], 'label'), 'the bench is for the people who receive'); // Pack J: Arriving

        // Home has no page bar (its title is the page's own) and no create button (HV1: its main action is the first job); a page
        // outside every section has none either.
        self::assertNull(Sections::frame(['buyer'], [], '/ui/')['pagebar']);
        self::assertSame([], Sections::frame(['purchasing_manager'], [], '/ui/')['actions']);
        self::assertTrue(array_column(Sections::frame(['buyer'], [], '/ui/')['nav'], null, 'key')['home']['current']);
        $f = Sections::frame(['buyer'], [], '/ui/password');
        self::assertNull($f['pagebar']);
        self::assertSame([], array_filter(array_column($f['nav'], 'current')));

        // A page the person cannot open (403) keeps its section when they have the section, without a current tab.
        $f = Sections::frame(['buyer'], [], '/ui/receiving/bench');
        self::assertSame('Purchasing', $f['pagebar']['label']);
        self::assertSame([], array_filter(array_column($f['pagebar']['tabs'], 'current')));
        self::assertSame([], $f['segments']);
        self::assertNull(Sections::frame(['buyer'], [], '/ui/review', ['queue' => 'Key'])['pagebar'], 'no Matching for a buyer: no page bar');
        self::assertNull(Sections::frame(['mapper'], [], '/ui/system/audit')['pagebar'], 'no Reports for a matcher: no page bar');

        // Admin's phone bar: none of the three daily pages, so Home and More.
        self::assertSame(['Home', 'More'], array_column(Sections::frame(['admin'], [], '/ui/')['phone'], 'label'));
        self::assertSame([], Sections::frame([], [], '/ui/')['phone'], 'no roles, no bar');
    }
}
