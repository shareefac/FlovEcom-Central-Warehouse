<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;

/**
 * THE navigation map of the staff screens (owner's request of 8 Oct 2026: "sidebar name make professional and make related tabs";
 * design v3): seven sidebar sections, the tabs inside each, and the segmented filter inside a tab. The sidebar, the tab bar, the
 * segmented filter, the phone bar, Home's "What you can use" and the tests all read this map; no view names a page itself.
 *
 *   section => ['tabs' => [tab => tab]]                       the sidebar item; its words are Words::SECTION and SECTION_DESC
 *   tab     => ['pages' => list<page>, 'segments'?: true]      a tab; its name is Words::MENU[tab]
 *           |  ['soon' => true]                                a tab not built yet: drawn disabled with a "Soon" chip, never a link
 *   page    => ['perm', 'path', 'query'?, 'match', 'badge'?, 'key'?, 'new'?]
 *
 * A page is one GET route: `perm` is exactly the permission the route checks (Ui\Kernel), so a link is shown to exactly the people
 * the route lets in (Tests\Unit\SectionsTest). A tab shows when the person may open at least one of its pages, and links to the
 * first of them; a section shows when one of its tabs does. In a tab with `segments` each page is a segment named
 * Words::SEGMENT[key]. `match` lists the request paths that belong to the page (detail pages, forms sent back with an error):
 * `{id}` is a number, a trailing `*` any rest, `?k=v` a query value; the most specific pattern wins (locate()). `badge` names a count
 * of Ui\Context::badges() (only what this person can act on). `new` is the page's create action, drawn as the toolbar's primary
 * button on that page: [perm, href, Words::NEW key].
 */
final class Sections
{
    public const MAP = [
        'dashboard' => ['tabs' => [
            'home' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/', 'match' => ['/ui', '/ui/']]]],
        ]],
        'products' => ['tabs' => [
            'cards' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/items/cards', 'match' => ['/ui/items/cards*', '/ui/items/{id}*', '/ui/search']]]],
            'mapping' => ['segments' => true, 'pages' => [
                ['key' => 'review', 'perm' => 'linking.view', 'path' => '/ui/review', 'query' => ['queue' => 'Key'],
                    'match' => ['/ui/review', '/ui/review/listing/*', '/ui/review/decision/*']],
                ['key' => 'samples', 'perm' => 'linking.view', 'path' => '/ui/review/samples', 'match' => ['/ui/review/samples*']],
                ['key' => 'pending', 'perm' => 'linking.view', 'path' => '/ui/review', 'query' => ['queue' => 'pending'], 'match' => ['/ui/review?queue=pending'],
                    'badge' => 'linking_pending'],
            ]],
            'duplicates' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/duplicates', 'match' => ['/ui/review/duplicates*'],
                'badge' => 'linking_duplicates']]],
            'barcodes' => ['pages' => [['perm' => 'catalogue.edit', 'path' => '/ui/items/barcodes', 'match' => ['/ui/items/barcodes*'], 'badge' => 'barcodes_open']]],
        ]],
        // Built from stock_balance and the stock ledger (the item page's own figures: catalogue.view); per store and per account later.
        'stock' => ['tabs' => [
            'stock' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock', 'match' => ['/ui/stock']]]],
            'movements' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/movements', 'match' => ['/ui/stock/movements*']]]],
            'adjustments' => ['soon' => true],
            'counts' => ['soon' => true],
            'transfers' => ['soon' => true],
        ]],
        'purchasing' => ['tabs' => [
            'reorder' => ['segments' => true, 'pages' => [
                ['key' => 'reorder', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder', 'match' => ['/ui/purchasing/reorder*']],
                ['key' => 'brands', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/brands', 'match' => ['/ui/purchasing/reorder/brands*']],
                ['key' => 'anomalies', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/anomalies', 'match' => ['/ui/purchasing/reorder/anomalies*']],
                ['key' => 'sales_history', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/sales-history', 'match' => ['/ui/purchasing/sales-history*']],
            ]],
            'orders' => ['pages' => [['perm' => 'purchasing.view', 'path' => '/ui/purchasing/orders', 'match' => ['/ui/purchasing/orders*'],
                'new' => ['perm' => 'doc.PO.post', 'href' => '/ui/purchasing/orders#new', 'label' => 'orders']]]],
            'goods_in' => ['segments' => true, 'pages' => [
                ['key' => 'receiving', 'perm' => 'receiving.view', 'path' => '/ui/receiving', 'match' => ['/ui/receiving', '/ui/receiving/*'],
                    'new' => ['perm' => 'doc.GRN.post', 'href' => '/ui/receiving#new', 'label' => 'receiving']],
                ['key' => 'bench', 'perm' => 'doc.GRN.post', 'path' => '/ui/receiving/bench', 'match' => ['/ui/receiving/bench', '/ui/receiving/{id}/bench']],
                ['key' => 'incidents', 'perm' => 'incidents.view', 'path' => '/ui/receiving/incidents', 'match' => ['/ui/receiving/incidents*'],
                    'badge' => 'incidents_open'],
            ]],
            'suppliers' => ['pages' => [['perm' => 'suppliers.view', 'path' => '/ui/purchasing/suppliers',
                'match' => ['/ui/purchasing/suppliers*', '/ui/purchasing/supplier-items*'],
                'new' => ['perm' => 'suppliers.manage', 'href' => '/ui/purchasing/suppliers/new', 'label' => 'suppliers']]]],
        ]],
        'reports' => ['tabs' => [
            'stock_by_store' => ['soon' => true],
            'sales_by_store' => ['soon' => true],
            'low_stock' => ['soon' => true],
            'audit' => ['pages' => [['perm' => 'audit.view', 'path' => '/ui/system/audit', 'match' => ['/ui/system/audit*']]]],
        ]],
        'approvals' => ['tabs' => [
            'reviews' => ['pages' => [['perm' => 'documents.review', 'path' => '/ui/documents/reviews', 'match' => ['/ui/documents/reviews*'], 'badge' => 'reviews_open']]],
            'documents' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/documents', 'match' => ['/ui/documents', '/ui/documents/*', '/ui/files/*']]]],
            'staff_requests' => ['pages' => [['perm' => 'staff.approve', 'path' => '/ui/staff-requests', 'match' => ['/ui/staff-requests*']]]],
        ]],
        'settings' => ['tabs' => [
            'company' => ['pages' => [['perm' => 'reference.view', 'path' => '/ui/reference/company', 'match' => ['/ui/reference/company*']]]],
            'warehouses' => ['pages' => [['perm' => 'reference.view', 'path' => '/ui/reference/warehouses', 'match' => ['/ui/reference/warehouses*'],
                'new' => ['perm' => 'settings.manage', 'href' => '/ui/reference/warehouses#new', 'label' => 'warehouses']]]],
            'sites' => ['pages' => [['perm' => 'system.view', 'path' => '/ui/system/sites', 'match' => ['/ui/system/sites*']]]],
            'people' => ['pages' => [
                ['perm' => 'staff.view', 'path' => '/ui/people', 'match' => ['/ui/people*'],
                    'new' => ['perm' => 'staff.manage', 'href' => '/ui/people#new', 'label' => 'people']],
                ['key' => 'access', 'perm' => 'reference.view', 'path' => '/ui/reference/access', 'match' => ['/ui/reference/access']],
            ]],
            'approvals' => ['pages' => [['perm' => 'reference.view', 'path' => '/ui/reference/approvals', 'match' => ['/ui/reference/approvals*']]]],
            'reasons' => ['pages' => [['perm' => 'reference.view', 'path' => '/ui/reference/reasons', 'match' => ['/ui/reference/reasons*'],
                'new' => ['perm' => 'settings.manage', 'href' => '/ui/reference/reasons#new', 'label' => 'reasons']]]],
            'system' => ['segments' => true, 'pages' => [
                ['key' => 'settings', 'perm' => 'reference.view', 'path' => '/ui/reference/settings', 'match' => ['/ui/reference/settings*']],
                ['key' => 'series', 'perm' => 'reference.view', 'path' => '/ui/reference/series', 'match' => ['/ui/reference/series*']],
                ['key' => 'integrity', 'perm' => 'system.view', 'path' => '/ui/system/checks', 'match' => ['/ui/system/checks*']],
            ]],
        ]],
    ];

    /** The phone's bottom bar: Dashboard, then the first PHONE_MAX of these the person has, then "More" (the whole sidebar). */
    public const PHONE = ['products', 'stock', 'purchasing', 'approvals', 'reports', 'settings'];
    public const PHONE_MAX = 3;

    /**
     * Where a request belongs: the section, tab and page whose `match` fits the path (and query) best, or null (the password page,
     * a page outside every section). Permissions play no part: a page refused with 403 still belongs where it is.
     *
     * @param array<string, mixed> $query
     * @return array{section: string, tab: string, page: string}|null
     */
    public static function locate(string $path, array $query = []): ?array
    {
        $best = null;
        $score = -1.0;
        foreach (self::MAP as $s => $section) {
            foreach ($section['tabs'] as $t => $tab) {
                foreach ($tab['pages'] ?? [] as $page) {
                    foreach ($page['match'] as $pattern) {
                        $fit = self::fit($pattern, $path, $query);
                        if ($fit !== null && $fit > $score) {
                            $score = $fit;
                            $best = ['section' => $s, 'tab' => $t, 'page' => (string) ($page['key'] ?? $t)];
                        }
                    }
                }
            }
        }
        return $best;
    }

    /**
     * How well a `match` pattern fits (null: not at all): the more literal characters, the better; a query condition beats any path;
     * an exact pattern beats a prefix of the same length.
     *
     * @param array<string, mixed> $query
     */
    public static function fit(string $pattern, string $path, array $query = []): ?float
    {
        $cond = null;
        if (str_contains($pattern, '?')) {
            [$pattern, $q] = explode('?', $pattern, 2);
            $cond = explode('=', $q, 2) + [1 => ''];
        }
        $prefix = str_ends_with($pattern, '*');
        $body = $prefix ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^' . implode('[1-9][0-9]{0,17}', array_map(static fn (string $p): string => preg_quote($p, '#'), explode('{id}', $body)))
            . ($prefix ? '' : '$') . '#D';
        if (preg_match($regex, $path) !== 1) {
            return null;
        }
        if ($cond !== null && (string) ($query[$cond[0]] ?? '') !== $cond[1]) {
            return null;
        }
        return strlen(str_replace('{id}', '', $body)) + ($cond !== null ? 1000 : 0) + ($prefix ? 0 : 0.5);
    }

    /**
     * The sections, tabs and pages a person may open (the map, filtered): tabs not built yet are kept (they are drawn disabled)
     * in a section that has a live one.
     *
     * @param list<string> $roles
     * @return array<string, array<string, array{soon: bool, segments: bool, pages: list<array<string, mixed>>}>> section => tab => tab
     */
    public static function visible(array $roles): array
    {
        $out = [];
        foreach (self::MAP as $s => $section) {
            $tabs = [];
            $live = false;
            foreach ($section['tabs'] as $t => $tab) {
                if (($tab['soon'] ?? false) === true) {
                    $tabs[$t] = ['soon' => true, 'segments' => false, 'pages' => []];
                    continue;
                }
                $pages = [];
                foreach ($tab['pages'] as $page) {
                    if (Permissions::can($roles, $page['perm'])) {
                        $pages[] = ['key' => (string) ($page['key'] ?? $t)] + $page;
                    }
                }
                if ($pages !== []) {
                    $tabs[$t] = ['soon' => false, 'segments' => ($tab['segments'] ?? false) === true, 'pages' => $pages];
                    $live = true;
                }
            }
            if ($live) {
                $out[$s] = $tabs;
            }
        }
        return $out;
    }

    /**
     * The menu in the shape Home's "What you can use" reads (DashboardController): each section with its live tabs, each tab with
     * the link of the first page the person may open. No roles, no menu.
     *
     * @param list<string> $roles
     * @return list<array{section: string, key: string, items: list<array{key: string, label: string, path: string, query: array<string, string>}>}>
     */
    public static function menu(array $roles): array
    {
        $out = [];
        foreach (self::visible($roles) as $s => $tabs) {
            $items = [];
            foreach ($tabs as $t => $tab) {
                if ($tab['soon']) {
                    continue;
                }
                $first = $tab['pages'][0];
                $items[] = ['key' => $t, 'label' => Words::MENU[$t], 'path' => (string) $first['path'], 'query' => $first['query'] ?? []];
            }
            $out[] = ['section' => Words::SECTION[$s], 'key' => $s, 'items' => $items];
        }
        return $out;
    }

    /**
     * Everything the layout draws around a page for this person (Context::frame):
     *   nav       the sidebar: each section's label, link (its first tab's), count (the sum of its tabs' counts) and whether it is current
     *   pagebar   for the current section when the person has it (Dashboard has none: its page title is the page's own): its label,
     *             one-line description, the tabs (current one marked, counts, "Soon" ones without a link), whether it draws the buying flow
     *   segments  the current tab's segmented filter (two or more segments the person may open), or []
     *   actions   the current page's create button the person may use (only on the page itself, not its detail pages), then the
     *             section's other create actions they may use (the split button's menu); on the Dashboard every create action they may use
     *   phone     the bottom bar: Dashboard, up to PHONE_MAX sections, "More" (#menu)
     *
     * @param list<string> $roles
     * @param array<string, int> $badges Context::badges()
     * @param array<string, mixed> $query
     * @return array{nav: list<array<string, mixed>>, pagebar: ?array<string, mixed>, segments: list<array<string, mixed>>, actions: list<array<string, string>>, phone: list<array<string, mixed>>, here: ?array{section: string, tab: string, page: string}}
     */
    public static function frame(array $roles, array $badges, string $path, array $query = []): array
    {
        $here = self::locate($path, $query);
        $visible = self::visible($roles);
        $nav = [];
        $tabsOf = [];
        foreach ($visible as $s => $tabs) {
            $list = [];
            $total = 0;
            foreach ($tabs as $t => $tab) {
                if ($tab['soon']) {
                    $list[] = ['key' => $t, 'label' => Words::MENU[$t], 'href' => null, 'query' => [], 'count' => 0, 'countWords' => '', 'current' => false, 'soon' => true];
                    continue;
                }
                [$count, $words] = self::count($tab['pages'], $badges);
                $total += $count;
                $first = $tab['pages'][0];
                $list[] = ['key' => $t, 'label' => Words::MENU[$t], 'href' => (string) $first['path'], 'query' => $first['query'] ?? [], 'count' => $count,
                    'countWords' => $words, 'current' => $here !== null && $here['section'] === $s && $here['tab'] === $t, 'soon' => false];
            }
            $tabsOf[$s] = $list;
            $first = array_values(array_filter($list, static fn (array $x): bool => !$x['soon']))[0];
            $nav[] = ['key' => $s, 'label' => Words::SECTION[$s], 'href' => $first['href'], 'query' => $first['query'], 'count' => $total,
                'countWords' => Words::BADGE['section'], 'current' => $here !== null && $here['section'] === $s];
        }

        $pagebar = null;
        $segments = [];
        $actions = $here !== null && $here['section'] === 'dashboard' ? self::createActions($roles) : [];
        if ($here !== null && isset($visible[$here['section']]) && $here['section'] !== 'dashboard') {
            $s = $here['section'];
            $pagebar = ['key' => $s, 'label' => Words::SECTION[$s], 'desc' => Words::SECTION_DESC[$s], 'tabs' => $tabsOf[$s], 'flow' => $s === 'purchasing',
                'tab' => Words::MENU[$here['tab']]];
            $tab = $visible[$s][$here['tab']] ?? null;
            if ($tab !== null && $tab['segments'] && count($tab['pages']) > 1) {
                foreach ($tab['pages'] as $page) {
                    [$count, $words] = self::count([$page], $badges);
                    $segments[] = ['key' => (string) $page['key'], 'label' => Words::SEGMENT[$page['key']], 'href' => (string) $page['path'],
                        'query' => $page['query'] ?? [], 'count' => $count, 'countWords' => $words, 'current' => $here['page'] === $page['key']];
                }
            }
            foreach ($tab['pages'] ?? [] as $page) {
                $new = $page['new'] ?? null;
                if ($new !== null && $page['key'] === $here['page'] && rtrim($path, '/') === rtrim((string) $page['path'], '/')
                    && Permissions::can($roles, $new['perm'])) {
                    $actions[] = ['label' => Words::NEW[$new['label']], 'href' => (string) $new['href']];
                }
            }
            // The split button's menu: the section's other create actions the person may use (only beside a page's own).
            if ($actions !== []) {
                foreach ($visible[$s] as $other) {
                    foreach ($other['pages'] as $page) {
                        $new = $page['new'] ?? null;
                        if ($new !== null && (string) $new['href'] !== $actions[0]['href'] && Permissions::can($roles, $new['perm'])) {
                            $actions[] = ['label' => Words::NEW[$new['label']], 'href' => (string) $new['href']];
                        }
                    }
                }
            }
        }

        $phone = [];
        if (isset($visible['dashboard'])) {
            $byKey = array_column($nav, null, 'key');
            $picked = ['dashboard'];
            foreach (self::PHONE as $s) {
                if (isset($byKey[$s]) && count($picked) <= self::PHONE_MAX) {
                    $picked[] = $s;
                }
            }
            foreach ($picked as $s) {
                $phone[] = $byKey[$s];
            }
            $phone[] = ['key' => 'more', 'label' => Words::TAB['more'], 'href' => '#menu', 'query' => [], 'count' => 0, 'countWords' => '', 'current' => false];
        }
        return ['nav' => $nav, 'pagebar' => $pagebar, 'segments' => $segments, 'actions' => $actions, 'phone' => $phone, 'here' => $here];
    }

    /**
     * The count of a tab or segment: the sum of its pages' badges, and the words a screen reader hears after it (the badge's own
     * words when one page has a count, else "waiting for you").
     *
     * @param list<array<string, mixed>> $pages
     * @param array<string, int> $badges
     * @return array{0: int, 1: string}
     */
    private static function count(array $pages, array $badges): array
    {
        $sum = 0;
        $names = [];
        foreach ($pages as $page) {
            if (isset($page['badge'])) {
                $n = (int) ($badges[$page['badge']] ?? 0);
                if ($n > 0) {
                    $sum += $n;
                    $names[] = (string) $page['badge'];
                }
            }
        }
        return [$sum, count($names) === 1 ? Words::BADGE[$names[0]] : Words::BADGE['section']];
    }

    /**
     * Where a link leads, in the names of the navigation ("Products › Mapping › Spot check"; the Dashboard's task board), or ''
     * for a link outside every section.
     */
    public static function where(string $href): string
    {
        $parts = parse_url($href);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $here = self::locate((string) ($parts['path'] ?? ''), $query);
        if ($here === null) {
            return '';
        }
        $names = [Words::SECTION[$here['section']]];
        if ($here['section'] !== 'dashboard') {
            $names[] = Words::MENU[$here['tab']];
            if ((self::MAP[$here['section']]['tabs'][$here['tab']]['segments'] ?? false) === true) {
                $names[] = Words::SEGMENT[$here['page']];
            }
        }
        return implode(' › ', $names);
    }

    /**
     * Every create action the person may use, in the map's order (the Dashboard's split button).
     *
     * @param list<string> $roles
     * @return list<array{label: string, href: string}>
     */
    public static function createActions(array $roles): array
    {
        $out = [];
        foreach (self::visible($roles) as $tabs) {
            foreach ($tabs as $tab) {
                foreach ($tab['pages'] as $page) {
                    $new = $page['new'] ?? null;
                    if ($new !== null && Permissions::can($roles, $new['perm'])) {
                        $out[] = ['label' => Words::NEW[$new['label']], 'href' => (string) $new['href']];
                    }
                }
            }
        }
        return $out;
    }

    /** @return list<string> every badge name the map uses */
    public static function badgeNames(): array
    {
        $out = [];
        foreach (self::MAP as $section) {
            foreach ($section['tabs'] as $tab) {
                foreach ($tab['pages'] ?? [] as $page) {
                    if (isset($page['badge'])) {
                        $out[] = (string) $page['badge'];
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }
}
