<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\Html;
use CW\Ui\Kernel;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The navigation map (Ui\Sections) of the approved redesign (Sidebar.dc.html, Phone.dc.html, 30 Oct 2026; docs/decisions.md RD1-RD12):
 * every page it links is a live GET route guarded by exactly the permission the map names, every staff page belongs to one section and
 * entry (detail pages included), each name comes from Words, the sidebar is the artboard's tree (seven sections between Home and
 * Approvals and Reports and Settings, only the current one open, two levels only), the pages that belong together are one Show, Store
 * listings' other matching pages are under the header's More, the phone bar is Home, Receive, Count, Approvals, More, and EVERY page a
 * person may open is reachable from Home through the sidebar, a Show or a More, for every role. Pure: no database.
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
        self::assertSame(['home', 'approvals', 'items', 'inventory', 'purchases', 'sales', 'listings', 'reports', 'settings'], array_keys(Sections::MAP));
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Store listings', 'Reports', 'Settings'], array_values(Words::SECTION),
            'the standard inventory names of Sidebar.dc.html, in its order');
        self::assertSame(['items', 'reports'], array_keys(array_filter(Sections::MAP, static fn (array $s): bool => ($s['gap'] ?? false) === true)),
            'a divider above Items and above Reports');
        self::assertSame(['home', 'receive', 'count', 'approvals'], array_keys(Sections::PHONE));
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_values(Words::NAV), 'Phone.dc.html');
        foreach (Sections::PHONE as $key => [$s, $t]) {
            self::assertArrayHasKey($s, Sections::MAP, $key);
            self::assertTrue($t === null || isset(Sections::MAP[$s]['tabs'][$t]), $key);
        }
        foreach ([...array_keys(Sections::MAP), ...array_keys(Sections::PHONE), 'more'] as $key) {
            self::assertArrayHasKey($key, Sections::ICON, "{$key}: its icon");
        }
        foreach (Sections::MAP as $s => $section) {
            self::assertSame([], array_diff(array_keys($section), ['tabs', 'count', 'gap']), $s);
            if (isset($section['count'])) {
                $keys = array_merge(...array_map(static fn (array $t): array => array_map(static fn (array $p): string => (string) ($p['key'] ?? ''), $t['pages']),
                    array_values($section['tabs'])));
                self::assertContains($section['count'], $keys, "{$s}: the page whose count is the section's (AV10)");
            }
            foreach ($section['tabs'] as $t => $tab) {
                $what = "{$s} / {$t}";
                self::assertArrayHasKey($t, Words::MENU, "{$what}: its name");
                self::assertSame([], array_diff(array_keys($tab), ['pages', 'keep', 'more']), $what);
                self::assertArrayHasKey($t, Words::MENU_HELP, "{$what}: its line under the header's More");
                $keys = [];
                foreach ($tab['pages'] as $page) {
                    $key = (string) ($page['key'] ?? $t);
                    $keys[] = $key;
                    $where = "{$what} / {$key}";
                    self::assertSame([], array_diff(array_keys($page), ['perm', 'path', 'query', 'match', 'badge', 'key', 'new', 'lists']), $where);
                    self::assertArrayHasKey($page['path'], $gets, "{$where}: a real GET route");
                    foreach ($page['lists'] ?? [] as $list) {
                        // Another list of the same page (RD-S1): a real GET route, found as this page, behind the same permission.
                        self::assertArrayHasKey($list, $gets, "{$where}: {$list} a real GET route");
                        self::assertSame(['section' => $s, 'tab' => $t, 'page' => $key], Sections::locate($list), "{$where}: {$list} is this page");
                        self::assertSame($gets[$page['path']], $gets[$list], "{$where}: {$list} lets in the same people");
                    }
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
                    if (count($tab['pages']) > 1) {
                        self::assertArrayHasKey($key, Words::SEGMENT, "{$where}: its name in the Show");
                    }
                    if (isset($page['badge'])) {
                        self::assertArrayHasKey($page['badge'], Words::BADGE, "{$where}: the words a screen reader hears");
                    }
                    if (isset($page['new'])) {
                        self::assertArrayHasKey($page['new']['perm'], Permissions::MAP, $where);
                        self::assertArrayHasKey($page['new']['label'], Words::NEW, $where);
                        $to = Sections::locate((string) parse_url($page['new']['href'], PHP_URL_PATH));
                        self::assertSame([$s, $t], [$to['section'] ?? null, $to['tab'] ?? null], "{$where}: the create button leads to a form of its own entry");
                    }
                    // The page's own link belongs to it.
                    self::assertSame(['section' => $s, 'tab' => $t, 'page' => $key], Sections::locate($page['path'], $page['query'] ?? []), "{$where}: its link");
                }
                self::assertSame(array_values(array_unique($keys)), $keys, "{$what}: a key marks one page");
            }
        }
        self::assertSame(['approvals_inbox', 'reviews_open', 'queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold', 'queue_landing', 'barcodes_open',
            'adjustments_open', 'stock_in_open', 'stock_out_open', 'releases_open', 'transfers_open', 'counts_open', 'incidents_open', 'arrivals_late', 'invoices_to_match',
            'invoices_timed_failed', 'linking_pending', 'linking_duplicates'], Sections::badgeNames());
    }

    /** Every staff page belongs to one section, entry and page: the current ones stay marked on a detail page (with its back link). */
    public function testEveryStaffPageBelongsToItsSectionAndEntry(): void
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
            ['/ui', [], 'home', 'home', 'home'],
            ['/ui/', [], 'home', 'home', 'home'],
            // Approvals: one entry, its pages Show choices.
            ['/ui/approvals', ['open' => 'corrections-12'], 'approvals', 'inbox', 'inbox'],
            ['/ui/documents/reviews', [], 'approvals', 'inbox', 'reviews'],
            ['/ui/documents/12', [], 'approvals', 'inbox', 'documents'],
            ['/ui/files/12', [], 'approvals', 'inbox', 'documents'],
            ['/ui/staff-requests', [], 'approvals', 'inbox', 'staff_requests'],
            ['/ui/queues/modes', [], 'approvals', 'inbox', 'queue_modes'],
            ['/ui/queues/landing/12', [], 'approvals', 'inbox', 'queue_landing'],
            // Items
            ['/ui/items/cards', [], 'items', 'cards', 'cards'],
            ['/ui/items/cards/import', [], 'items', 'cards', 'cards'],
            ['/ui/items/12', [], 'items', 'cards', 'cards'],
            ['/ui/items/12/card', [], 'items', 'cards', 'cards'],
            ['/ui/search', ['q' => 'x'], 'items', 'cards', 'cards'],
            ['/ui/items/new', [], 'items', 'cards', 'cards'],
            ['/ui/items/new', ['show' => 'all'], 'items', 'cards', 'by_hand'],
            ['/ui/items/new', ['show' => 'waiting'], 'items', 'cards', 'by_hand'],
            ['/ui/items/families/12', [], 'items', 'families', 'families'],
            ['/ui/items/packs/12', [], 'items', 'multipacks', 'packs'],
            ['/ui/items/packs/links', [], 'items', 'multipacks', 'pack_links'],
            ['/ui/items/barcodes', [], 'items', 'barcodes', 'barcodes'],
            // Inventory
            ['/ui/stock', [], 'inventory', 'stock', 'stock'],
            ['/ui/stock/movements', ['type' => 'goods_in'], 'inventory', 'movements', 'movements'],
            ['/ui/stock/adjustments/12', [], 'inventory', 'adjustments', 'adjustments'],
            ['/ui/stock/in/12', [], 'inventory', 'adjustments', 'stock_in'],
            ['/ui/stock/in.csv', [], 'inventory', 'adjustments', 'stock_in'],
            ['/ui/stock/out/12/add', [], 'inventory', 'adjustments', 'stock_out'],
            ['/ui/stock/releases/12', [], 'inventory', 'adjustments', 'releases'],
            ['/ui/stock/transfers/12/pdf', [], 'inventory', 'transfers', 'transfers'],
            ['/ui/stock/accounts', [], 'inventory', 'transfers', 'accounts'],
            ['/ui/stock/counts', [], 'inventory', 'counts', 'counts'],
            ['/ui/stock/counts/12', [], 'inventory', 'counts', 'count_sheets'],
            ['/ui/stock/counts/differences', [], 'inventory', 'counts', 'count_differences'],
            ['/ui/stock/repack/12/post', [], 'inventory', 'repack', 'repack'],
            ['/ui/stock/reservations', ['channel' => 'vapeandgo', 'state' => 'held'], 'inventory', 'reservations', 'reservations'],
            ['/ui/stock/reservations/12', [], 'inventory', 'reservations', 'reservations'],
            // Purchases
            ['/ui/purchasing/suppliers/12/items', [], 'purchases', 'suppliers', 'suppliers'],
            ['/ui/purchasing/supplier-items/12', [], 'purchases', 'suppliers', 'suppliers'],
            ['/ui/purchasing/orders/12', [], 'purchases', 'orders', 'orders'],
            ['/ui/purchasing/orders/new', [], 'purchases', 'orders', 'orders'],
            ['/ui/receiving', [], 'purchases', 'goods_in', 'receiving'],
            ['/ui/receiving/12', [], 'purchases', 'goods_in', 'receiving'],
            ['/ui/receiving/bench', [], 'purchases', 'goods_in', 'bench'],
            ['/ui/receiving/12/bench', [], 'purchases', 'goods_in', 'bench'],
            ['/ui/receiving/incidents', [], 'purchases', 'goods_in', 'incidents'],
            ['/ui/receiving/duty.csv', [], 'purchases', 'goods_in', 'duty'],
            ['/ui/receiving/arriving', [], 'purchases', 'goods_in', 'arriving'],
            ['/ui/purchasing/invoices', ['q' => 'x'], 'purchases', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/12', [], 'purchases', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/new', [], 'purchases', 'invoices', 'invoices'],
            ['/ui/purchasing/invoices/to-match', ['show' => 'accepted'], 'purchases', 'invoices', 'to_match'],
            ['/ui/purchasing/invoices/not-invoiced', [], 'purchases', 'invoices', 'not_invoiced'],
            ['/ui/purchasing/invoices/timed', [], 'purchases', 'invoices', 'timed'],
            ['/ui/purchasing/returns/12', [], 'purchases', 'returns', 'returns'],
            ['/ui/purchasing/credit-notes/12', [], 'purchases', 'returns', 'credit_notes'],
            ['/ui/purchasing/credit-notes/from-return', [], 'purchases', 'returns', 'credit_notes'],
            ['/ui/purchasing/reorder', [], 'purchases', 'reorder', 'reorder'],
            ['/ui/purchasing/reorder/items/12', [], 'purchases', 'reorder', 'reorder'],
            ['/ui/purchasing/reorder/brands', [], 'purchases', 'reorder', 'brands'],
            ['/ui/purchasing/reorder/anomalies/12/end', [], 'purchases', 'reorder', 'anomalies'],
            ['/ui/purchasing/sales-history', [], 'purchases', 'reorder', 'sales_history'],
            // Sales (pack D) and the trade sales (pack H)
            ['/ui/stock/sales', ['channel' => 'vpg', 'state' => 'paid'], 'sales', 'sales_orders', 'sales_orders'],
            ['/ui/stock/sales/12', ['unit' => 'u1'], 'sales', 'sales_orders', 'sales_orders'],
            ['/ui/stock/sales/dispatch', ['show' => 'today'], 'sales', 'sales_dispatch', 'sales_dispatch'],
            ['/ui/stock/sales/returns.csv', ['kind' => 'returned'], 'sales', 'sales_returns', 'sales_returns'],
            ['/ui/stock/handovers/12', [], 'sales', 'handovers', 'handovers'],
            ['/ui/stock/handovers/customers/12', [], 'sales', 'customers', 'handover_customers'],
            ['/ui/stock/handovers/balances', [], 'sales', 'customers', 'handover_balances'],
            // Store listings
            ['/ui/review/products', ['cov' => 'every'], 'listings', 'by_product', 'by_product'],
            ['/ui/review/products/12/map', ['channel' => 'x'], 'listings', 'by_product', 'by_product'],
            ['/ui/review/batches/12', ['from' => 'item', 'cov' => 'every'], 'listings', 'by_product', 'by_product'],
            ['/ui/review/batches/12', ['from' => 'store'], 'listings', 'review', 'review'],
            ['/ui/review', ['queue' => 'Key'], 'listings', 'review', 'review'],
            ['/ui/review/listing/12', ['queue' => 'Key'], 'listings', 'review', 'review'],
            ['/ui/review/decision/12/approve', [], 'listings', 'review', 'review'],
            ['/ui/review', ['queue' => 'pending'], 'listings', 'pending', 'pending'],
            ['/ui/review/samples/12', [], 'listings', 'samples', 'samples'],
            ['/ui/review/store', ['channel' => 'x'], 'listings', 'store_products', 'store_products'],
            ['/ui/match', [], 'listings', 'by_product', 'by_product'],
            ['/ui/review/new-variants.csv', [], 'listings', 'new_variants', 'new_variants'],
            ['/ui/review/duplicates/12', [], 'listings', 'duplicates', 'duplicates'],
            // Reports, Settings (the audit log is a Settings page, RD7)
            ['/ui/reports/stock-value', [], 'reports', 'stock_value', 'stock_value'],
            ['/ui/reports/slow-stock.csv', [], 'reports', 'slow_stock', 'slow_stock'],
            ['/ui/reference/company/edit', [], 'settings', 'settings', 'company'],
            ['/ui/reference/warehouses/12', [], 'settings', 'settings', 'warehouses'],
            ['/ui/system/sites', [], 'settings', 'settings', 'sites'],
            ['/ui/people/12', [], 'settings', 'settings', 'people'],
            ['/ui/reference/access', [], 'settings', 'settings', 'access'],
            ['/ui/reference/approvals', [], 'settings', 'settings', 'approvals'],
            ['/ui/reference/reasons/reason', ['code' => 'x'], 'settings', 'settings', 'reasons'],
            ['/ui/reference/settings/setting', ['key' => 'x'], 'settings', 'settings', 'settings'],
            ['/ui/reference/series', [], 'settings', 'settings', 'series'],
            ['/ui/system/checks', [], 'settings', 'settings', 'integrity'],
            ['/ui/system/audit', [], 'settings', 'settings', 'audit'],
        ];
        foreach ($expect as [$path, $query, $s, $t, $p]) {
            self::assertSame(['section' => $s, 'tab' => $t, 'page' => $p], Sections::locate($path, $query), $path . ' ' . json_encode($query));
        }
        self::assertNull(Sections::locate('/ui/password'));
        self::assertNull(Sections::fit('/ui/receiving/{id}/bench', '/ui/receiving/x/bench'), '{id} is a number');
        self::assertNull(Sections::fit('/ui/review?queue=pending', '/ui/review', ['queue' => 'Key']));
        self::assertNull(Sections::fit('/ui/review?queue=pending', '/ui/review', ['queue' => ['pending']]), 'a list is not a value: no match, no error');
    }

    /** Who sees what: the people of the owner's acceptance test (I-1 §4.4) see different menus; nobody sees a section with nothing for them. */
    public function testEachJobSeesItsOwnMenu(): void
    {
        $sections = static fn (array $roles): array => array_column(Sections::menu($roles), 'section');
        $entries = static function (array $roles, string $section): array {
            foreach (Sections::menu($roles) as $s) {
                if ($s['key'] === $section) {
                    return array_column(array_filter($s['items'], static fn (array $i): bool => !$i['more']), 'label');
                }
            }
            return [];
        };
        $all = ['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Reports', 'Settings'];
        self::assertSame($all, $sections(['buyer']));
        self::assertSame($all, $sections(['purchasing_desk']));
        self::assertSame($all, $sections(['reviewer']));
        self::assertSame(['Suppliers', 'Purchase orders', 'Purchase invoices', 'Purchase returns', 'Reorder'], $entries(['buyer'], 'purchases'));
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase invoices', 'Purchase returns'], $entries(['purchasing_desk'], 'purchases'), 'no Reorder for the desk');
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase invoices', 'Purchase returns', 'Reorder'], $entries(['reviewer'], 'purchases'));
        self::assertSame(['Suppliers', 'Purchase orders', 'Goods received', 'Purchase returns'], $entries(['goods_in'], 'purchases'), 'returns, not invoices (money)');
        self::assertSame(['Items', 'Item groups', 'Multipacks'], $entries(['buyer'], 'items'), 'no barcodes to decide for a buyer');
        self::assertSame(['Items', 'Item groups', 'Multipacks', 'Barcodes'], $entries(['stock_controller'], 'items'));
        self::assertSame(['Stock on hand', 'Adjustments', 'Transfers', 'Stock counts', 'Repack', 'Reserved stock', 'Stock history'], $entries(['buyer'], 'inventory'));
        self::assertSame(['Stock on hand', 'Reserved stock', 'Stock history'], $entries(['viewer'], 'inventory'), 'everyone who sees a product\'s stock');
        self::assertSame(['Sales orders', 'Shipments', 'Sales returns', 'Trade sales', 'Customers'], $entries(['warehouse'], 'sales'));
        self::assertSame(['Sales orders', 'Shipments', 'Sales returns'], $entries(['viewer'], 'sales'), 'the trade sales are records (documents.view)');
        self::assertSame(['By item', 'Check a match'], $entries(['mapper'], 'listings'));
        self::assertSame([], $entries(['buyer'], 'listings'), 'no matching for a buyer');
        self::assertSame(['Stock summary by store', 'Low stock', 'Inventory aging', 'Sales by store'], $entries(['buyer'], 'reports'), 'no money for a buyer');
        self::assertSame(['Inventory valuation summary', 'Stock summary by store', 'Low stock', 'Inventory aging', 'Sales by store'], $entries(['reviewer'], 'reports'));
        // Admin: people and settings, no buying, no sales and no approvals (I12); the audit log is a Settings page now (RD7).
        self::assertSame(['Home', 'Items', 'Inventory', 'Store listings', 'Settings'], $sections(['admin']));
        self::assertSame(['Home', 'Items', 'Inventory', 'Store listings', 'Settings'], $sections(['mapper']));
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Store listings', 'Reports', 'Settings'], $sections(['mapping_lead', 'reviewer']),
            'the owner\'s daily view: all nine');
        self::assertNotSame(Sections::menu(['buyer']), Sections::menu(['purchasing_desk']));
        self::assertNotSame(Sections::menu(['purchasing_desk']), Sections::menu(['reviewer']));
        self::assertSame([], Sections::menu([]), 'no roles, no menu');
        // An entry links to the first page the person may open: Settings opens Company; Approvals opens the inbox, or History for a buyer.
        $href = static fn (array $roles, string $s): ?string => array_column(Sections::frame($roles, [], '/ui/')['nav'], 'href', 'key')[$s] ?? null;
        self::assertSame('/ui/reference/company', $href(['admin'], 'settings'));
        self::assertSame('/ui/approvals', $href(['stock_controller'], 'approvals'));
        self::assertSame('/ui/documents', $href(['buyer'], 'approvals'), 'a buyer answers nothing: the history');
        self::assertSame('/ui/reports/stock-by-store', $href(['buyer'], 'reports'));
        self::assertSame('/ui/reports/stock-value', $href(['reviewer'], 'reports'));
    }

    /**
     * The sidebar of Sidebar.dc.html: one row per section the person has, in its order, a divider above Items and Reports; ONE current
     * section, tinted, the only one that shows its entries (never its specialist pages); Home, Approvals and Settings have none; the counts
     * are what waits for this person (Approvals: its inbox, once); the phone bar (Phone.dc.html) and the bell.
     */
    public function testTheSidebarIsTheApprovedTree(): void
    {
        $owner = ['mapping_lead', 'reviewer'];
        $f = Sections::frame($owner, [], '/ui/');
        self::assertSame(['Home', 'Approvals', 'Items', 'Inventory', 'Purchases', 'Sales', 'Store listings', 'Reports', 'Settings'], array_column($f['nav'], 'label'));
        self::assertSame(['items', 'reports'], array_column(array_filter($f['nav'], static fn (array $n): bool => $n['gap']), 'key'));
        self::assertSame(['/ui/', '/ui/approvals', '/ui/items/cards', '/ui/stock', '/ui/purchasing/suppliers', '/ui/stock/sales', '/ui/review/products',
            '/ui/reports/stock-value', '/ui/reference/company'], array_column($f['nav'], 'href'), 'a section opens its first entry');
        self::assertSame(['sec-home', 'sec-approvals', 'sec-items', 'sec-inventory', 'sec-purchases', 'sec-sales', 'sec-listings', 'sec-reports', 'sec-settings'],
            array_column($f['nav'], 'icon'));
        self::assertSame(['home'], array_keys(array_filter(array_column($f['nav'], 'current', 'key'))));
        self::assertSame([], array_merge(...array_column($f['nav'], 'subs')), 'Home: no section shows its entries');
        self::assertSame(['Home', 'Receive', 'Count', 'Approvals', 'More'], array_column($f['phone'], 'label'), 'Phone.dc.html');
        self::assertSame(['/ui/', '/ui/receiving', '/ui/stock/counts', '/ui/approvals', '#menu'], array_column($f['phone'], 'href'));
        self::assertSame(['sec-home', 'receive', 'count', 'sec-approvals', 'dots'], array_column($f['phone'], 'icon'));
        self::assertTrue($f['phone'][0]['current']);

        // Each section's entries, only on its own pages: the current one marked (on its records too), never a third level.
        $tree = static function (array $roles, string $path, array $query = []): array {
            $out = [];
            foreach (Sections::frame($roles, [], $path, $query)['nav'] as $n) {
                if ($n['subs'] !== []) {
                    $out[$n['label']] = array_map(static fn (array $s): string => $s['label'] . ($s['current'] ? '*' : ''), $n['subs']);
                }
            }
            return $out;
        };
        self::assertSame(['Items' => ['Items*', 'Item groups', 'Multipacks', 'Barcodes']], $tree($owner, '/ui/items/12'));
        self::assertSame(['Inventory' => ['Stock on hand', 'Adjustments*', 'Transfers', 'Stock counts', 'Repack', 'Reserved stock', 'Stock history']],
            $tree($owner, '/ui/stock/releases/12'), 'a release is an Adjustments page (RD4)');
        self::assertSame(['Purchases' => ['Suppliers', 'Purchase orders', 'Goods received*', 'Purchase invoices', 'Purchase returns', 'Reorder']],
            $tree($owner, '/ui/receiving/duty'));
        self::assertSame(['Sales' => ['Sales orders', 'Shipments', 'Sales returns', 'Trade sales', 'Customers*']], $tree($owner, '/ui/stock/handovers/balances'));
        self::assertSame(['Store listings' => ['By item', 'Check a match*']], $tree($owner, '/ui/review/listing/12'));
        self::assertSame(['Store listings' => ['By item*', 'Check a match']], $tree($owner, '/ui/match'), 'Simple matching is a tab of By item\'s page (RD-S1)');
        self::assertSame(['Store listings' => ['By item', 'Check a match']], $tree($owner, '/ui/review/store'), 'a specialist page: its section, no entry');
        self::assertSame(['Reports' => ['Inventory valuation summary', 'Stock summary by store', 'Low stock', 'Inventory aging*', 'Sales by store']],
            $tree($owner, '/ui/reports/slow-stock'));
        self::assertSame([], $tree($owner, '/ui/system/audit'), 'Settings: one entry, no sub-items');
        self::assertSame([], $tree($owner, '/ui/queues/modes'), 'Approvals: one entry, no sub-items');

        // ONE current section, the page's (none for a page outside every section or a section the person does not have).
        $current = static fn (array $roles, string $path, array $query = []): array
            => array_keys(array_filter(array_column(Sections::frame($roles, [], $path, $query)['nav'], 'current', 'key')));
        foreach ([['/ui/receiving/12/bench', 'purchases'], ['/ui/stock/counts/12', 'inventory'], ['/ui/documents/12', 'approvals'], ['/ui/search', 'items'],
            ['/ui/review', 'listings'], ['/ui/stock/sales/dispatch', 'sales'], ['/ui/stock/handovers', 'sales'], ['/ui/system/audit', 'settings'], ['/ui/', 'home']] as [$path, $key]) {
            self::assertSame([$key], $current($owner, $path), $path);
        }
        self::assertSame([], $current(['buyer'], '/ui/review', ['queue' => 'Key']), 'a page of a section the person does not have: none');
        self::assertSame([], $current($owner, '/ui/password'));
        // The phone bar marks the same place: Receive on a delivery's bench, Count on a count sheet; a page of another section, none.
        $phone = static fn (string $path): array => array_column(array_filter(Sections::frame($owner, [], $path)['phone'], static fn (array $p): bool => $p['current']), 'key');
        self::assertSame(['receive'], $phone('/ui/receiving/12/bench'));
        self::assertSame(['count'], $phone('/ui/stock/counts/12'));
        self::assertSame(['approvals'], $phone('/ui/queues/landing'));
        self::assertSame([], $phone('/ui/stock'));
        self::assertSame(['Home', 'More'], array_column(Sections::frame(['mapper'], [], '/ui/')['phone'], 'label'), 'only the entries the person has');
        self::assertSame(['Home', 'Count', 'Approvals', 'More'], array_column(Sections::frame(['buyer'], [], '/ui/')['phone'], 'label'));
        self::assertSame([], Sections::frame([], [], '/ui/')['nav'], 'no roles, no menu');
        self::assertSame([], Sections::frame([], [], '/ui/')['phone'], 'no roles, no bar');

        // A thing waiting is counted once, on its section and its entry; Approvals counts its inbox (AV10), not the inbox and its kinds again.
        $badges = ['incidents_open' => 2, 'arrivals_late' => 1, 'invoices_to_match' => 4, 'counts_open' => 3, 'stock_in_open' => 5, 'approvals_inbox' => 6,
            'reviews_open' => 9, 'queue_modes' => 2, 'barcodes_open' => 7, 'linking_pending' => 8, 'linking_duplicates' => 1];
        $f = Sections::frame(['stock_controller', 'purchasing_desk', 'mapping_lead'], $badges, '/ui/');
        $counts = array_column($f['nav'], 'count', 'key');
        self::assertSame([6, 7, 8, 7, 9], [$counts['approvals'], $counts['items'], $counts['inventory'], $counts['purchases'], $counts['listings']]);
        self::assertSame(Words::BADGE['approvals_inbox'], array_column($f['nav'], 'countWords', 'key')['approvals'], 'one kind counted: its own words');
        self::assertSame(Words::BADGE['section'], array_column($f['nav'], 'countWords', 'key')['purchases'], 'two kinds: "waiting for you"');
        self::assertSame([3, 3, 6], [$f['phone'][1]['count'], $f['phone'][2]['count'], $f['phone'][3]['count']],
            'Receive: Goods received\'s; Count: the differences; Approvals: the inbox');
        $sub = array_column(Sections::frame(['stock_controller'], $badges, '/ui/stock/in')['nav'], 'subs', 'key')['inventory'];
        self::assertSame([0, 5, 0, 3, 0, 0, 0], array_column($sub, 'count'), 'an entry counts its pages');

        // The bell: Approvals › Waiting for you and its count, for the people who answer there; nobody else has one.
        self::assertSame(['href' => '/ui/approvals', 'query' => [], 'count' => 6, 'countWords' => Words::BADGE['approvals_inbox']],
            Sections::frame(['stock_controller'], $badges, '/ui/stock')['bell']);
        self::assertNull(Sections::frame(['buyer'], $badges, '/ui/')['bell'], 'a buyer answers nothing there');
        foreach (Permissions::ROLES as $role) {
            self::assertSame(Sections::may([$role], \CW\Ui\Controller\InboxController::PERMS), Sections::frame([$role], [], '/ui/')['bell'] !== null, $role);
        }
    }

    /**
     * The pages that belong together are ONE Show (RD2): the current entry's pages the person may open, the page shown `here`, the record of
     * one `current`; Store listings' specialist pages are under the header's More on its lists (RD6); a store's code travels between the
     * entries and choices that keep it; the create button is the page's own; a list page draws no figure tiles (RD10).
     */
    public function testTheShowChoicesTheHeadersMoreAndTheCreateButton(): void
    {
        $show = static fn (array $f): array => array_map(static fn (array $s): string => $s['label'] . ($s['here'] ? '*' : ($s['current'] ? '+' : '')), $f['segments']);
        self::assertSame(['Corrections', 'Stock in*', 'Stock out', 'Releases'], $show(Sections::frame(['buyer'], [], '/ui/stock/in')));
        self::assertSame(['Corrections', 'Stock in+', 'Stock out', 'Releases'], $show(Sections::frame(['buyer'], [], '/ui/stock/in/12')), 'a record: its page current, no "here"');
        self::assertSame(['Items to count', 'Count sheets*', 'Differences'], $show(Sections::frame(['buyer'], [], '/ui/stock/counts/sheets')));
        self::assertSame(['Transfers', 'Balance owed*'], $show(Sections::frame(['buyer'], [], '/ui/stock/accounts')));
        self::assertSame(['All items*', 'Made by hand'], $show(Sections::frame(['stock_controller'], [], '/ui/items/cards')));
        self::assertSame(['All items', 'Made by hand*'], $show(Sections::frame(['stock_controller'], [], '/ui/items/new', ['show' => 'waiting'])));
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/items/cards')['segments'], 'one page the person may open: no Show');
        self::assertSame(['To receive*', 'Checking', 'Issues', 'Duty register', 'Arriving'], $show(Sections::frame(['goods_in'], ['incidents_open' => 1], '/ui/receiving')));
        self::assertSame(['To receive', 'Issues', 'Duty register', 'Arriving'], array_column(Sections::frame(['reviewer'], [], '/ui/receiving')['segments'], 'label'),
            'the bench is for the people who receive');
        self::assertSame(['Invoices', 'To match', 'Delivered, no invoice yet*', 'Timed'], $show(Sections::frame(['purchasing_desk'], [], '/ui/purchasing/invoices/not-invoiced')));
        self::assertSame(['Returns to suppliers', 'Credit notes*'], $show(Sections::frame(['reviewer'], [], '/ui/purchasing/credit-notes')));
        self::assertSame(['Suggestions*', 'Brands', 'Anomalies', 'Sales history', 'Suppliers', 'Low-stock warnings'], $show(Sections::frame(['buyer'], [], '/ui/purchasing/reorder')));
        self::assertSame(['Customers*', 'Balances'], $show(Sections::frame(['warehouse'], [], '/ui/stock/handovers/customers')));
        self::assertSame(['Waiting for you', 'Documents to review', 'History', 'Staff requests*', 'Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold',
            'Website bugs'], $show(Sections::frame(['reviewer'], [], '/ui/staff-requests')));
        self::assertSame(['History*', 'Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold', 'Website bugs'], $show(Sections::frame(['buyer'], [], '/ui/documents')));
        self::assertSame(['Company', 'Warehouses', 'Stores', 'Users', 'Roles and permissions', 'Approval rules', 'Reasons', 'Rules and numbers', 'Numbering',
            'System checks', 'Audit log*'], $show(Sections::frame(['admin'], [], '/ui/system/audit')));
        $counted = Sections::frame(['stock_controller'], ['counts_open' => 3], '/ui/stock/counts')['segments'];
        self::assertSame([0, 0, 3], array_column($counted, 'count'));
        self::assertSame(Words::BADGE['counts_open'], $counted[2]['countWords']);

        // Store listings: its specialist pages under the header's More of its lists (never in the sidebar), with their counts and lines.
        $more = static fn (array $f): array => array_map(static fn (array $t): string => $t['label'] . ($t['count'] > 0 ? " ({$t['count']})" : ''), $f['pagebar']['more']);
        $lead = Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/review/products');
        self::assertSame(['Store products', 'Spot check', 'Second approval (3)', 'New variants', 'Duplicates (2)'], $more($lead), 'Simple matching is a tab (RD-S1)');
        $simple = Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/match');
        self::assertSame($more($lead), $more($simple), 'Simple matching, another list of By item\'s page: the same More (RD-S1 `lists`)');
        self::assertSame(5, $simple['pagebar']['moreCount']);
        self::assertSame([], Sections::frame(['mapping_lead'], ['linking_pending' => 3], '/ui/match/options')['pagebar']['more'], 'its fragment is no list');
        self::assertSame(['/ui/review/store', '/ui/review/samples', '/ui/review', '/ui/review/new-variants', '/ui/review/duplicates'],
            array_column($lead['pagebar']['more'], 'href'));
        self::assertSame(Words::MENU_HELP['new_variants'], $lead['pagebar']['more'][3]['help']);
        self::assertSame(['Store products', 'Spot check', 'New variants', 'Duplicates (2)'],
            $more(Sections::frame(['mapping_lead'], ['linking_pending' => 3, 'linking_duplicates' => 2], '/ui/review', ['queue' => 'pending'])), 'never the page shown');
        self::assertSame([], Sections::frame(['mapping_lead'], [], '/ui/review/listing/12')['pagebar']['more'], 'a record: its own actions only');
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/stock/in')['pagebar']['more'], 'no other section has specialist pages');
        // A store's code travels between the entries that keep it (Sales' three; Check a match, the spot check and the second approval), a
        // plain code only, on the section's own pages.
        $sales = Sections::frame(['warehouse'], [], '/ui/stock/sales/dispatch', ['channel' => 'alt', 'show' => 'today']);
        $subs = array_column($sales['nav'], 'subs', 'key')['sales'];
        self::assertSame([['channel' => 'alt'], ['channel' => 'alt'], ['channel' => 'alt'], [], []], array_column($subs, 'query'), 'not to the trade sales');
        self::assertSame([false, true, false, false, false], array_column($subs, 'here'), 'the entry of the page shown has no link (CV13)');
        self::assertSame([[], [], [], [], []], array_column(array_column(Sections::frame(['warehouse'], [], '/ui/stock/sales', ['channel' => 'x"><b'])['nav'], 'subs', 'key')['sales'], 'query'),
            'a value that is not a code is not carried');
        self::assertSame([], array_column(Sections::frame(['warehouse'], [], '/ui/stock', ['channel' => 'alt'])['nav'], 'query', 'key')['sales'], 'from another section: nothing');
        $check = Sections::frame(['mapping_lead'], [], '/ui/review', ['queue' => 'Check', 'channel' => 'electrofag']);
        $carried = array_combine(array_column($check['pagebar']['more'], 'key'), array_column($check['pagebar']['more'], 'query'));
        self::assertSame(['channel' => 'electrofag'], $carried['samples']);
        self::assertSame(['queue' => 'pending', 'channel' => 'electrofag'], $carried['pending']);
        self::assertSame([], $carried['store_products'], 'Store products keeps no store from here');

        // The create button: on the list itself, for the people who may create, never on a detail page or the form it opens.
        self::assertSame([['label' => 'New purchase order', 'href' => '/ui/purchasing/orders/new']], Sections::frame(['buyer'], [], '/ui/purchasing/orders')['actions']);
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/orders/12')['actions']);
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/purchasing/orders/new')['actions'], 'the new order\'s page has none of its own');
        self::assertSame([], Sections::frame(['reviewer'], [], '/ui/purchasing/orders')['actions'], 'a reviewer does not order');
        self::assertSame([['label' => 'New item', 'href' => '/ui/items/new']], Sections::frame(['stock_controller'], [], '/ui/items/cards')['actions'], 'Items.dc.html\'s "+ New item"');
        self::assertSame([], Sections::frame(['buyer'], [], '/ui/items/cards')['actions'], 'only who may make an item');
        self::assertSame([['label' => 'New stock in', 'href' => '/ui/stock/in#new']], Sections::frame(['stock_controller'], [], '/ui/stock/in')['actions']);
        self::assertSame([['label' => 'New delivery', 'href' => '/ui/receiving/new']], Sections::frame(['goods_in'], [], '/ui/receiving')['actions']);
        self::assertSame(['/ui/people#new'], array_column(Sections::frame(['admin'], [], '/ui/people')['actions'], 'href'));
        self::assertSame([], Sections::frame(['auditor'], [], '/ui/people')['actions']);
        self::assertNull(Sections::frame(['buyer'], [], '/ui/')['pagebar'], 'Home: no section bar, no create button');
        self::assertSame([], Sections::frame(['purchasing_manager'], [], '/ui/')['actions']);

        // A list page draws no figure tiles (RD10): an entry's own page outside Home and the reports; a record keeps its strip.
        self::assertTrue(Sections::frame(['buyer'], [], '/ui/stock')['list']);
        self::assertTrue(Sections::frame(['buyer'], [], '/ui/stock/handovers/balances')['list']);
        self::assertTrue(Sections::frame(['buyer'], [], '/ui/purchasing/reorder/brands')['list']);
        self::assertFalse(Sections::frame(['buyer'], [], '/ui/purchasing/orders/12')['list'], 'a record');
        self::assertFalse(Sections::frame(['buyer'], [], '/ui/reports/low-stock')['list'], 'a report keeps its figures');
        self::assertFalse(Sections::frame(['buyer'], [], '/ui/')['list'], 'Home keeps its tiles');
        self::assertFalse(Sections::frame(['buyer'], [], '/ui/receiving/bench')['list'], 'a page the person cannot open');
    }

    /**
     * Same people reach the same pages (the brief's rule): from Home, following only what the shell draws (the sidebar's sections and the
     * current one's entries, the Show of a page of a group, the header's More of Store listings' lists), every page each job may open is
     * reached, for every job, a job with a look-only job beside it, and the owner's two jobs.
     */
    public function testEveryPageAPersonMayOpenIsReachedFromHome(): void
    {
        foreach ([...array_map(static fn (string $r): array => [$r], Permissions::ROLES), ['mapping_lead', 'reviewer'], ['admin', 'accountant'], ['buyer', 'goods_in']] as $roles) {
            $seen = [];
            $todo = [['/ui/', []]];
            while ($todo !== []) {
                [$path, $query] = array_shift($todo);
                $key = Html::url($path, $query);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $f = Sections::frame($roles, [], $path, $query);
                $links = [];
                foreach ($f['nav'] as $n) {
                    $links[] = [$n['href'], $n['query']];
                    foreach ($n['subs'] as $s) {
                        $links[] = [$s['href'], $s['query']];
                    }
                }
                if (in_array(true, array_column($f['segments'], 'here'), true)) {
                    foreach ($f['segments'] as $s) {
                        $links[] = [$s['href'], $s['query']];
                    }
                }
                foreach ($f['pagebar']['more'] ?? [] as $t) {
                    $links[] = [$t['href'], $t['query']];
                }
                foreach ($links as [$to, $q]) {
                    if (!isset($seen[Html::url($to, $q)])) {
                        $todo[] = [$to, $q];
                    }
                }
            }
            foreach (Sections::visible($roles) as $s => $tabs) {
                foreach ($tabs as $t => $tab) {
                    foreach ($tab['pages'] as $page) {
                        self::assertArrayHasKey(Html::url((string) $page['path'], $page['query'] ?? []), $seen,
                            implode('+', $roles) . ": {$s} / {$t} / {$page['key']} is reached from Home");
                    }
                }
            }
        }
    }
}
