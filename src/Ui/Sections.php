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
 *   tab     => ['pages' => list<page>, 'segments'?: true, 'keep'?: list<string>]   a tab; its name is Words::MENU[tab]; `keep`: the
 *                                                          query values (a store, `channel`) its segments carry from the current request
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
            // To review opens the "By store" overview (U107); the store chosen there travels through the segments (`keep`, U106).
            'mapping' => ['segments' => true, 'keep' => ['channel'], 'pages' => [
                ['key' => 'review', 'perm' => 'linking.view', 'path' => '/ui/review',
                    'match' => ['/ui/review', '/ui/review/listing/*', '/ui/review/decision/*', '/ui/review/bulk', '/ui/review/batches/*']],
                ['key' => 'samples', 'perm' => 'linking.view', 'path' => '/ui/review/samples', 'match' => ['/ui/review/samples*']],
                ['key' => 'pending', 'perm' => 'linking.view', 'path' => '/ui/review', 'query' => ['queue' => 'pending'], 'match' => ['/ui/review?queue=pending'],
                    'badge' => 'linking_pending'],
            ]],
            // Every website product of one store with its link state (U109).
            'store_products' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/store', 'match' => ['/ui/review/store*']]]],
            // Every warehouse product with its match on each store, its CSV and the picker of a website product for it (U113); the
            // preview and the batch page of a bulk action started there (U127: the apply's redirect says `from=item`).
            'by_product' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/products',
                'match' => ['/ui/review/products*', '/ui/review/batches/*?from=item']]]],
            // One store's variants first seen in a period, its CSV, and the seed "Create as new items" on every store (U131).
            'new_variants' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/new-variants', 'match' => ['/ui/review/new-variants*']]]],
            'duplicates' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/duplicates', 'match' => ['/ui/review/duplicates*'],
                'badge' => 'linking_duplicates']]],
            'barcodes' => ['pages' => [['perm' => 'catalogue.edit', 'path' => '/ui/items/barcodes', 'match' => ['/ui/items/barcodes*'], 'badge' => 'barcodes_open']]],
            // ---- Pack B (docs/decisions.md PB1-PB8): an item made by hand (Q10), product families (Q4), pre-packed multipacks and the pack
            // links beside them (Q3). Families and pack items are read by everyone who sees products; the pack links are matching pages.
            'add_item' => ['pages' => [['perm' => 'items.create', 'path' => '/ui/items/new', 'match' => ['/ui/items/new*'],
                'new' => ['perm' => 'items.create', 'href' => '/ui/items/new#new', 'label' => 'add_item']]]],
            'families' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/items/families', 'match' => ['/ui/items/families*'],
                'new' => ['perm' => 'families.manage', 'href' => '/ui/items/families#new', 'label' => 'families']]]],
            'multipacks' => ['segments' => true, 'pages' => [
                ['key' => 'packs', 'perm' => 'catalogue.view', 'path' => '/ui/items/packs', 'match' => ['/ui/items/packs', '/ui/items/packs/{id}*'],
                    'new' => ['perm' => 'catalogue.edit', 'href' => '/ui/items/packs#new', 'label' => 'packs']],
                ['key' => 'pack_links', 'perm' => 'linking.view', 'path' => '/ui/items/packs/links', 'match' => ['/ui/items/packs/links*']],
            ]],
            // ---- end of pack B
        ]],
        // Overview and Movements: stock_balance and the stock ledger (the item page's own figures: catalogue.view). The stock records of
        // pack A1 (docs/decisions.md SO10): the people who read records (documents.view) see their lists; the create buttons are for the
        // people who keep them (doc.<TYPE>.post). The other account's room (VPG 2) is the Transfers tab's "Balance owed". Reservations
        // (docs/decisions.md RS1): the stock kept for the stores' orders, read only, for the same people as Overview and Movements. Sales
        // (pack D, PD1): each store's orders, dispatch and returns, read only, for the people who look at sales (sales.view).
        'stock' => ['tabs' => [
            'stock' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock', 'match' => ['/ui/stock']]]],
            'movements' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/movements', 'match' => ['/ui/stock/movements*']]]],
            'stock_in' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/in', 'match' => ['/ui/stock/in', '/ui/stock/in.csv', '/ui/stock/in/*'],
                'badge' => 'stock_in_open', 'new' => ['perm' => 'doc.SIN.post', 'href' => '/ui/stock/in#new', 'label' => 'stock_in']]]],
            'stock_out' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/out', 'match' => ['/ui/stock/out', '/ui/stock/out.csv', '/ui/stock/out/*'],
                'badge' => 'stock_out_open', 'new' => ['perm' => 'doc.SOUT.post', 'href' => '/ui/stock/out#new', 'label' => 'stock_out']]]],
            // ---- Pack H (0038; docs/decisions.md PH13): hand-overs to sister shops, our shop units and trade customers (IM11), the
            // customers with their remembered prices, and what each owes us. The people who read records look (documents.view). ----
            'handovers' => ['segments' => true, 'pages' => [
                ['key' => 'handovers', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers', 'match' => ['/ui/stock/handovers', '/ui/stock/handovers.csv',
                    '/ui/stock/handovers/{id}*'], 'new' => ['perm' => 'doc.TRD.post', 'href' => '/ui/stock/handovers#new', 'label' => 'handovers']],
                ['key' => 'handover_customers', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/customers', 'match' => ['/ui/stock/handovers/customers*'],
                    'new' => ['perm' => 'handovers.customers', 'href' => '/ui/stock/handovers/customers#new', 'label' => 'handover_customers']],
                ['key' => 'handover_balances', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/balances', 'match' => ['/ui/stock/handovers/balances*']],
            ]],
            // ---- end of pack H ----
            'transfers' => ['segments' => true, 'pages' => [
                ['key' => 'transfers', 'perm' => 'documents.view', 'path' => '/ui/stock/transfers', 'match' => ['/ui/stock/transfers*'], 'badge' => 'transfers_open',
                    'new' => ['perm' => 'doc.TRF.post', 'href' => '/ui/stock/transfers#new', 'label' => 'transfers']],
                ['key' => 'releases', 'perm' => 'documents.view', 'path' => '/ui/stock/releases', 'match' => ['/ui/stock/releases*'], 'badge' => 'releases_open',
                    'new' => ['perm' => 'doc.REL.post', 'href' => '/ui/stock/releases#new', 'label' => 'releases']],
                ['key' => 'accounts', 'perm' => 'documents.view', 'path' => '/ui/stock/accounts', 'match' => ['/ui/stock/accounts*']],
            ]],
            'adjustments' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/adjustments', 'match' => ['/ui/stock/adjustments*'],
                'badge' => 'adjustments_open', 'new' => ['perm' => 'doc.ADJ.post', 'href' => '/ui/stock/adjustments#new', 'label' => 'adjustments']]]],
            // Counts (pack A2a; docs/decisions.md CT14): the plan and what to count next, the count sheets, the differences to check. The
            // people who read records look (documents.view); the people who count open, enter, post and clear (doc.CNT.post).
            'counts' => ['segments' => true, 'pages' => [
                ['key' => 'counts', 'perm' => 'documents.view', 'path' => '/ui/stock/counts', 'match' => ['/ui/stock/counts'],
                    'new' => ['perm' => 'doc.CNT.post', 'href' => '/ui/stock/counts#new', 'label' => 'counts']],
                ['key' => 'count_sheets', 'perm' => 'documents.view', 'path' => '/ui/stock/counts/sheets', 'match' => ['/ui/stock/counts/sheets*', '/ui/stock/counts/{id}*']],
                ['key' => 'count_differences', 'perm' => 'documents.view', 'path' => '/ui/stock/counts/differences', 'match' => ['/ui/stock/counts/differences*'],
                    'badge' => 'counts_open'],
            ]],
            'reservations' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/reservations', 'match' => ['/ui/stock/reservations*']]]],
            // ---- Pack D (docs/decisions.md PD1, PD2): Sales, read only. Each store's orders (and one order's page), what waits to be
            // sent, and what came back or was cancelled; the store chosen travels through the three segments (`keep`). ----
            'sales' => ['segments' => true, 'keep' => ['channel'], 'pages' => [
                ['key' => 'sales_orders', 'perm' => 'sales.view', 'path' => '/ui/stock/sales', 'match' => ['/ui/stock/sales', '/ui/stock/sales.csv', '/ui/stock/sales/{id}']],
                ['key' => 'sales_dispatch', 'perm' => 'sales.view', 'path' => '/ui/stock/sales/dispatch', 'match' => ['/ui/stock/sales/dispatch*']],
                ['key' => 'sales_returns', 'perm' => 'sales.view', 'path' => '/ui/stock/sales/returns', 'match' => ['/ui/stock/sales/returns*']],
            ]],
            // ---- end of pack D ----
            'quality' => ['soon' => true],
            // ---- Pack B (PB9): a Repack breaks packs into singles or makes packs; documents.view reads, doc.RPK.post books.
            'repack' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/repack', 'match' => ['/ui/stock/repack*'],
                'new' => ['perm' => 'doc.RPK.post', 'href' => '/ui/stock/repack#new', 'label' => 'repack']]]],
        ]],
        'purchasing' => ['tabs' => [
            'reorder' => ['segments' => true, 'pages' => [
                ['key' => 'reorder', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder', 'match' => ['/ui/purchasing/reorder*']],
                ['key' => 'brands', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/brands', 'match' => ['/ui/purchasing/reorder/brands*']],
                ['key' => 'anomalies', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/anomalies', 'match' => ['/ui/purchasing/reorder/anomalies*']],
                ['key' => 'sales_history', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/sales-history', 'match' => ['/ui/purchasing/sales-history*']],
                // --- Pack G (docs/decisions.md PG9): the suppliers' spare days and the low-stock warnings CW keeps for the websites.
                ['key' => 'reorder_suppliers', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/suppliers', 'match' => ['/ui/purchasing/reorder/suppliers*']],
                ['key' => 'low_stock_levels', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/low-stock', 'match' => ['/ui/purchasing/reorder/low-stock*']],
                // --- end of Pack G
            ]],
            'orders' => ['pages' => [['perm' => 'purchasing.view', 'path' => '/ui/purchasing/orders', 'match' => ['/ui/purchasing/orders*'],
                'new' => ['perm' => 'doc.PO.post', 'href' => '/ui/purchasing/orders#new', 'label' => 'orders']]]],
            'goods_in' => ['segments' => true, 'pages' => [
                ['key' => 'receiving', 'perm' => 'receiving.view', 'path' => '/ui/receiving', 'match' => ['/ui/receiving', '/ui/receiving/*'],
                    'new' => ['perm' => 'doc.GRN.post', 'href' => '/ui/receiving#new', 'label' => 'receiving']],
                ['key' => 'bench', 'perm' => 'doc.GRN.post', 'path' => '/ui/receiving/bench', 'match' => ['/ui/receiving/bench', '/ui/receiving/{id}/bench']],
                ['key' => 'incidents', 'perm' => 'incidents.view', 'path' => '/ui/receiving/incidents', 'match' => ['/ui/receiving/incidents*'],
                    'badge' => 'incidents_open'],
                // Pack H (0038; docs/decisions.md PH15): the vaping duty register built from the receipts (IM13), read only.
                ['key' => 'duty', 'perm' => 'duty.view', 'path' => '/ui/receiving/duty', 'match' => ['/ui/receiving/duty*']],
                // Pack J (0041; docs/decisions.md PJ14): the goods of final supplier invoices on their way, and "Receive".
                ['key' => 'arriving', 'perm' => 'receiving.view', 'path' => '/ui/receiving/arriving', 'match' => ['/ui/receiving/arriving*'], 'badge' => 'arrivals_late'],
            ]],
            // --- Pack C (IM7, docs/decisions.md PC17): supplier invoices (the invoices, the "To match" list, the deliveries not invoiced
            // yet, the supplier's credit notes) and returns to suppliers.
            'invoices' => ['segments' => true, 'pages' => [
                ['key' => 'invoices', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices',
                    'match' => ['/ui/purchasing/invoices', '/ui/purchasing/invoices/{id}*', '/ui/purchasing/invoices/from-receipt'],
                    'new' => ['perm' => 'doc.SINV.post', 'href' => '/ui/purchasing/invoices#new', 'label' => 'invoices']],
                ['key' => 'to_match', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/to-match', 'match' => ['/ui/purchasing/invoices/to-match*'],
                    'badge' => 'invoices_to_match'],
                ['key' => 'not_invoiced', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/not-invoiced', 'match' => ['/ui/purchasing/invoices/not-invoiced*']],
                ['key' => 'credit_notes', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/credit-notes', 'match' => ['/ui/purchasing/credit-notes*'],
                    'new' => ['perm' => 'doc.SCN.post', 'href' => '/ui/purchasing/credit-notes#new', 'label' => 'credit_notes']],
                // Pack J (0041; docs/decisions.md PJ10): the draft invoices waiting for their time.
                ['key' => 'timed', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/timed', 'match' => ['/ui/purchasing/invoices/timed*'],
                    'badge' => 'invoices_timed_failed'],
            ]],
            'returns' => ['pages' => [['perm' => 'returns.view', 'path' => '/ui/purchasing/returns', 'match' => ['/ui/purchasing/returns*'],
                'new' => ['perm' => 'doc.DN.post', 'href' => '/ui/purchasing/returns#new', 'label' => 'returns']]]],
            // --- end of pack C
            'suppliers' => ['pages' => [['perm' => 'suppliers.view', 'path' => '/ui/purchasing/suppliers',
                'match' => ['/ui/purchasing/suppliers*', '/ui/purchasing/supplier-items*'],
                'new' => ['perm' => 'suppliers.manage', 'href' => '/ui/purchasing/suppliers/new', 'label' => 'suppliers']]]],
        ]],
        'reports' => ['tabs' => [
            // ---- Pack E (docs/decisions.md PE1): the reports, read only, each with its CSV; Stock Value is money (values.view).
            'stock_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/stock-by-store', 'match' => ['/ui/reports/stock-by-store*']]]],
            'sales_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/sales-by-store', 'match' => ['/ui/reports/sales-by-store*']]]],
            'low_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/low-stock', 'match' => ['/ui/reports/low-stock*']]]],
            'stock_value' => ['pages' => [['perm' => 'values.view', 'path' => '/ui/reports/stock-value', 'match' => ['/ui/reports/stock-value*']]]],
            'slow_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/slow-stock', 'match' => ['/ui/reports/slow-stock*']]]],
            // ---- end Pack E
            'audit' => ['pages' => [['perm' => 'audit.view', 'path' => '/ui/system/audit', 'match' => ['/ui/system/audit*']]]],
        ]],
        'approvals' => ['tabs' => [
            'reviews' => ['pages' => [['perm' => 'documents.review', 'path' => '/ui/documents/reviews', 'match' => ['/ui/documents/reviews*'], 'badge' => 'reviews_open']]],
            'documents' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/documents', 'match' => ['/ui/documents', '/ui/documents/*', '/ui/files/*']]]],
            'staff_requests' => ['pages' => [['perm' => 'staff.approve', 'path' => '/ui/staff-requests', 'match' => ['/ui/staff-requests*']]]],
            // ---- Pack F (docs/decisions.md PF5): what the stores send that needs a person, one segment per queue. Everyone who reads
            // records looks (queues.view); the counts are only what this person may answer (Ui\Context::badges).
            'store_queues' => ['segments' => true, 'pages' => [
                ['key' => 'queue_suspense', 'perm' => 'queues.view', 'path' => '/ui/queues/suspense', 'match' => ['/ui/queues/suspense*'], 'badge' => 'queue_suspense'],
                ['key' => 'queue_corrections', 'perm' => 'queues.view', 'path' => '/ui/queues/corrections', 'match' => ['/ui/queues/corrections*'],
                    'badge' => 'queue_corrections'],
                ['key' => 'queue_modes', 'perm' => 'queues.view', 'path' => '/ui/queues/modes', 'match' => ['/ui/queues/modes*'], 'badge' => 'queue_modes'],
                ['key' => 'queue_oversold', 'perm' => 'queues.view', 'path' => '/ui/queues/oversold', 'match' => ['/ui/queues/oversold*'], 'badge' => 'queue_oversold'],
                // Website bugs (0050, docs/decisions.md LP8): orders a store took for a landing page.
                ['key' => 'queue_landing', 'perm' => 'queues.view', 'path' => '/ui/queues/landing', 'match' => ['/ui/queues/landing*'], 'badge' => 'queue_landing'],
            ]],
            // ---- end of pack F ----
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
        if ($cond !== null) {
            // A value that is not one plain value (queue[]=x) names no page: never cast it (it was a 500 on the "By store" overview).
            $value = $query[$cond[0]] ?? '';
            if (!is_scalar($value) || (string) $value !== $cond[1]) {
                return null;
            }
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
                // The query values the tab keeps (a store's code: a plain code only, the pages check it names a store).
                $kept = [];
                foreach (self::MAP[$s]['tabs'][$here['tab']]['keep'] ?? [] as $k) {
                    $v = $query[$k] ?? null;
                    if (is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $v) === 1) {
                        $kept[$k] = $v;
                    }
                }
                foreach ($tab['pages'] as $page) {
                    [$count, $words] = self::count([$page], $badges);
                    $segments[] = ['key' => (string) $page['key'], 'label' => Words::SEGMENT[$page['key']], 'href' => (string) $page['path'],
                        'query' => ($page['query'] ?? []) + $kept, 'count' => $count, 'countWords' => $words, 'current' => $here['page'] === $page['key']];
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
