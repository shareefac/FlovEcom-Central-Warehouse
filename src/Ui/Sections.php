<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;

/**
 * THE navigation map of the staff screens (owner's request of 8 Oct 2026: "sidebar name make professional and make related tabs";
 * design v3; the shorter menu of 29 Oct 2026, docs/decisions.md HV1-HV6): the sections, the tabs inside each and the segmented filter
 * inside a tab. The sidebar, the tab bar, the segmented filter, the phone bar, the bell and the tests all read this map; no view names a
 * page itself.
 *
 *   section => ['tabs' => [tab => tab], 'count'?: tab, 'keep'?: list<string>]   its words are Words::SECTION and SECTION_DESC; `count`:
 *                                                          the tab whose count is the section's (an inbox of the others' records), else
 *                                                          their sum; `keep`: the query values (a store, `channel`) its tabs carry
 *   tab     => ['pages' => list<page>, 'segments'?: true, 'keep'?: list<string>, 'more'?: true]   a tab; its name is Words::MENU[tab];
 *                                                          `keep`: the query values its segments carry from the current request;
 *                                                          `more`: a set-up, import or specialist page, drawn under the tab row's More
 *           |  ['soon' => true, 'more'?: true]                 a tab not built yet: drawn disabled with a "Soon" chip, never a link
 *   page    => ['perm', 'path', 'query'?, 'match', 'badge'?, 'key'?, 'new'?]
 *
 * A page is one GET route: `perm` is exactly the permission the route checks (Ui\Kernel), so a link is shown to exactly the people
 * the route lets in (Tests\Unit\SectionsTest); a list of permissions (any of them) is a route open to every signed-in person whose
 * page refuses the others itself (Approvals › Waiting for you). A tab shows when the person may open at least one of its pages, and links to the
 * first of them; a section shows when one of its tabs does. In a tab with `segments` each page is a segment named
 * Words::SEGMENT[key]. `match` lists the request paths that belong to the page (detail pages, forms sent back with an error):
 * `{id}` is a number, a trailing `*` any rest, `?k=v` a query value; the most specific pattern wins (locate()). `badge` names a count
 * of Ui\Context::badges() (only what this person can act on). `new` is the page's create action, drawn as the toolbar's primary
 * button on that page: [perm, href, Words::NEW key].
 *
 * The sidebar (HV2) is not the map's order: Home, then "Every day" (DAILY: a tab or a whole section each, Words::NAV), then "More"
 * (MORE: the other sections). The phone's bottom bar is Home, PHONE, then More (the whole sidebar).
 */
final class Sections
{
    public const MAP = [
        'dashboard' => ['tabs' => [
            'home' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/', 'match' => ['/ui', '/ui/']]]],
        ]],
        // Products (HV3): the warehouse's items; making them by hand, the families and the multipacks are set-up pages under the row's More.
        'products' => ['tabs' => [
            'cards' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/items/cards', 'match' => ['/ui/items/cards*', '/ui/items/{id}*', '/ui/search']]]],
            'barcodes' => ['pages' => [['perm' => 'catalogue.edit', 'path' => '/ui/items/barcodes', 'match' => ['/ui/items/barcodes*'], 'badge' => 'barcodes_open']]],
            // ---- Pack B (docs/decisions.md PB1-PB8): an item made by hand (Q10), product families (Q4), pre-packed multipacks and the pack
            // links beside them (Q3). Families and pack items are read by everyone who sees products; the pack links are matching pages.
            'add_item' => ['more' => true, 'pages' => [['perm' => 'items.create', 'path' => '/ui/items/new', 'match' => ['/ui/items/new*'],
                'new' => ['perm' => 'items.create', 'href' => '/ui/items/new#new', 'label' => 'add_item']]]],
            'families' => ['more' => true, 'pages' => [['perm' => 'catalogue.view', 'path' => '/ui/items/families', 'match' => ['/ui/items/families*'],
                'new' => ['perm' => 'families.manage', 'href' => '/ui/items/families#new', 'label' => 'families']]]],
            'multipacks' => ['more' => true, 'segments' => true, 'pages' => [
                ['key' => 'packs', 'perm' => 'catalogue.view', 'path' => '/ui/items/packs', 'match' => ['/ui/items/packs', '/ui/items/packs/{id}*'],
                    'new' => ['perm' => 'catalogue.edit', 'href' => '/ui/items/packs#new', 'label' => 'packs']],
                ['key' => 'pack_links', 'perm' => 'linking.view', 'path' => '/ui/items/packs/links', 'match' => ['/ui/items/packs/links*']],
            ]],
            // ---- end of pack B
        ]],
        // Matching (HV3): how each store's products match the warehouse's items, out of Products (it had 11 tabs). The specialist pages
        // (By Item, New Variants, Duplicates) are under the row's More.
        'matching' => ['tabs' => [
            // Simple matching (docs/decisions.md SM6; the owner's request of 25 Oct 2026): the first of the matching tabs, for everyone with
            // linking.view; its choices and its Apply are routes of the people who may decide (Ui\Kernel).
            'simple_match' => ['pages' => [['perm' => 'linking.view', 'path' => '/ui/match', 'match' => ['/ui/match*']]]],
            // To review opens the "By store" overview (U107), the matching progress (HV5); the store chosen there travels through the
            // segments (`keep`, U106).
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
            'by_product' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/products',
                'match' => ['/ui/review/products*', '/ui/review/batches/*?from=item']]]],
            // One store's variants first seen in a period, its CSV, and the seed "Create as new items" on every store (U131).
            'new_variants' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/new-variants', 'match' => ['/ui/review/new-variants*']]]],
            'duplicates' => ['more' => true, 'pages' => [['perm' => 'linking.view', 'path' => '/ui/review/duplicates', 'match' => ['/ui/review/duplicates*'],
                'badge' => 'linking_duplicates']]],
        ]],
        // Overview and Movements: stock_balance and the stock ledger (the item page's own figures: catalogue.view). The stock records of
        // pack A1 (docs/decisions.md SO10): the people who read records (documents.view) see their lists; the create buttons are for the
        // people who keep them (doc.<TYPE>.post). The other account's room (VPG 2) is the Transfers tab's "Balance owed". Reservations
        // (docs/decisions.md RS1): the stock kept for the stores' orders, read only, for the same people as Overview and Movements. The
        // hand-overs, transfers, reservations and repacks are specialist pages under the row's More (HV3).
        'stock' => ['tabs' => [
            'stock' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock', 'match' => ['/ui/stock']]]],
            'movements' => ['pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/movements', 'match' => ['/ui/stock/movements*']]]],
            'stock_in' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/in', 'match' => ['/ui/stock/in', '/ui/stock/in.csv', '/ui/stock/in/*'],
                'badge' => 'stock_in_open', 'new' => ['perm' => 'doc.SIN.post', 'href' => '/ui/stock/in#new', 'label' => 'stock_in']]]],
            'stock_out' => ['pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/out', 'match' => ['/ui/stock/out', '/ui/stock/out.csv', '/ui/stock/out/*'],
                'badge' => 'stock_out_open', 'new' => ['perm' => 'doc.SOUT.post', 'href' => '/ui/stock/out#new', 'label' => 'stock_out']]]],
            // ---- Pack H (0038; docs/decisions.md PH13): hand-overs to sister shops, our shop units and trade customers (IM11), the
            // customers with their remembered prices, and what each owes us. The people who read records look (documents.view). ----
            'handovers' => ['more' => true, 'segments' => true, 'pages' => [
                ['key' => 'handovers', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers', 'match' => ['/ui/stock/handovers', '/ui/stock/handovers.csv',
                    '/ui/stock/handovers/{id}*'], 'new' => ['perm' => 'doc.TRD.post', 'href' => '/ui/stock/handovers#new', 'label' => 'handovers']],
                ['key' => 'handover_customers', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/customers', 'match' => ['/ui/stock/handovers/customers*'],
                    'new' => ['perm' => 'handovers.customers', 'href' => '/ui/stock/handovers/customers#new', 'label' => 'handover_customers']],
                ['key' => 'handover_balances', 'perm' => 'documents.view', 'path' => '/ui/stock/handovers/balances', 'match' => ['/ui/stock/handovers/balances*']],
            ]],
            // ---- end of pack H ----
            'transfers' => ['more' => true, 'segments' => true, 'pages' => [
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
            'reservations' => ['more' => true, 'pages' => [['perm' => 'catalogue.view', 'path' => '/ui/stock/reservations', 'match' => ['/ui/stock/reservations*']]]],
            'quality' => ['soon' => true, 'more' => true],
            // ---- Pack B (PB9): a Repack breaks packs into singles or makes packs; documents.view reads, doc.RPK.post books.
            'repack' => ['more' => true, 'pages' => [['perm' => 'documents.view', 'path' => '/ui/stock/repack', 'match' => ['/ui/stock/repack*'],
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
                'new' => ['perm' => 'doc.PO.post', 'href' => '/ui/purchasing/orders/new', 'label' => 'orders']]]],
            'goods_in' => ['segments' => true, 'pages' => [
                ['key' => 'receiving', 'perm' => 'receiving.view', 'path' => '/ui/receiving', 'match' => ['/ui/receiving', '/ui/receiving/*'],
                    'new' => ['perm' => 'doc.GRN.post', 'href' => '/ui/receiving/new', 'label' => 'receiving']],
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
                    'match' => ['/ui/purchasing/invoices', '/ui/purchasing/invoices/{id}*', '/ui/purchasing/invoices/from-receipt', '/ui/purchasing/invoices/new',
                        '/ui/purchasing/invoices/products'],
                    // The supplier invoice on one page (docs/decisions.md PV1): "New supplier invoice" opens the page itself.
                    'new' => ['perm' => 'doc.SINV.post', 'href' => '/ui/purchasing/invoices/new', 'label' => 'invoices']],
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
        // ---- Pack D (docs/decisions.md PD1, PD2): Sales, read only, its own section since HV3 (it was a tab of Stock). Each store's orders
        // (and one order's page), what waits to be sent, and what came back or was cancelled; the store chosen travels through the
        // three tabs (the section's `keep`). ----
        'sales' => ['keep' => ['channel'], 'tabs' => [
            'sales_orders' => ['pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales', 'match' => ['/ui/stock/sales', '/ui/stock/sales.csv',
                '/ui/stock/sales/{id}']]]],
            'sales_dispatch' => ['pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales/dispatch', 'match' => ['/ui/stock/sales/dispatch*']]]],
            'sales_returns' => ['pages' => [['perm' => 'sales.view', 'path' => '/ui/stock/sales/returns', 'match' => ['/ui/stock/sales/returns*']]]],
        ]],
        // ---- end of pack D ----
        'reports' => ['tabs' => [
            // ---- Pack E (docs/decisions.md PE1): the reports, read only, each with its CSV; Stock Value is money (values.view).
            'stock_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/stock-by-store', 'match' => ['/ui/reports/stock-by-store*']]]],
            'sales_by_store' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/sales-by-store', 'match' => ['/ui/reports/sales-by-store*']]]],
            'low_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/low-stock', 'match' => ['/ui/reports/low-stock*']]]],
            'stock_value' => ['pages' => [['perm' => 'values.view', 'path' => '/ui/reports/stock-value', 'match' => ['/ui/reports/stock-value*']]]],
            'slow_stock' => ['pages' => [['perm' => 'reports.view', 'path' => '/ui/reports/slow-stock', 'match' => ['/ui/reports/slow-stock*']]]],
            // ---- end Pack E
            'audit' => ['more' => true, 'pages' => [['perm' => 'audit.view', 'path' => '/ui/system/audit', 'match' => ['/ui/system/audit*']]]],
        ]],
        // The section's count is its inbox's (`count`, docs/decisions.md AV10): every record the person may answer, counted once.
        'approvals' => ['count' => 'inbox', 'tabs' => [
            // Waiting for you (AV1): every kind the person may answer on one page; the tab is for the holders of any of its permissions.
            'inbox' => ['pages' => [['perm' => Controller\InboxController::PERMS, 'path' => '/ui/approvals', 'match' => ['/ui/approvals', '/ui/approvals/*'],
                'badge' => 'approvals_inbox']]],
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

    /**
     * The sidebar's "Every day" group (HV2; HM_Home): entry => [section, tab]: a tab of a section (its first page the person may open,
     * its count), or a whole section (null: its first tab, the section's count). Each only for the people who may open it.
     */
    public const DAILY = [
        'goods_in' => ['purchasing', 'goods_in'],
        'counts' => ['stock', 'counts'],
        'approvals' => ['approvals', null],
        'orders' => ['purchasing', 'orders'],
        'products' => ['products', null],
    ];
    /** The sidebar's "More" group: the other sections, each linking to its first tab. */
    public const MORE = ['stock', 'purchasing', 'sales', 'matching', 'reports', 'settings'];
    /** The phone's bottom bar (HM_Phone): Home, these entries of the sidebar the person has, then "More" (the whole sidebar). */
    public const PHONE = ['goods_in', 'counts', 'approvals'];
    /** The icons of the sidebar's entries that are not named after their section (ico-<name>: the sprite's names have no underscore). */
    public const ICON = ['home' => 'home', 'goods_in' => 'goods-in', 'counts' => 'counts', 'orders' => 'orders'];

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
     * @return array<string, array<string, array{soon: bool, segments: bool, more: bool, pages: list<array<string, mixed>>}>> section => tab => tab
     */
    public static function visible(array $roles): array
    {
        $out = [];
        foreach (self::MAP as $s => $section) {
            $tabs = [];
            $live = false;
            foreach ($section['tabs'] as $t => $tab) {
                $more = ($tab['more'] ?? false) === true;
                if (($tab['soon'] ?? false) === true) {
                    $tabs[$t] = ['soon' => true, 'segments' => false, 'more' => $more, 'pages' => []];
                    continue;
                }
                $pages = [];
                foreach ($tab['pages'] as $page) {
                    if (self::may($roles, $page['perm'])) {
                        $pages[] = ['key' => (string) ($page['key'] ?? $t)] + $page;
                    }
                }
                if ($pages !== []) {
                    $tabs[$t] = ['soon' => false, 'segments' => ($tab['segments'] ?? false) === true, 'more' => $more, 'pages' => $pages];
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
     * What a person may use, section by section in the map's order: each section with its live tabs, each tab with the link of the first
     * page the person may open. No roles, no menu. (Home listed it as "What you can use" until HV1; the tests read who sees what here.)
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

    /** Whether a link to $to leads to the page at $path (the same path, whatever the query: a filter of the page is still the page). */
    private static function samePath(string $path, string $to): bool
    {
        return rtrim($path, '/') === rtrim($to, '/');
    }

    /**
     * Everything the layout draws around a page for this person (Context::frame):
     *   nav       the sidebar (HV2): Home, the "Every day" entries (DAILY), the "More" sections (MORE), each only when the person has it:
     *             its key, group (home, daily, more), icon, label, link, count, whether it is current, and `subs`: the current section's
     *             entry lists the section's pages as sub-items (DC28; the owner, 30 Oct 2026: "dont need tab view use sidebar and sub section"):
     *             each tab the person may open (and one not built yet, `soon`: no link) with its link, count, `current`, `here` (the page shown: no link, CV13)
     *             and `more` (a set-up or specialist page, HV3), the current tab's segments under it (`segs`). ONE entry is current: the
     *             daily entry of the page's tab, else the entry of the page's section. A daily tab's count is the tab's; a section's is
     *             the sum of its tabs' but those with a daily entry of their own (counted there), or its inbox's (`count`, AV10)
     *   pagebar   for the current section when the person has it (Home has none: its page title is the page's own): its label,
     *             one-line description, the tabs of the row, the tabs under the row's More (`more`, HV3: never the current one, which
     *             the row shows after its own; none when the person has no other tab), their count. No page draws it as a row any more
     *             (the owner, 30 Oct 2026: DC28): the page's eyebrow takes its label; its tabs are the sidebar's sub-items (`nav`'s `subs`)
     *   segments  the current tab's segmented filter (two or more segments the person may open), or []; the sidebar's second level
     *             A tab or segment is `here` when it is the current one and leads to the page shown (the same path): it is drawn without a
     *             link, so a click never reloads the page and loses what was typed (docs/decisions.md CV13); the current one of a record's
     *             page leads back to its list and stays a link.
     *   actions   the current page's create button the person may use (only on the page itself, not its detail pages); only its own
     *             (DC4: no second More of the section's other create actions); none on Home (HV1)
     *   phone     the bottom bar: Home, the PHONE entries the person has, "More" (#menu)
     *   bell      the bell (HV4): Approvals › Waiting for you and its count, for the people who answer there; else null
     *
     * @param list<string> $roles
     * @param array<string, int> $badges Context::badges()
     * @param array<string, mixed> $query
     * @return array{nav: list<array<string, mixed>>, pagebar: ?array<string, mixed>, segments: list<array<string, mixed>>, actions: list<array<string, string>>, phone: list<array<string, mixed>>, bell: ?array<string, mixed>, here: ?array{section: string, tab: string, page: string}}
     */
    public static function frame(array $roles, array $badges, string $path, array $query = []): array
    {
        $here = self::locate($path, $query);
        $visible = self::visible($roles);
        $tabsOf = [];
        foreach ($visible as $s => $tabs) {
            // The query values the section's tabs keep (Sales: the store), on its own pages only (a plain code: the pages check it).
            $kept = $here !== null && $here['section'] === $s ? self::kept(self::MAP[$s]['keep'] ?? [], $query) : [];
            $list = [];
            foreach ($tabs as $t => $tab) {
                if ($tab['soon']) {
                    $list[$t] = ['key' => $t, 'label' => Words::MENU[$t], 'href' => null, 'query' => [], 'count' => 0, 'countWords' => '', 'current' => false, 'here' => false,
                        'soon' => true, 'more' => $tab['more']];
                    continue;
                }
                [$count, $words] = self::count($tab['pages'], $badges);
                $first = $tab['pages'][0];
                $current = $here !== null && $here['section'] === $s && $here['tab'] === $t;
                $list[$t] = ['key' => $t, 'label' => Words::MENU[$t], 'href' => (string) $first['path'], 'query' => ($first['query'] ?? []) + $kept, 'count' => $count,
                    'countWords' => $words, 'current' => $current, 'here' => $current && self::samePath($path, (string) $first['path']), 'soon' => false, 'more' => $tab['more']];
            }
            $tabsOf[$s] = $list;
        }

        // The sidebar: Home, Every day, More. A daily tab is counted on its own entry, not again on its section's.
        $entry = static function (string $key, string $group, string $s, string $label, array $tab, int $count, string $words): array {
            return ['key' => $key, 'group' => $group, 'section' => $s, 'tab' => null, 'icon' => self::ICON[$key] ?? $key, 'label' => $label, 'href' => $tab['href'],
                'query' => $tab['query'], 'count' => $count, 'countWords' => $words, 'current' => false, 'open' => false, 'subs' => []];
        };
        $firstLive = static fn (array $list): array => array_values(array_filter($list, static fn (array $x): bool => !$x['soon']))[0];
        $nav = [];
        $ownEntry = [];
        if (isset($tabsOf['dashboard'])) {
            $nav[] = $entry('home', 'home', 'dashboard', Words::SECTION['dashboard'], $firstLive($tabsOf['dashboard']), 0, Words::BADGE['section']);
        }
        foreach (self::DAILY as $key => [$s, $t]) {
            if ($t !== null) {
                $tab = $tabsOf[$s][$t] ?? null;
                if ($tab === null || $tab['soon']) {
                    continue;
                }
                $nav[] = ['tab' => $t] + $entry($key, 'daily', $s, Words::NAV[$key], $tab, $tab['count'], $tab['countWords']);
                $ownEntry[$s][$t] = true;
            } elseif (isset($tabsOf[$s])) {
                $nav[] = $entry($key, 'daily', $s, Words::NAV[$key], $firstLive($tabsOf[$s]), self::sectionCount($s, $tabsOf[$s], []), Words::BADGE['section']);
            }
        }
        foreach (self::MORE as $s) {
            if (isset($tabsOf[$s])) {
                $nav[] = $entry($s, 'more', $s, Words::SECTION[$s], $firstLive($tabsOf[$s]), self::sectionCount($s, $tabsOf[$s], $ownEntry[$s] ?? []),
                    Words::BADGE['section']);
            }
        }
        // ONE current entry: the daily entry of the page's tab, else the entry of its section.
        $mark = null;
        foreach ($here === null ? [] : $nav as $i => $item) {
            if ($item['section'] === $here['section'] && $item['tab'] === $here['tab']) {
                $mark = $i;
                break;
            }
            if ($item['section'] === $here['section'] && $item['tab'] === null) {
                $mark ??= $i;
            }
        }
        if ($mark !== null) {
            $nav[$mark]['current'] = true;
        }

        $pagebar = null;
        $segments = [];
        $actions = [];
        if ($here !== null && isset($visible[$here['section']]) && $here['section'] !== 'dashboard') {
            $s = $here['section'];
            // The row: the section's main tabs, then the current one when it is a More tab; More holds the rest (HV3). A person with no
            // main tab of the section sees its other tabs in the row.
            $all = array_values($tabsOf[$s]);
            $main = array_values(array_filter($all, static fn (array $t): bool => !$t['more']));
            $more = [];
            if ($main !== []) {
                $more = array_values(array_filter($all, static fn (array $t): bool => $t['more'] && !$t['current']));
                $main = [...$main, ...array_values(array_filter($all, static fn (array $t): bool => $t['more'] && $t['current']))];
                // A More of pages not built yet only is not drawn: nothing in it opens.
                $more = array_filter($more, static fn (array $t): bool => !$t['soon']) === [] ? [] : $more;
            } else {
                $main = $all;
            }
            $tab = $visible[$s][$here['tab']] ?? null;
            if ($tab !== null && $tab['segments'] && count($tab['pages']) > 1) {
                // The query values the tab keeps (a store's code: a plain code only, the pages check it names a store).
                $kept = self::kept(self::MAP[$s]['tabs'][$here['tab']]['keep'] ?? [], $query);
                foreach ($tab['pages'] as $page) {
                    [$count, $words] = self::count([$page], $badges);
                    $segments[] = ['key' => (string) $page['key'], 'label' => Words::SEGMENT[$page['key']], 'href' => (string) $page['path'],
                        'query' => ($page['query'] ?? []) + $kept, 'count' => $count, 'countWords' => $words, 'current' => $here['page'] === $page['key'],
                        'here' => $here['page'] === $page['key'] && self::samePath($path, (string) $page['path'])];
                }
            }
            $pagebar = ['key' => $s, 'label' => Words::SECTION[$s], 'desc' => Words::SECTION_DESC[$s], 'tabs' => $main, 'more' => $more,
                'moreCount' => (int) array_sum(array_column($more, 'count')), 'tab' => Words::MENU[$here['tab']]];
            foreach ($tab['pages'] ?? [] as $page) {
                $new = $page['new'] ?? null;
                if ($new !== null && $page['key'] === $here['page'] && rtrim($path, '/') === rtrim((string) $page['path'], '/')
                    && Permissions::can($roles, $new['perm'])) {
                    $actions[] = ['label' => Words::NEW[$new['label']], 'href' => (string) $new['href']];
                }
            }
        }

        // The sidebar's tree (DC28): the current section's entry (the one that names the section, not a daily tab's) lists its pages, the
        // current one's segments under it; every other entry stays one link.
        if ($pagebar !== null) {
            $subs = [];
            $moreKeys = array_column($pagebar['more'], 'key');
            foreach ($tabsOf[$pagebar['key']] as $t) {
                if ($t['soon'] && !in_array($t['key'], $moreKeys, true)) {
                    continue; // a page not built yet shows only beside a page that opens (as HV3's More did)
                }
                $subs[] = $t + ['segs' => $t['current'] ? $segments : []];
            }
            foreach ($nav as $i => $item) {
                if ($item['section'] === $pagebar['key'] && $item['tab'] === null) {
                    $nav[$i]['subs'] = $subs;
                    $nav[$i]['open'] = true;
                }
            }
        }

        $phone = [];
        $byKey = array_column($nav, null, 'key');
        if (isset($byKey['home'])) {
            foreach (['home', ...self::PHONE] as $key) {
                if (isset($byKey[$key])) {
                    $phone[] = $byKey[$key];
                }
            }
            $phone[] = ['key' => 'more', 'group' => 'more', 'section' => '', 'tab' => null, 'icon' => 'more', 'label' => Words::TAB['more'], 'href' => '#menu',
                'query' => [], 'count' => 0, 'countWords' => '', 'current' => false];
        }
        $inbox = $tabsOf['approvals']['inbox'] ?? null;
        $bell = $inbox === null ? null : ['href' => $inbox['href'], 'query' => [], 'count' => $inbox['count'], 'countWords' => $inbox['countWords']];
        return ['nav' => $nav, 'pagebar' => $pagebar, 'segments' => $segments, 'actions' => $actions, 'phone' => $phone, 'bell' => $bell, 'here' => $here];
    }

    /**
     * A section's count on the sidebar: its inbox's when it names one (AV10: its other tabs list the same records again), else the sum of
     * its tabs' but the ones counted on a daily entry of their own ($own).
     *
     * @param array<string, array<string, mixed>> $tabs the section's drawn tabs
     * @param array<string, true> $own
     */
    private static function sectionCount(string $s, array $tabs, array $own): int
    {
        $inbox = self::MAP[$s]['count'] ?? null;
        if ($inbox !== null && isset($tabs[$inbox])) {
            return (int) $tabs[$inbox]['count'];
        }
        $sum = 0;
        foreach ($tabs as $t => $tab) {
            $sum += isset($own[$t]) ? 0 : (int) $tab['count'];
        }
        return $sum;
    }

    /**
     * The query values a section or a tab keeps from the request (a store's code): a plain code only, the pages check it names a store.
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
