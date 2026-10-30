<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;

/**
 * THE navigation map of the staff screens: the approved redesign of 30 Oct 2026 (Sidebar.dc.html; docs/decisions.md RD1-RD12): seven
 * sections with the standard inventory names, each with its colour and icon, between Home and Approvals at the top and Reports and
 * Settings at the bottom; a section's entries are its pages in the sidebar (only the current section shows them), and the pages that
 * belong together in one entry are its "Show" choices (no tab rows, no third level). The sidebar, the Show choice, the header's More,
 * the phone bar and the tests all read this map; no view names a page itself.
 *
 *   section => ['tabs' => [entry => entry], 'count'?: page, 'gap'?: true]   its name is Words::SECTION; `count`: the page whose count is
 *                                                          the section's and its entry's (an inbox of the others' records, AV10), else
 *                                                          the sum; `gap`: a divider above it in the sidebar
 *   entry   => ['pages' => list<page>, 'keep'?: list<string>, 'more'?: true]   an entry (a sidebar sub-item; the map still says "tab");
 *                                                          its name is Words::MENU[entry]; its link is the first page the person may
 *                                                          open; two or more pages are its Show choices (Words::SEGMENT[key]); `keep`:
 *                                                          the query values (a store's `channel`) carried between the entries and
 *                                                          choices that keep them; `more`: a specialist page of the section, not in the
 *                                                          sidebar: the section's list pages list it under the header's More
 *   page    => ['perm', 'path', 'query'?, 'match', 'badge'?, 'key'?, 'new'?]
 *
 * A page is one GET route: `perm` is exactly the permission the route checks (Ui\Kernel), so a link is shown to exactly the people
 * the route lets in (Tests\Unit\SectionsTest); a list of permissions (any of them) is a route open to every signed-in person whose
 * page refuses the others itself (Approvals › Waiting for you). An entry shows when the person may open at least one of its pages; a
 * section shows when one of its entries does. `match` lists the request paths that belong to the page (detail pages, forms sent back
 * with an error): `{id}` is a number, a trailing `*` any rest, `?k=v` a query value; the most specific pattern wins (locate()). `badge`
 * names a count of Ui\Context::badges() (only what this person can act on). `new` is the page's create action, drawn as the header's
 * main button on that page: [perm, href, Words::NEW key].
 */
final class Sections
{
    public const MAP = [
        'home' => ['tabs' => [
            'home' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/', 'match' => ['/ui', '/ui/']]]],
        ]],
        // Approvals: ONE entry (no sub-items); Waiting for you first, the documents to review, the history, the staff requests and what
        // the stores send are its Show choices. Its count is the inbox's: every record the person may answer, counted once (AV10).
        'approvals' => ['count' => 'inbox', 'tabs' => [
            'inbox' => ['pages' => [
                ['key' => 'inbox', 'perm' => Controller\InboxController::PERMS, 'path' => '/ui/approvals', 'match' => ['/ui/approvals', '/ui/approvals/*'],
                    'badge' => 'approvals_inbox'],
                ['key' => 'reviews', 'perm' => 'documents.review', 'path' => '/ui/documents/reviews', 'match' => ['/ui/documents/reviews*'], 'badge' => 'reviews_open'],
                ['key' => 'documents', 'perm' => 'documents.view', 'path' => '/ui/documents', 'match' => ['/ui/documents', '/ui/documents/*', '/ui/files/*']],
                ['key' => 'staff_requests', 'perm' => 'staff.approve', 'path' => '/ui/staff-requests', 'match' => ['/ui/staff-requests*']],
                // ---- Pack F (docs/decisions.md PF5): what the stores send that needs a person. Everyone who reads records looks
                // (queues.view); the counts are only what this person may answer (Ui\Context::badges).
                ['key' => 'queue_suspense', 'perm' => 'queues.view', 'path' => '/ui/queues/suspense', 'match' => ['/ui/queues/suspense*'], 'badge' => 'queue_suspense'],
                ['key' => 'queue_corrections', 'perm' => 'queues.view', 'path' => '/ui/queues/corrections', 'match' => ['/ui/queues/corrections*'],
                    'badge' => 'queue_corrections'],
                ['key' => 'queue_modes', 'perm' => 'queues.view', 'path' => '/ui/queues/modes', 'match' => ['/ui/queues/modes*'], 'badge' => 'queue_modes'],
                ['key' => 'queue_oversold', 'perm' => 'queues.view', 'path' => '/ui/queues/oversold', 'match' => ['/ui/queues/oversold*'], 'badge' => 'queue_oversold'],
                // Website bugs (0050, docs/decisions.md LP8): orders a store took for a landing page.
                ['key' => 'queue_landing', 'perm' => 'queues.view', 'path' => '/ui/queues/landing', 'match' => ['/ui/queues/landing*'], 'badge' => 'queue_landing'],
            ]],
        ]],
        // Items: the warehouse's items (the items made by hand a Show choice; "+ New item" opens the one-page form), the item groups (the
        // families), the multipacks with their pack links, the barcodes to place.
        'items' => ['gap' => true, 'tabs' => [
            'cards' => ['pages' => [
                ['key' => 'cards', 'perm' => 'catalogue.view', 'path' => '/ui/items/cards', 'match' => ['/ui/items/cards*', '/ui/items/{id}*', '/ui/search', '/ui/items/new*'],
                    'new' => ['perm' => 'items.create', 'href' => '/ui/items/new', 'label' => 'add_item']],
                ['key' => 'by_hand', 'perm' => 'items.create', 'path' => '/ui/items/new', 'query' => ['show' => 'all'],
                    'match' => ['/ui/items/new?show=all', '/ui/items/new?show=waiting'], 'new' => ['perm' => 'items.create', 'href' => '/ui/items/new', 'label' => 'add_item']],
            ]],
            'families' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/items/families', 'match' => ['/ui/items/families*'],
                'new' => ['perm' => 'families.manage', 'href' => '/ui/items/families#new', 'label' => 'families']]]],
            'multipacks' => ['pages' => [
                ['key' => 'packs', 'perm' => 'catalogue.view', 'path' => '/ui/items/packs', 'match' => ['/ui/items/packs', '/ui/items/packs/{id}*'],
                    'new' => ['perm' => 'catalogue.edit', 'href' => '/ui/items/packs#new', 'label' => 'packs']],
                ['key' => 'pack_links', 'perm' => 'linking.view', 'path' => '/ui/items/packs/links', 'match' => ['/ui/items/packs/links*']],
            ]],
            'barcodes' => ['pages' => [['perm' => 'catalogue.edit', 'path' => '/ui/items/barcodes', 'match' => ['/ui/items/barcodes*'], 'badge' => 'barcodes_open']]],
        ]],
        // Inventory: stock_balance and the records that move it. The people who read records (documents.view) see the records' lists; the
        // create buttons are for the people who keep them (doc.<TYPE>.post). Adjustments holds the four kinds of stock change (corrections,
        // stock in, stock out, releases from another account) as its Show choices; the balance owed to another account stays with
        // Transfers (docs/decisions.md RD4).
        'inventory' => ['tabs' => [
            'stock' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock', 'match' => ['/ui/stock']]]],
            'adjustments' => ['pages' => [
                ['key' => 'adjustments', 'perm' => 'documents.view', 'path' => '/ui/stock/adjustments', 'match' => ['/ui/stock/adjustments*'],
                    'badge' => 'adjustments_open', 'new' => ['perm' => 'doc.ADJ.post', 'href' => '/ui/stock/adjustments#new', 'label' => 'adjustments']],
                ['key' => 'stock_in', 'perm' => 'documents.view', 'path' => '/ui/stock/in', 'match' => ['/ui/stock/in', '/ui/stock/in.csv', '/ui/stock/in/*'],
                    'badge' => 'stock_in_open', 'new' => ['perm' => 'doc.SIN.post', 'href' => '/ui/stock/in#new', 'label' => 'stock_in']],
                ['key' => 'stock_out', 'perm' => 'documents.view', 'path' => '/ui/stock/out', 'match' => ['/ui/stock/out', '/ui/stock/out.csv', '/ui/stock/out/*'],
                    'badge' => 'stock_out_open', 'new' => ['perm' => 'doc.SOUT.post', 'href' => '/ui/stock/out#new', 'label' => 'stock_out']],
                ['key' => 'releases', 'perm' => 'documents.view', 'path' => '/ui/stock/releases', 'match' => ['/ui/stock/releases*'], 'badge' => 'releases_open',
                    'new' => ['perm' => 'doc.REL.post', 'href' => '/ui/stock/releases#new', 'label' => 'releases']],
            ]],
            'transfers' => ['pages' => [
                ['key' => 'transfers', 'perm' => 'documents.view', 'path' => '/ui/stock/transfers', 'match' => ['/ui/stock/transfers*'], 'badge' => 'transfers_open',
                    'new' => ['perm' => 'doc.TRF.post', 'href' => '/ui/stock/transfers#new', 'label' => 'transfers']],
                ['key' => 'accounts', 'perm' => 'documents.view', 'path' => '/ui/stock/accounts', 'match' => ['/ui/stock/accounts*']],
            ]],
            // Stock counts (pack A2a; docs/decisions.md CT14): what to count next, the count sheets, the differences to check. The people
            // who read records look (documents.view); the people who count open, enter, post and clear (doc.CNT.post).
            'counts' => ['pages' => [
                ['key' => 'counts', 'perm' => 'documents.view', 'path' => '/ui/stock/counts', 'match' => ['/ui/stock/counts'],
                    'new' => ['perm' => 'doc.CNT.post', 'href' => '/ui/stock/counts#new', 'label' => 'counts']],
                ['key' => 'count_sheets', 'perm' => 'documents.view', 'path' => '/ui/stock/counts/sheets', 'match' => ['/ui/stock/counts/sheets*', '/ui/stock/counts/{id}*']],
                ['key' => 'count_differences', 'perm' => 'documents.view', 'path' => '/ui/stock/counts/differences', 'match' => ['/ui/stock/counts/differences*'],
                    'badge' => 'counts_open'],
            ]],
            // Pack B (PB9): a Repack breaks packs into singles or makes packs; documents.view reads, doc.RPK.post books.
            'repack' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/repack', 'match' => ['/ui/stock/repack*'],
                'new' => ['perm' => 'doc.RPK.post', 'href' => '/ui/stock/repack#new', 'label' => 'repack']]]],
            // Reserved stock (docs/decisions.md RS1): the stock kept for the stores' orders, read only.
            'reservations' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/reservations', 'match' => ['/ui/stock/reservations*']]]],
            'movements' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/movements', 'match' => ['/ui/stock/movements*']]]],
        ]],
        'purchases' => ['tabs' => [
            'suppliers' => ['pages' => [['perm' => 'suppliers.view', 'path' => '/ui/purchasing/suppliers',
                'match' => ['/ui/purchasing/suppliers*', '/ui/purchasing/supplier-items*'],
                'new' => ['perm' => 'suppliers.manage', 'href' => '/ui/purchasing/suppliers/new', 'label' => 'suppliers']]]],
            'orders' => ['pages' => [['perm' => 'purchasing.view', 'path' => '/ui/purchasing/orders', 'match' => ['/ui/purchasing/orders*'],
                'new' => ['perm' => 'doc.PO.post', 'href' => '/ui/purchasing/orders/new', 'label' => 'orders']]]],
            // Goods received: the deliveries, the bench check, the issues, the duty register (pack H, PH15) and the goods of final supplier
            // invoices on their way (pack J, PJ14).
            'goods_in' => ['pages' => [
                ['key' => 'receiving', 'perm' => 'receiving.view', 'path' => '/ui/receiving', 'match' => ['/ui/receiving', '/ui/receiving/*'],
                    'new' => ['perm' => 'doc.GRN.post', 'href' => '/ui/receiving/new', 'label' => 'receiving']],
                ['key' => 'bench', 'perm' => 'doc.GRN.post', 'path' => '/ui/receiving/bench', 'match' => ['/ui/receiving/bench', '/ui/receiving/{id}/bench']],
                ['key' => 'incidents', 'perm' => 'incidents.view', 'path' => '/ui/receiving/incidents', 'match' => ['/ui/receiving/incidents*'],
                    'badge' => 'incidents_open'],
                ['key' => 'duty', 'perm' => 'duty.view', 'path' => '/ui/receiving/duty', 'match' => ['/ui/receiving/duty*']],
                ['key' => 'arriving', 'perm' => 'receiving.view', 'path' => '/ui/receiving/arriving', 'match' => ['/ui/receiving/arriving*'], 'badge' => 'arrivals_late'],
            ]],
            // --- Pack C (IM7, docs/decisions.md PC17): supplier invoices (the invoices, "To match", the deliveries not invoiced yet, the
            // timed drafts of pack J, PJ10); returns to suppliers and the supplier's credit notes.
            'invoices' => ['pages' => [
                ['key' => 'invoices', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices',
                    'match' => ['/ui/purchasing/invoices', '/ui/purchasing/invoices/{id}*', '/ui/purchasing/invoices/from-receipt', '/ui/purchasing/invoices/new',
                        '/ui/purchasing/invoices/products'],
                    // The supplier invoice on one page (docs/decisions.md PV1): "New supplier invoice" opens the page itself.
                    'new' => ['perm' => 'doc.SINV.post', 'href' => '/ui/purchasing/invoices/new', 'label' => 'invoices']],
                ['key' => 'to_match', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/to-match', 'match' => ['/ui/purchasing/invoices/to-match*'],
                    'badge' => 'invoices_to_match'],
                ['key' => 'not_invoiced', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/not-invoiced', 'match' => ['/ui/purchasing/invoices/not-invoiced*']],
                ['key' => 'timed', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/invoices/timed', 'match' => ['/ui/purchasing/invoices/timed*'],
                    'badge' => 'invoices_timed_failed'],
            ]],
            'returns' => ['pages' => [
                ['key' => 'returns', 'perm' => 'returns.view', 'path' => '/ui/purchasing/returns', 'match' => ['/ui/purchasing/returns*'],
                    'new' => ['perm' => 'doc.DN.post', 'href' => '/ui/purchasing/returns#new', 'label' => 'returns']],
                ['key' => 'credit_notes', 'perm' => 'invoices.view', 'path' => '/ui/purchasing/credit-notes', 'match' => ['/ui/purchasing/credit-notes*'],
                    'new' => ['perm' => 'doc.SCN.post', 'href' => '/ui/purchasing/credit-notes#new', 'label' => 'credit_notes']],
            ]],
            // --- end of pack C
            'reorder' => ['pages' => [
                ['key' => 'reorder', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder', 'match' => ['/ui/purchasing/reorder*']],
                ['key' => 'brands', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/brands', 'match' => ['/ui/purchasing/reorder/brands*']],
                ['key' => 'anomalies', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/anomalies', 'match' => ['/ui/purchasing/reorder/anomalies*']],
                ['key' => 'sales_history', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/sales-history', 'match' => ['/ui/purchasing/sales-history*']],
                // --- Pack G (docs/decisions.md PG9): the suppliers' spare days and the low-stock warnings CW keeps for the websites.
                ['key' => 'reorder_suppliers', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/suppliers', 'match' => ['/ui/purchasing/reorder/suppliers*']],
                ['key' => 'low_stock_levels', 'perm' => 'reorder.view', 'path' => '/ui/purchasing/reorder/low-stock', 'match' => ['/ui/purchasing/reorder/low-stock*']],
                // --- end of Pack G
            ]],
        ]],
        // ---- Pack D (docs/decisions.md PD1, PD2): Sales, read only: each store's orders (and one order's page), what waits to be sent and
        // what came back or was cancelled; the store chosen travels through the three (`keep`). Pack H (0038, PH13): the trade sales (the
        // hand-overs to sister shops, our shop units and trade customers), and the customers with their prices and what each owes us. ----
        'sales' => ['tabs' => [
            'sales_orders' => ['keep' => ['channel'], 'pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales', 'match' => ['/ui/stock/sales', '/ui/stock/sales.csv',
                '/ui/stock/sales/{id}']]]],
            'sales_dispatch' => ['keep' => ['channel'], 'pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales/dispatch', 'match' => ['/ui/stock/sales/dispatch*']]]],
            'sales_returns' => ['keep' => ['channel'], 'pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales/returns', 'match' => ['/ui/stock/sales/returns*']]]],
            'handovers' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/handovers', 'match' => ['/ui/stock/handovers', '/ui/stock/handovers.csv',
                '/ui/stock/handovers/{id}*'], 'new' => ['perm' => 'doc.TRD.post', 'href' => '/ui/stock/handovers#new', 'label' => 'handovers']]]],
            'customers' => ['pages' => [
                ['key' => 'handover_customers', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/customers', 'match' => ['/ui/stock/handovers/customers*'],
                    'new' => ['perm' => 'handovers.customers', 'href' => '/ui/stock/handovers/customers#new', 'label' => 'handover_customers']],
                ['key' => 'handover_balances', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/balances', 'match' => ['/ui/stock/handovers/balances*']],
            ]],
        ]],
        // ---- end of pack D ----
        // Store listings: how each store's products match the warehouse's items. By item (the Product matching page, whose tabs are By item,
        // Simple matching and New on the stores: docs/decisions.md RD-S1) and Check a match (the queue to review and a store product's decision
        // page) are its entries; every other matching page is a specialist page (`more`), under the header's More of the section's lists (RD6).
        'listings' => ['tabs' => [
            'by_product' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/review/products', 'match' => ['/ui/review/products*', '/ui/review/batches/*?from=item', '/ui/match*']]]],
            'review' => ['keep' => ['channel'], 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review',
                'match' => ['/ui/review', '/ui/review/listing/*', '/ui/review/decision/*', '/ui/review/bulk', '/ui/review/batches/*']]]],
            'store_products' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/store', 'match' => ['/ui/review/store*']]]],
            'samples' => ['more' => true, 'keep' => ['channel'], 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/samples', 'match' => ['/ui/review/samples*']]]],
            'pending' => ['more' => true, 'keep' => ['channel'], 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review', 'query' => ['queue' => 'pending'],
                'match' => ['/ui/review?queue=pending'], 'badge' => 'linking_pending']]],
            'new_variants' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/new-variants', 'match' => ['/ui/review/new-variants*']]]],
            'duplicates' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/duplicates', 'match' => ['/ui/review/duplicates*'],
                'badge' => 'linking_duplicates']]],
        ]],
        // ---- Pack E (docs/decisions.md PE1): the reports, read only, each with its CSV; the valuation is money (values.view).
        'reports' => ['gap' => true, 'tabs' => [
            'stock_value' => ['pages' => [['perm' => 'values.view', 'path' => '/ui/reports/stock-value', 'match' => ['/ui/reports/stock-value*']]]],
            'stock_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/stock-by-store', 'match' => ['/ui/reports/stock-by-store*']]]],
            'low_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/low-stock', 'match' => ['/ui/reports/low-stock*']]]],
            'slow_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/slow-stock', 'match' => ['/ui/reports/slow-stock*']]]],
            'sales_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/sales-by-store', 'match' => ['/ui/reports/sales-by-store*']]]],
        ]],
        // Settings: ONE entry for every settings page (no sub-items); each page one of its choices, the audit log last (docs/decisions.md RD7).
        'settings' => ['tabs' => [
            'settings' => ['pages' => [
                ['key' => 'company', 'perm' => 'reference.view', 'path' => '/ui/reference/company', 'match' => ['/ui/reference/company*']],
                ['key' => 'warehouses', 'perm' => 'reference.view', 'path' => '/ui/reference/warehouses', 'match' => ['/ui/reference/warehouses*'],
                    'new' => ['perm' => 'settings.manage', 'href' => '/ui/reference/warehouses#new', 'label' => 'warehouses']],
                ['key' => 'sites', 'perm' => 'system.view', 'path' => '/ui/system/sites', 'match' => ['/ui/system/sites*']],
                ['key' => 'people', 'perm' => 'staff.view', 'path' => '/ui/people', 'match' => ['/ui/people*'],
                    'new' => ['perm' => 'staff.manage', 'href' => '/ui/people#new', 'label' => 'people']],
                ['key' => 'access', 'perm' => 'reference.view', 'path' => '/ui/reference/access', 'match' => ['/ui/reference/access']],
                ['key' => 'approvals', 'perm' => 'reference.view', 'path' => '/ui/reference/approvals', 'match' => ['/ui/reference/approvals*']],
                ['key' => 'reasons', 'perm' => 'reference.view', 'path' => '/ui/reference/reasons', 'match' => ['/ui/reference/reasons*'],
                    'new' => ['perm' => 'settings.manage', 'href' => '/ui/reference/reasons#new', 'label' => 'reasons']],
                ['key' => 'settings', 'perm' => 'reference.view', 'path' => '/ui/reference/settings', 'match' => ['/ui/reference/settings*']],
                ['key' => 'series', 'perm' => 'reference.view', 'path' => '/ui/reference/series', 'match' => ['/ui/reference/series*']],
                ['key' => 'integrity', 'perm' => 'system.view', 'path' => '/ui/system/checks', 'match' => ['/ui/system/checks*']],
                ['key' => 'audit', 'perm' => 'audit.view', 'path' => '/ui/system/audit', 'match' => ['/ui/system/audit*']],
            ]],
        ]],
    ];

    /**
     * The phone's bottom bar (Phone.dc.html): Home, Receive (Goods received), Count (Stock counts), Approvals, then More (the whole
     * sidebar). bar entry => [section, entry]: an entry of a section, or the whole section (null); each only for the people who may open it.
     */
    public const PHONE = [
        'home' => ['home', null],
        'receive' => ['purchases', 'goods_in'],
        'count' => ['inventory', 'counts'],
        'approvals' => ['approvals', null],
    ];

    /** The sprite's icon of each section (its chip in the sidebar) and of each phone bar entry (ico-<name>: no underscore). */
    public const ICON = [
        'home' => 'sec-home', 'approvals' => 'sec-approvals', 'items' => 'sec-items', 'inventory' => 'sec-inventory', 'purchases' => 'sec-purchases',
        'sales' => 'sec-sales', 'listings' => 'sec-listings', 'reports' => 'sec-reports', 'settings' => 'sec-settings',
        'receive' => 'receive', 'count' => 'count', 'more' => 'dots',
    ];

    /** The sections whose list pages keep their figure tiles (the owner's rule of 30 Oct 2026: figures only on Home and the reports). */
    public const FIGURES = ['home', 'reports'];

    /**
     * Where a request belongs: the section, entry ("tab") and page whose `match` fits the path (and query) best, or null (the password
     * page, a page outside every section). Permissions play no part: a page refused with 403 still belongs where it is.
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
                foreach ($tab['pages'] as $page) {
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
     * The sections, entries and pages a person may open (the map, filtered), in the map's order.
     *
     * @param list<string> $roles
     * @return array<string, array<string, array{more: bool, pages: list<array<string, mixed>>}>> section => entry => its pages
     */
    public static function visible(array $roles): array
    {
        $out = [];
        foreach (self::MAP as $s => $section) {
            $tabs = [];
            foreach ($section['tabs'] as $t => $tab) {
                $pages = [];
                foreach ($tab['pages'] as $page) {
                    if (self::may($roles, $page['perm'])) {
                        $pages[] = ['key' => (string) ($page['key'] ?? $t)] + $page;
                    }
                }
                if ($pages !== []) {
                    $tabs[$t] = ['more' => ($tab['more'] ?? false) === true, 'pages' => $pages];
                }
            }
            if ($tabs !== []) {
                $out[$s] = $tabs;
            }
        }
        return $out;
    }

    /**
     * What a person may use, section by section in the map's order: each section with its entries, each entry with the link of the
     * first page the person may open. No roles, no menu. (The tests read who sees what here.)
     *
     * @param list<string> $roles
     * @return list<array{section: string, key: string, items: list<array{key: string, label: string, path: string, query: array<string, string>, more: bool}>}>
     */
    public static function menu(array $roles): array
    {
        $out = [];
        foreach (self::visible($roles) as $s => $tabs) {
            $items = [];
            foreach ($tabs as $t => $tab) {
                $first = $tab['pages'][0];
                $items[] = ['key' => $t, 'label' => Words::MENU[$t], 'path' => (string) $first['path'], 'query' => $first['query'] ?? [], 'more' => $tab['more']];
            }
            $out[] = ['section' => Words::SECTION[$s], 'key' => $s, 'items' => $items];
        }
        return $out;
    }

    /** Whether a link to $to leads to the page at $path (the same path, whatever the query: a filter of the page is still the page). */
    private static function samePath(string $path, string $to): bool
    {
        return rtrim($path, '/') === rtrim($to, '/');
    }

    /**
     * Everything the layout draws around a page for this person (Context::frame):
     *   nav       the sidebar (Sidebar.dc.html): one entry per section the person has, in the map's order: its key, label, icon, link (its
     *             first entry's first page), count (the sum of its entries', or its `count` page's), `gap` (a divider above it), whether it
     *             is current (the section of the page shown) and `subs`: the current section's entries (not its specialist pages) when the
     *             map gives it more than one: each with its link, count, `current` and `here` (the page shown is the entry's own link: no
     *             link, CV13). ONE section is current; only it shows its entries; no third level (its Show choices are on the page)
     *   pagebar   the current section when the person has it (Home has none): key, label, the current entry (`tab`), and `more`: the
     *             section's specialist pages the person may open (Store listings: simple matching, store products, ...), but the one
     *             shown, on the section's own list pages only (the header's More lists them); none elsewhere
     *   segments  the current entry's Show choices (two or more pages the person may open), or []: key, label, link, count, `current`
     *             (the page shown or the page a record belongs to) and `here` (the page shown is the choice itself)
     *   actions   the current page's create button the person may use (only on the page itself, not its detail pages); none on Home
     *   list      whether the page shown is a list (an entry's own page) outside Home and the reports: its figure tiles are not drawn
     *   phone     the bottom bar: Home, Receive, Count, Approvals (the ones the person has), More (#menu)
     *   bell      Approvals › Waiting for you and its count, for the people who answer there; else null
     *
     * @param list<string> $roles
     * @param array<string, int> $badges Context::badges()
     * @param array<string, mixed> $query
     * @return array{nav: list<array<string, mixed>>, pagebar: ?array<string, mixed>, segments: list<array<string, mixed>>, actions: list<array<string, string>>, list: bool, phone: list<array<string, mixed>>, bell: ?array<string, mixed>, here: ?array{section: string, tab: string, page: string}}
     */
    public static function frame(array $roles, array $badges, string $path, array $query = []): array
    {
        $here = self::locate($path, $query);
        $visible = self::visible($roles);
        $inSection = static fn (string $s): bool => $here !== null && $here['section'] === $s;
        // The query values the current entry keeps, carried to another entry of its section that keeps them too (a store's code).
        $keeps = $here !== null ? (self::MAP[$here['section']]['tabs'][$here['tab']]['keep'] ?? []) : [];
        $carry = static fn (string $s, string $t): array => $inSection($s)
            ? self::kept(array_values(array_intersect(self::MAP[$s]['tabs'][$t]['keep'] ?? [], $keeps)), $query) : [];

        $tabsOf = [];
        foreach ($visible as $s => $tabs) {
            foreach ($tabs as $t => $tab) {
                [$count, $words] = self::count($tab['pages'], $badges, self::MAP[$s]['count'] ?? null);
                $first = $tab['pages'][0];
                $current = $inSection($s) && $here['tab'] === $t;
                $tabsOf[$s][$t] = ['key' => $t, 'label' => Words::MENU[$t], 'href' => (string) $first['path'], 'query' => ($first['query'] ?? []) + $carry($s, $t),
                    'count' => $count, 'countWords' => $words, 'current' => $current, 'here' => $current && $here['page'] === $first['key']
                    && self::samePath($path, (string) $first['path']), 'more' => $tab['more'], 'help' => Words::MENU_HELP[$t]];
            }
        }

        // The sidebar: one entry per section; the current one (the page's section) lists its entries, never its specialist pages.
        $nav = [];
        foreach ($tabsOf as $s => $list) {
            $main = array_values(array_filter($list, static fn (array $t): bool => !$t['more']));
            $first = $main[0] ?? array_values($list)[0];
            $count = (int) array_sum(array_column($list, 'count'));
            // One entry counted: its words (the inbox's, AV10); several: "waiting for you".
            $counted = array_values(array_filter($list, static fn (array $t): bool => $t['count'] > 0));
            $words = count($counted) === 1 ? $counted[0]['countWords'] : Words::BADGE['section'];
            $mainInMap = count(array_filter(self::MAP[$s]['tabs'], static fn (array $t): bool => ($t['more'] ?? false) !== true));
            $current = $inSection($s);
            $nav[] = ['key' => $s, 'label' => Words::SECTION[$s], 'icon' => self::ICON[$s], 'href' => $first['href'], 'query' => $first['query'],
                'count' => $count, 'countWords' => $words, 'gap' => (self::MAP[$s]['gap'] ?? false) === true, 'current' => $current,
                'open' => $current && $mainInMap > 1, 'subs' => $current && $mainInMap > 1 ? array_map(static fn (array $t): array => array_diff_key($t, ['help' => 1]), $main) : []];
        }

        $pagebar = null;
        $segments = [];
        $actions = [];
        $list = false;
        if ($here !== null && isset($visible[$here['section']]) && $here['section'] !== 'home') {
            $s = $here['section'];
            $tab = $visible[$s][$here['tab']] ?? null;
            $page = null;
            foreach ($tab['pages'] ?? [] as $p) {
                if ($p['key'] === $here['page']) {
                    $page = $p;
                }
            }
            // A list page: the request is the page's own address (not one of its records or forms).
            $onList = $page !== null && self::samePath($path, (string) $page['path']);
            $list = $onList && !in_array($s, self::FIGURES, true);
            if ($tab !== null && count($tab['pages']) > 1) {
                $kept = self::kept(self::MAP[$s]['tabs'][$here['tab']]['keep'] ?? [], $query);
                foreach ($tab['pages'] as $p) {
                    [$count, $words] = self::count([$p], $badges);
                    $segments[] = ['key' => (string) $p['key'], 'label' => Words::SEGMENT[$p['key']], 'href' => (string) $p['path'],
                        'query' => ($p['query'] ?? []) + $kept, 'count' => $count, 'countWords' => $words, 'current' => $here['page'] === $p['key'],
                        'here' => $here['page'] === $p['key'] && self::samePath($path, (string) $p['path'])];
                }
            }
            $more = [];
            if ($onList) {
                foreach ($tabsOf[$s] as $t) {
                    if ($t['more'] && !$t['current']) {
                        $more[] = $t;
                    }
                }
            }
            $pagebar = ['key' => $s, 'label' => Words::SECTION[$s], 'tab' => $here['tab'], 'tabLabel' => Words::MENU[$here['tab']], 'more' => $more,
                'moreCount' => (int) array_sum(array_column($more, 'count'))];
            $new = $page['new'] ?? null;
            if ($onList && $new !== null && Permissions::can($roles, $new['perm'])) {
                $actions[] = ['label' => Words::NEW[$new['label']], 'href' => (string) $new['href']];
            }
        }

        // The phone's bar: Home, the PHONE entries the person has, then More (the whole sidebar).
        $phone = [];
        if (isset($tabsOf['home'])) {
            $sections = array_column($nav, null, 'key');
            foreach (self::PHONE as $key => [$s, $t]) {
                $to = $t === null ? ($sections[$s] ?? null) : ($tabsOf[$s][$t] ?? null);
                if ($to === null) {
                    continue;
                }
                $phone[] = ['key' => $key, 'label' => Words::NAV[$key], 'icon' => self::ICON[$key], 'href' => $to['href'], 'query' => $to['query'],
                    'count' => $key === 'home' ? 0 : $to['count'], 'countWords' => $to['countWords'], 'current' => $t === null ? $inSection($s) : $to['current']];
            }
            $phone[] = ['key' => 'more', 'label' => Words::NAV['more'], 'icon' => self::ICON['more'], 'href' => '#menu', 'query' => [], 'count' => 0, 'countWords' => '',
                'current' => false];
        }
        $inbox = $tabsOf['approvals']['inbox'] ?? null;
        $bell = null;
        if ($inbox !== null && self::may($roles, Controller\InboxController::PERMS)) {
            $bell = ['href' => '/ui/approvals', 'query' => [], 'count' => $inbox['count'], 'countWords' => $inbox['countWords']];
        }
        return ['nav' => $nav, 'pagebar' => $pagebar, 'segments' => $segments, 'actions' => $actions, 'list' => $list, 'phone' => $phone, 'bell' => $bell,
            'here' => $here];
    }

    /**
     * The query values an entry keeps from the request (a store's code): a plain code only, the pages check it names a store.
     *
     * @param list<string> $keys
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private static function kept(array $keys, array $query): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $query[$k] ?? null;
            if (is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $v) === 1) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * The count of an entry or a choice: the sum of its pages' badges (or only $inbox's, the page whose count holds the others' records,
     * when the person has it: AV10), and the words a screen reader hears after it (the badge's own words when one page has a count,
     * else "waiting for you").
     *
     * @param list<array<string, mixed>> $pages
     * @param array<string, int> $badges
     * @return array{0: int, 1: string}
     */
    private static function count(array $pages, array $badges, ?string $inbox = null): array
    {
        foreach ($pages as $page) {
            if ($inbox !== null && ($page['key'] ?? null) === $inbox) {
                $pages = [$page];
                break;
            }
        }
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

    /** Whether the roles may open a page: its permission, or any of its list of permissions. @param list<string> $roles @param string|list<string> $perm */
    public static function may(array $roles, string|array $perm): bool
    {
        foreach ((array) $perm as $p) {
            if (Permissions::can($roles, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> every badge name the map uses */
    public static function badgeNames(): array
    {
        $out = [];
        foreach (self::MAP as $section) {
            foreach ($section['tabs'] as $tab) {
                foreach ($tab['pages'] as $page) {
                    if (isset($page['badge'])) {
                        $out[] = (string) $page['badge'];
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }
}
