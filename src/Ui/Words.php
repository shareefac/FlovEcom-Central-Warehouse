<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Permissions;

/**
 * THE wording of the staff screens (plan /root/cw_work/ui_clarity/plan.md §1, owner decision of 7 Oct 2026): one plain word
 * for each internal code, the menu, the page intros, the "?" help texts and the error texts. Templates and controllers take
 * their words from here and never type a glossary word inline, so a wording change edits one place (tests assert these
 * constants, not copied strings).
 *
 * Constants and pure functions only: no database. Every group is keyed by the internal code (role code, band, status ...);
 * of() falls back to the code made readable, and tests/Unit/WordsTest.php fails on any code of the system without a word.
 *
 * Kept on purpose (staff meet them elsewhere): CW-000123, PO-000123, barcode / EAN, the VAT letters, ERPNext, MOQ, "Key" in
 * the server tools and older notes, the role codes in the database and docs, import column names and URL values.
 * Service (CwException) messages are NOT rewritten here: they reach the API. The screens translate them by error code (ERROR).
 *
 * PROVISIONAL (recorded in docs/decisions.md, owner to confirm): the six band names, the core words (website product,
 * warehouse product, match, Matching lead, second OK, Reviewer) and the person to ask (ASK).
 */
final class Words
{
    /** The person staff are told to ask (provisional: a fixed name, not read from the staff list). */
    public const ASK = 'Fazil';
    public const ASK_ROLE = 'Fazil (the admin)';

    /** The groups of() accepts (the constant names below). */
    private const GROUPS = ['SITE', 'ROLE', 'ROLE_PLURAL', 'ROLE_HELP', 'ROLE_GROUP', 'SECTION', 'SECTION_DESC', 'MENU', 'SEGMENT', 'NEW', 'FLOW', 'MENU_HELP', 'TAB',
        'BADGE', 'COMING_LATER',
        'PAGE_TITLE', 'BAND', 'BAND_TITLE', 'BAND_HELP', 'BAND_HELP_RULES', 'LANE', 'AI', 'FLAG', 'VETO', 'BAND_REASON', 'FIELD_STATE', 'NEEDS_SECOND', 'ACTION',
        'DECISION_STATE', 'LISTING_STATUS', 'POLICY', 'STOCK', 'SAMPLE_STATE', 'SAMPLE_RESULT', 'PO_STATE', 'SEND_VIA', 'REASON', 'SUPPLIER_STATUS',
        'CHECK_REASON', 'CHECK_KIND', 'REVIEW_STATE', 'TASK_STATE', 'DOC_STATUS', 'REORDER_FLAG', 'SETTING', 'ERROR_TITLE', 'ERROR', 'UI', 'HOME',
        'SIGN_IN', 'REFUSAL', 'DOC_TYPE', 'DOC_TYPES', 'CHECK_STATE', 'CHECKS', 'RECORDS', 'RECORD', 'RECORD_NOTICE', 'COMPANY', 'COMPANY_NOTICE',
        'SETTINGS_PAGE', 'REASON_USE', 'SETTING_TOPIC', 'SETTING_HELP', 'STAFF', 'STAFF_NOTICE', 'SPOT', 'FIELD', 'FORM_VALUE', 'NIC_TYPE', 'AI_FIELD',
        'AI_FIELD_STATE', 'ACTION_DONE', 'MOVEMENT', 'ORIGIN', 'QUEUE', 'LISTING', 'PENDING', 'MATCH_NOTICE', 'MATCH_ERROR', 'SAMPLE', 'SAMPLE_FIT', 'DUPS',
        'DUP_NOTICE', 'DUP_ERROR', 'SEARCH', 'ITEM', 'PO', 'PO_FILTER', 'PO_SOURCE', 'ORDERS', 'ORDER', 'PO_NOTICE', 'BUY_ERROR', 'PO_WARN', 'PO_ADD', 'REORDER', 'WHY',
        'REORDER_ITEM', 'BRANDS', 'ANOMALIES', 'REORDER_NOTICE', 'SALES', 'SUPPLIERS', 'SUPPLIER', 'SUPPLIER_FIELD', 'SUPPLIER_FORM', 'SUPPLIER_NOTICE', 'SUPPLIER_ITEMS', 'SI_NOTICE',
        'CARD_FIELD', 'CARDS', 'CARD_STATE', 'CARD', 'CARD_ERROR', 'CARD_NOTICE', 'CARD_IMPORT', 'BARCODE', 'BARCODE_SOURCE', 'BARCODE_REASON', 'BARCODE_DECISION',
        'SELLING', 'SELLING_NOTICE', 'SITE_SYNC', 'SELLING_ERROR', 'MODE_MEANING', 'RECEIPT_STATE', 'BENCH_STATE', 'INCIDENT_WHERE',
        'INCIDENT_KIND', 'INCIDENT_STATE', 'RECEIPT_FILE', 'STAMP_TYPE', 'UNSTAMPED_ACTION', 'MODE_SOURCE', 'RECEIVING', 'RECEIPT', 'BENCH', 'INCIDENTS',
        'RECEIPT_NOTICE', 'RECEIPT_ADDED', 'RECEIPT_ERROR', 'RECEIPT_PLAN', 'CONFIG', 'CONFIG_ACTION', 'CONFIG_ERROR', 'SETTING_EDIT', 'SETTING_NOTICE',
        'APPROVALS', 'REASONS_EDIT', 'REASON_NOTICE', 'WAREHOUSES', 'WHY_NOT_EMPTY', 'WAREHOUSE_NOTICE', 'MODE', 'SITES', 'SITE_COMMAND', 'INTEGRITY', 'AUDIT',
        'AUDIT_RECORD', 'AUDIT_FAMILY', 'AUDIT_ACTION', 'PERMISSION', 'ACCESS', 'ENROL', 'SHEET', 'STAFF_REQUESTS', 'RULE', 'NEW_CODE', 'RESET_KIND',
        'COUNTS', 'COUNT_GROUP', 'COUNT_SHEET_GROUP', 'COUNT_KIND', 'COUNT_REVIEW', 'COUNT_REVIEW_HELP', 'COUNT_SHOW', 'COUNT_LINE', 'COUNT_CHECK', 'COUNT_BAD',
        'COUNT_NOTICE', 'COUNT_ERROR',
        'WATCH', 'STOCK_VIEW', 'TILE', 'BULK', 'BULK_ACTION', 'BULK_DONE', 'BULK_SKIP', 'BULK_ERROR', 'BULK_CONFIRM', 'BULK_RESULT', 'BY_STORE',
        'STORE_PRODUCTS', 'STORE_STATE', 'BY_PRODUCT', 'PRODUCT_STORE', 'QUEUE_SHOW', 'ONE_AT_A_TIME',
        'BY_ITEM_BULK', 'BY_ITEM_SKIP',
        'NEW_VARIANTS', 'NEW_VARIANT_ACTION', 'NEW_VARIANT_DONE', 'NEW_VARIANT_PENDING', 'NEW_VARIANT_STATE', 'NEW_VARIANT_FILTER', 'ARRIVAL_LEFT',
        'STOCK_OPS', 'STOCK_FILE', 'STOCK_NOTICE', 'STOCK_ERROR', 'ACCOUNTS', 'ACCOUNT_ERROR', 'STOCK_PDF',
        'RESV', 'RESV_STATE', 'RESV_GROUP', 'RESV_CHIP', 'RESV_UNIT', 'RESV_ORIGIN',
        // pack D (docs/decisions.md PD13)
        'SALE', 'SALE_STATE', 'SALE_ORIGIN', 'SALE_ORDER', 'DISPATCH', 'DISPATCH_CHIP', 'SALE_RETURNS', 'SALE_RETURN_KIND', 'SALE_RESTOCK', 'SALE_RESTOCK_TEXT',
        // Pack B
        'ITEM_NEW', 'BY_HAND_STATE', 'ITEMS_NOTICE', 'ITEMS_ERROR', 'FAMILY', 'FAMILY_SKIP', 'PACKS', 'PACK_FLAG', 'PACK_LINK_FLAG', 'REPACK', 'REPACK_DIRECTION',
        // --- Pack C (IM7)
        'INVOICES', 'INVOICE_KIND', 'INVOICE_LINE', 'MATCH_STATE', 'MATCH_LINE', 'MATCH_WHY', 'VALUE_EVENT', 'VALUE_BASIS', 'CREDIT_STATE', 'INVOICE_FILE',
        'INVOICE_NOTICE', 'INVOICE_ERROR', 'RETURNS', 'RETURN_NOTICE', 'RETURN_ERROR',
        // Pack F (PF5-PF10)
        'QUEUE_STATE', 'QUEUES', 'RELAY_KIND', 'RELAY_REASON', 'SUSPENSE', 'CORRECTIONS', 'MODES', 'OVERSOLD', 'OVERSELL_KIND', 'OVERSELL_RESOLUTION',
        'QUEUE_NOTICE', 'QUEUE_ERROR', 'ALERT',
        'ROLES_EDIT', 'ROLES_ERROR', 'REPORTS', // Pack E
        // --- Pack G (the reorder engine)
        'REORDER_ENGINE', 'REORDER_ENGINE_ERROR', 'REORDER_ENGINE_NOTICE', 'REORDER_SUPPLIERS', 'REORDER_LOW', 'REORDER_LOW_SKIP',
        // --- end of Pack G
        // Pack H (0038): hand-overs, customers and their balances, the duty register.
        'HANDOVER', 'HANDOVER_NOTICE', 'HANDOVER_ERROR', 'HANDOVER_PDF', 'CUSTOMER', 'CUSTOMER_KIND', 'CUSTOMER_FIELD_ERROR', 'CUSTOMER_ERROR',
        'CUSTOMER_NOTICE', 'PRICE_SKIP', 'BALANCE', 'BALANCE_KIND', 'BALANCE_NOTICE', 'BALANCE_ERROR', 'DUTY', 'DUTY_STAMP',
        // --- Pack J (0041): goods on their way
        'ARRIVALS', 'ARRIVAL_STATE', 'RECEIVE_MODE', 'STAMP_ANSWER', 'ARRIVAL_ERROR'];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.1 Products and websites

    /** channel.code => its name (a fallback only: the screens use channel.name). */
    public const SITE = ['vapeandgo' => 'Vape and Go', 'electrofag' => 'Electrofag', 'vapebig' => 'Vape Big'];

    /** The one name of each thing (plan §1.1, §1.2): what the screens call it. */
    public const THING = [
        'item' => 'warehouse product',
        'items' => 'warehouse products',
        'listing' => 'website product',
        'listings' => 'website products',
        'card' => 'product card',
        'identity' => 'product details',
        'cw_code' => 'CW number',
        'cw_code_explained' => 'CW-000123 is the product\'s number in this system.',
        'variant' => 'option number',
        'site' => 'website',
        'mint' => 'create',
        'proposal' => 'suggested match',
        'proposed_item' => 'suggested product',
        'run' => 'computer check',
        'no_proposal' => 'Not checked by the computer yet',
        'units' => '1 sale uses',
        'band' => 'How sure',
        'second' => 'second OK',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.8 Jobs (roles). The codes stay in the database, the server tools and the docs.

    public const ROLE = [
        'viewer' => 'Look only (matching)',
        'mapper' => 'Matcher',
        'mapping_lead' => 'Matching lead',
        'warehouse' => 'Warehouse',
        'manager' => 'Stock manager',
        'admin' => 'Admin',
        'buyer' => 'Buyer',
        'purchasing_manager' => 'Purchasing manager',
        'goods_in' => 'Goods in',
        'purchasing_desk' => 'Purchasing desk',
        'stock_controller' => 'Stock controller',
        'reviewer' => 'Reviewer',
        'accountant' => 'Accountant',
        'auditor' => 'Auditor (look only)',
    ];

    /** The order jobs are named in a sentence: the jobs that check and decide first. */
    public const JOB_ORDER = ['reviewer', 'mapping_lead', 'mapper', 'buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller',
        'warehouse', 'manager', 'admin', 'viewer', 'accountant', 'auditor'];

    /** "This page is for <these>". */
    public const ROLE_PLURAL = [
        'viewer' => 'Look only (matching)',
        'mapper' => 'Matchers',
        'mapping_lead' => 'Matching leads',
        'warehouse' => 'Warehouse staff',
        'manager' => 'Stock managers',
        'admin' => 'Admins',
        'buyer' => 'Buyers',
        'purchasing_manager' => 'Purchasing managers',
        'goods_in' => 'Goods in staff',
        'purchasing_desk' => 'Purchasing desk staff',
        'stock_controller' => 'Stock controllers',
        'reviewer' => 'Reviewers',
        'accountant' => 'Accountants',
        'auditor' => 'Auditors',
    ];

    /** One line per job (People page, help). Permissions::DESCRIPTIONS keeps the short technical ones. */
    public const ROLE_HELP = [
        'viewer' => 'Can look at the matching screens. Cannot change anything.',
        'mapper' => 'Matches each website product to our warehouse product, or marks it to ignore.',
        'mapping_lead' => 'What a Matcher does, plus the second OK, the matches where clues disagree, and joining duplicates.',
        'warehouse' => 'Counts stock on the shelves (screen coming later).',
        'manager' => 'Decides if a product may keep selling when stock runs out.',
        'admin' => 'Gives staff access. Cannot do or approve any other work.',
        'buyer' => 'Suppliers, purchase orders and what to buy.',
        'purchasing_manager' => 'What a Buyer does, plus deliveries. Supplier invoices are coming later.',
        'goods_in' => 'Checks deliveries at the goods-in bench and books them in.',
        'purchasing_desk' => 'Books in deliveries with their invoices, and hands stock to sister shops and trade customers. Supplier invoices and returns are coming later.',
        'stock_controller' => 'Product cards and barcodes now. Stock counts and corrections later.',
        'reviewer' => 'Checks and approves other people\'s work. Never their own.',
        'accountant' => 'Stock values and files for the accounts (coming later).',
        'auditor' => 'Can look at everything, including staff. Changes nothing.',
    ];

    /** Permissions::ROLE_GROUPS label => the heading on the People form. */
    public const ROLE_GROUP = [
        'Linking' => 'Matching products',
        'Purchasing and receiving' => 'Buying and deliveries',
        'Stock' => 'Stock',
        'Review and finance' => 'Checks and accounts',
        'Admin' => 'Staff access',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 2. The navigation (Ui\Sections keeps the sections, tabs, segments, paths and permissions; their words are here). Standard
    //    business names (owner, 8 Oct 2026: "sidebar name make professional and make related tabs").

    /** Sidebar section key (Sections::MAP) => its name: seven items, the same in the sidebar, the page bar and the phone bar. */
    public const SECTION = [
        'dashboard' => 'Dashboard',
        'products' => 'Products',
        'stock' => 'Stock',
        'purchasing' => 'Purchasing',
        'reports' => 'Reports',
        'approvals' => 'Approvals',
        'settings' => 'Settings',
    ];

    /** The one line under a section's name in its page bar. */
    public const SECTION_DESC = [
        'dashboard' => 'What needs doing today.',
        'products' => 'Your warehouse products, and how each store\'s products match them.',
        'stock' => 'What is in each warehouse, every change to it, and the records that move it.',
        'purchasing' => 'Buy from suppliers and receive the deliveries.',
        'reports' => 'Figures by store, and every change made in this system.',
        'approvals' => 'Work that needs your OK before it goes ahead, or a check after.',
        'settings' => 'Company, warehouses, stores, users and rules.',
    ];

    /** Tab (and page) key => its name. The same word in the tab, the page title and the crumbs. */
    public const MENU = [
        'home' => 'Dashboard',
        // Products
        'cards' => 'All Products',
        'mapping' => 'Mapping',
        'review' => 'Mapping',
        'store_products' => 'Store Products',
        'by_product' => 'By Item',
        'new_variants' => 'New Variants',
        'pending' => 'Second approval',
        'samples' => 'Spot check',
        'duplicates' => 'Duplicates',
        'barcodes' => 'Barcodes',
        // Stock
        'stock' => 'Overview',
        'movements' => 'Movements',
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        'transfers' => 'Transfers',
        'adjustments' => 'Adjustments',
        'counts' => 'Counts',
        'reservations' => 'Reservations',
        'quality' => 'Quality',
        'releases' => 'Releases',
        'accounts' => 'Balance owed',
        // ---- Pack D (docs/decisions.md PD1): Stock › Sales and its three segments ----
        'sales' => 'Sales',
        'sales_orders' => 'Store orders',
        'sales_dispatch' => 'Dispatch',
        'sales_returns' => 'Returns and cancels',
        // ---- end of pack D ----
        // Purchasing
        'reorder' => 'Reorder',
        'orders' => 'Purchase Orders',
        'goods_in' => 'Goods In',
        'suppliers' => 'Suppliers',
        'sales_history' => 'Sales history',
        'receiving' => 'Goods In',
        'bench' => 'Checking',
        'incidents' => 'Issues',
        // Reports
        'stock_by_store' => 'Stock by Store',
        'sales_by_store' => 'Sales by Store',
        'low_stock' => 'Low Stock',
        // ---- Pack E
        'stock_value' => 'Stock Value',
        'slow_stock' => 'Slow Stock',
        // ---- end Pack E
        'audit' => 'Audit Log',
        // Approvals
        'reviews' => 'Waiting for me',
        'documents' => 'History',
        'staff_requests' => 'Staff requests',
        // Settings
        'company' => 'Company',
        'warehouses' => 'Warehouses',
        'sites' => 'Stores',
        'people' => 'Users',
        'approvals' => 'Approval Rules',
        'reasons' => 'Reasons',
        'system' => 'System',
        'settings' => 'Settings',
        'integrity' => 'System checks',
        // Pack B: Products and Stock
        'add_item' => 'Add Item',
        'families' => 'Families',
        'multipacks' => 'Multipacks',
        'packs' => 'Pack items',
        'pack_links' => 'Pack links',
        'repack' => 'Repack',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'Invoices',
        'returns' => 'Returns',
        // Pack F (docs/decisions.md PF5): Approvals › From the stores.
        'store_queues' => 'From the stores',
        // Pack H (0038): Stock > Hand-overs.
        'handovers' => 'Hand-overs',
    ];

    /** A segment of a tab (Sections::MAP `key` of a page in a tab with `segments`) => its name. */
    public const SEGMENT = [
        'review' => 'To review',
        'samples' => 'Spot check',
        'pending' => 'Second approval',
        'reorder' => 'Suggestions',
        'brands' => 'Brands',
        'anomalies' => 'Anomalies',
        'sales_history' => 'Sales history',
        'receiving' => 'To receive',
        'bench' => 'Checking',
        'incidents' => 'Issues',
        'settings' => 'Settings',
        'series' => 'Numbering',
        'integrity' => 'System checks',
        // Stock > Counts (pack A2a)
        'counts' => 'Next to count',
        'count_sheets' => 'Count sheets',
        'count_differences' => 'Differences',
        'transfers' => 'Transfers',
        'releases' => 'Releases',
        'accounts' => 'Balance owed',
        // ---- Pack D (docs/decisions.md PD1) ----
        'sales_orders' => 'Orders',
        'sales_dispatch' => 'Dispatch',
        'sales_returns' => 'Returns',
        // ---- end of pack D ----
        // Pack B: Products › Multipacks
        'packs' => 'Pack items',
        'pack_links' => 'Pack links',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'Invoices',
        'to_match' => 'To match',
        'not_invoiced' => 'Not invoiced yet',
        'credit_notes' => 'Credit notes',
        'timed' => 'Timed', // pack J (PJ10)
        'arriving' => 'Arriving', // pack J (PJ14)
        // Approvals › From the stores (pack F, PF5)
        'queue_suspense' => 'Lines to place',
        'queue_corrections' => 'ERPNext corrections',
        'queue_modes' => 'Mode changes',
        'queue_oversold' => 'Oversold',
        // --- Pack G (Purchasing › Reorder) ---
        'reorder_suppliers' => 'Suppliers',
        'low_stock_levels' => 'Low-stock warnings',
        // --- end of Pack G
        // Pack H (0038): Stock > Hand-overs, and the duty register in Purchasing > Goods In.
        'handovers' => 'Hand-overs',
        'handover_customers' => 'Customers',
        'handover_balances' => 'Balances',
        'duty' => 'Duty register',
    ];

    /** A page's create button (Sections::MAP `new`): a verb that names the result. */
    public const NEW = [
        'orders' => 'New purchase order',
        'receiving' => 'Receive delivery',
        'suppliers' => 'New supplier',
        'people' => 'New user',
        'warehouses' => 'New warehouse',
        'reasons' => 'New reason',
        'stock_in' => 'New stock in',
        'stock_out' => 'New stock out',
        'transfers' => 'New transfer',
        'releases' => 'New release',
        'adjustments' => 'New adjustment',
        'counts' => 'New count sheet',
        // Pack B
        'add_item' => 'Add an item',
        'families' => 'New family',
        'packs' => 'New pack item',
        'repack' => 'New repack',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'New supplier invoice',
        'credit_notes' => 'New credit note',
        'returns' => 'New return to a supplier',
        // Pack H (0038).
        'handovers' => 'New hand-over',
        'handover_customers' => 'New customer',
    ];

    /** The buying flow strip on Purchasing (Ui\FlowCounts): step => name, and the figures under the names. */
    public const FLOW = [
        'label' => 'How buying works, step by step',
        'reorder' => 'Reorder',
        'orders' => 'Purchase Order',
        'goods_in' => 'Goods In',
        'stock' => 'Stock updated',
        'reorder_text' => 'Suggestions from sales',
        'orders_open' => '%s open',
        'orders_waiting' => '%s waiting for OK',
        'goods_in_text' => '%s not booked in',
        'stock_text' => '%s booked in this week',
    ];

    /** One line under each tab on the Dashboard ("What you can use"). */
    public const MENU_HELP = [
        'home' => 'What is waiting for you today.',
        'cards' => 'Every product with its legal details and barcodes.',
        'mapping' => 'Match each website product to its warehouse product, the spot checks and the second approvals.',
        'store_products' => 'Every product of one store, and whether it is matched, suggested, not matched or ignored.',
        'by_product' => 'Every warehouse item, and the variant it is matched to on each store.',
        'new_variants' => 'The variants each store added lately, and creating them as new items.',
        'duplicates' => 'Vape and Go pages that may be the same product.',
        'barcodes' => 'Barcodes the computer could not place.',
        'stock' => 'What is in each warehouse now.',
        'movements' => 'Every change to the stock, newest first.',
        'stock_in' => 'Stock that comes in without a supplier delivery: opening stock, found stock, a trade customer\'s return.',
        'stock_out' => 'Stock that goes out without a website sale: samples, staff use, trade sales, repairs.',
        'transfers' => 'Stock moved between warehouses or places, stock released from another account, and what we owe it.',
        'adjustments' => 'Corrections and write-offs of the stock figure, each line with its reason.',
        'counts' => 'Count the shelves: what to count next, the count sheets, and the count differences to check.',
        'reservations' => 'The stock kept for each store\'s orders: in a checkout, paid and waiting to ship, freed, or run out of time.',
        // Pack D (docs/decisions.md PD1)
        'sales' => 'Each store\'s orders, what waits to be sent, and what came back or was cancelled.',
        'reorder' => 'What to buy now, worked out from sales.',
        'orders' => 'The orders we send to suppliers.',
        'goods_in' => 'Book in each delivery against its supplier invoice, check it, and deal with what was wrong.',
        'suppliers' => 'The companies we buy from.',
        // ---- Pack E
        'stock_by_store' => 'What each store can sell now, product by product.',
        'sales_by_store' => 'Orders and units each store sold, day by day.',
        'low_stock' => 'Products a store can sell only a few of, or none.',
        'stock_value' => 'What the stock is worth at its average cost, by product, brand and warehouse.',
        'slow_stock' => 'Products in stock that have not sold for a long time.',
        // ---- end Pack E
        'audit' => 'Everything that was changed, by whom and when.',
        'reviews' => 'Other people\'s work that needs your OK or your check.',
        'documents' => 'Every final record, by number.',
        'staff_requests' => 'Changes of staff access that wait for your OK.',
        'company' => 'Our name and addresses, printed on every purchase order.',
        'warehouses' => 'Where stock is kept, whose it is, and what the websites sell from.',
        'sites' => 'How each website\'s link with the warehouse is doing.',
        'people' => 'Who can use this system and what they may do.',
        'approvals' => 'Which work waits for a second person, and the limits.',
        'reasons' => 'The reasons people choose when stock goes up or down outside a sale.',
        'system' => 'How the system is set up, how records are numbered, and the nightly checks.',
        // Pack B
        'add_item' => 'Make an item that no store sells yet, and see the items made by hand.',
        'families' => 'Group items into families: all the flavours or strengths of one product.',
        'multipacks' => 'Sealed multipacks kept in stock as packs, and the variants that sell several units a sale.',
        'repack' => 'Break sealed packs into singles, or make packs from singles.',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'Supplier invoices checked against the order and the delivery, the deliveries not invoiced yet, and credit notes.',
        'returns' => 'Goods sent back to a supplier, each one our claim for a credit.',
        // Pack F (PF5)
        'store_queues' => 'What the stores sent from ERPNext that needs a person, and the orders sold without the stock.',
        // Pack H (0038).
        'handovers' => 'Stock handed to sister shops, our shop units and trade customers, with prices and an invoice.',
    ];

    /** The phone's bottom bar: Dashboard and the sections take their SECTION names; the last tab opens the whole menu. */
    public const TAB = [
        'more' => 'More',
    ];

    /** Badge name (Context::badges) => the words a screen reader hears after the number ("3 waiting for your second OK"). */
    public const BADGE = [
        'linking_pending' => 'waiting for your second OK',
        'linking_duplicates' => 'waiting for you to decide',
        'reviews_open' => 'waiting for you',
        'barcodes_open' => 'barcodes for you to check',
        'incidents_open' => 'issues still open',
        'stock_in_open' => 'stock in records waiting for your check or OK',
        'stock_out_open' => 'stock out records waiting for your check or OK',
        'transfers_open' => 'transfers waiting for your check or OK',
        'releases_open' => 'releases waiting for your check or OK',
        'adjustments_open' => 'adjustments waiting for your check or OK',
        'counts_open' => 'count differences waiting for your answer',
        // A sidebar section's count: everything in it that waits for this person.
        'section' => 'waiting for you',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices_to_match' => 'invoices that do not match, waiting for you',
        'arrivals_late' => 'deliveries late on their way', // pack J
        'invoices_timed_failed' => 'timed invoices that could not be made final', // pack J
        // Pack F (PF5): Approvals › From the stores, only for the people who may answer.
        'queue_suspense' => 'lines waiting for an item',
        'queue_corrections' => 'ERPNext corrections waiting for your answer',
        'queue_modes' => 'mode changes waiting for your answer',
        'queue_oversold' => 'oversold orders not handled yet',
    ];

    /** Permissions::COMING_LATER key => words for Home's "Coming later" line (no phase codes, no dates). */
    public const COMING_LATER = [
        'invoices' => 'supplier invoices',
        'returns' => 'returns to suppliers',
        'trade' => 'trade sales',
        'values' => 'stock values for the accounts',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 4. Page titles and intros: one sentence pair at the top of every page ([what it is, what to do])

    /** Page key => title, where it is not the menu name. */
    public const PAGE_TITLE = [
        'count_differences' => 'Count differences to check',
        'count_before' => 'Before you make it final',
        'login' => 'Sign in',
        'password' => 'Change password',
        'search' => 'Find a product',
        'reasons' => 'Reasons',
        'series' => 'Numbering',
        'reviews' => 'Waiting for me',
        'pending' => 'Second approval',
        'samples' => 'Spot checks',
        'item' => 'Warehouse product',
        'card_import' => 'Change many product cards',
        'company_edit' => 'Change the company details',
        'supplier_new' => 'New supplier',
        'reorder_brands' => 'Brands',
        'reorder_anomalies' => 'Anomalies',
        // --- Pack G
        'reorder_suppliers' => 'Suppliers: spare days',
        'reorder_low_stock' => 'Low-stock warnings',
        // --- end of Pack G
        'access' => 'Roles and permissions',
        'enrol' => 'Set up my sign-in',
        'staff_requests' => 'Staff requests',
        'new_code' => 'Your own sign-in code',
        // Pack B
        'item_new' => 'Add an item',
        'packs' => 'Pack items',
        'pack_links' => 'Pack links',
        // ---- Pack E
        'permission' => 'Who can do this',
        // ---- end Pack E
    ];

    /**
     * Page key => [what the page is, what to do]. For a person who can only look, intro() swaps the second half for
     * "You can look; <who> change this."
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PAGE_INTRO = [
        'login' => ['Sign in to the Vape and Go stock system.', 'Use your e-mail, your password and the 6-digit code from the code app on your phone.'],
        'password' => ['Choose a new password of at least 12 characters.', 'You stay signed in here.'],
        'home' => ['What is waiting for you today.', 'Start with the top card.'],
        'queue_Key' => ['Same barcode, or copied from the other website, and the AI agrees.', 'Check the names, then press Yes. A few seconds each.'],
        'mapping_overview' => ['How far each store is with matching, by how sure the computer is.', 'Choose a store, then press Review on a list.'],
        'store_products' => ['Every website product of one store, and its match.', 'Choose a store. Tick products to ignore them or send them back; open one to change it.'],
        'by_product' => ['Every warehouse item and the variant it is matched to on each store.',
            'Where a store is missing, press "Match…" and choose its variant. To answer many suggestions at once, tick the items and use the bar.'],
        'by_item_preview' => ['Nothing is saved yet: this is what will happen to each variant.',
            'Read the lines, then press the button at the bottom, or go back.'],
        'by_item_result' => ['What happened to each variant of this action.', 'Open a skipped one to decide it by hand.'],
        'by_product_map' => ['Which variant of this store is this warehouse item?',
            'Press Choose on it. The next page shows the two side by side, and you say yes there.'],
        'new_variants' => ['The variants one store added lately, newest first, and what each one is.',
            'Tick the ones marked New and press "Create as new items", or open one to decide it by hand.'],
        'bulk_confirm' => ['This changes many website products at once.', 'Check the list below. Nothing is saved until you press the red button.'],
        'bulk_result' => ['What happened to each website product you ticked.', 'Open a skipped one to decide it by hand.'],
        'queue_Check' => ['The AI found a likely product, but no barcode proves it, or a small detail differs.', 'Compare them before you say yes.'],
        'queue_New item' => ['Nothing in the warehouse list is this product.', 'Make sure it is not there under another name, then create a new product.'],
        'queue_Can\'t tell' => ['The AI could not choose, for example the name does not say 10mg or 20mg.', 'Pick the product yourself.'],
        'queue_Conflict' => ['The clues point to different products.', 'Only a matching lead decides these.'],
        'queue_Manual' => ['The websites use different names for the same range.', 'Open each one and pick the product with the same flavour.'],
        'pending' => ['Some matches need a second person: a matching lead who did not make them.', 'Check each one, then approve it (it goes live) or cancel it (take it back).'],
        'listing' => ['Is this website product the same as the suggested warehouse product?', 'Compare them, then choose.'],
        // The same page when there is nothing for this person to choose (R2 review, 7 Oct): matched already, or another lead's spot check.
        'listing_matched' => ['This website product is matched to a warehouse product.', 'Check the match. The box "Is this the same product?" says what you can do.'],
        'listing_spot' => ['This website product is in a spot check.', 'You can look; only the spot check\'s owner decides it.'],
        'samples' => ['Random strong matches that one matching lead checks.', 'If all of them are right, the rest are confirmed together.'],
        'sample' => ['One spot check of random strong matches.', 'Open each one and say yes only if it is right. The button below opens the next one.'],
        'duplicates' => ['Vape and Go pages that may be the same product.', 'A matching lead decides: same product (join them) or different (keep them apart).'],
        'duplicate_group' => ['Pages that may sell the same product.', 'Compare them. If they are the same product, join them so they share one stock figure. If not, keep them apart.'],
        'search' => ['Find a warehouse product or a website product.', 'Type a product name, scan a barcode, or type a CW number.'],
        'item' => ['Everything about one warehouse product: its details, the website products that sell it, its stock and recent stock changes.', ''],
        'cards' => ['Every warehouse product with its legal and buying details.', 'Fix the ones marked with a warning first.'],
        'card_import' => ['Change many product cards at once.', 'Download the list, edit it in Excel, then import it here. Nothing is saved until you choose "Save the changes".'],
        'card_form' => ['The legal and buying details of one product.', 'Fill them in, then press Confirm when they are right.'],
        'barcodes' => ['Barcodes the computer could not place.', 'Decide which product (single or pack) each one belongs to.'],
        'reviews' => ['Other people\'s work that needs your OK or your check.', 'Do the top list first: nothing happens until you say yes.'],
        'documents' => ['Every final record (today: purchase orders and their cancellations), newest first.', ''],
        'document' => ['The permanent record of one document and its checks.', 'To change an order, open it under Purchasing.'],
        'reorder' => ['What to buy now, worked out from recent website sales (unusual days left out), delivery times, and the stock you have and have ordered.',
            'It is only a suggestion: tick what you want, change the packs if needed, press "Create draft orders", then check each draft.'],
        'reorder_item' => ['Why the list suggests this amount.', 'Buyers can change this product\'s settings below.'],
        'reorder_brands' => ['Make the list buy more or less of a whole brand.', ''],
        'reorder_anomalies' => ['Days with unusual sales (for example stockpiling before the duty) that should be left out when CW works out what to buy.', ''],
        // --- Pack G
        'reorder_suppliers' => ['Spare days of stock for the products each supplier is the main supplier of.',
            'Press "Change" next to a supplier to set its spare days.'],
        'reorder_low_stock' => ['The low-stock warning each website shows, kept by CW for each product.',
            'Tick products, then use their safety stock, or let each website keep its own figure.'],
        // --- end of Pack G
        'sales_history' => ['Daily website sales that Reorder uses. ' . self::ASK . ' loads them from the websites.',
            'If a website product is not matched to a warehouse product, its sales are not used: the list below shows the biggest ones.'],
        'orders' => ['Every order to a supplier.', 'Start a new one below, or open one to see where it is.'],
        'order_draft' => ['A new order, not confirmed yet.', 'Add products by scanning or searching, check packs and prices, then confirm it. An order over the approval limit goes to a reviewer first.'],
        // Pack PO (PO4): the step "Add a new item" of the order editor.
        'order_new_item' => ['Make a new item and put it on this order in one step.', 'Fill in what you know: only the name is needed. You come back to the order afterwards.'],
        'order' => ['One order: what was ordered, where it is now, and what to do next.', ''],
        'suppliers' => ['The companies we buy from.', 'A new supplier needs a reviewer\'s OK before you can order from it.'],
        'supplier_form' => ['A supplier\'s details.', 'Fill them in, save them as a draft, then ask a reviewer to approve the supplier.'],
        'supplier' => ['Can we order from this supplier, what waits for a reviewer, its products and its recent orders.', ''],
        'supplier_items' => ['What we buy from this supplier, in which pack and at what price.', ''],
        'supplier_item_form' => ['Add a product this supplier sells.', 'Find our product, then type the supplier\'s code, pack size and price.'],
        'supplier_item' => ['One product as this supplier sells it: pack, price history and whether this is its main supplier.', ''],
        'company' => ['Our name, numbers and addresses as printed on every purchase order.', 'A reviewer fills them in and confirms them.'],
        'company_edit' => ['Type or correct our details, then save.', 'Then press "These details are correct" on the next page.'],
        'settings' => ['How the system is set up.', 'Open a setting to change it. Say why each time: every change is kept with who, when and why.'],
        'setting' => ['One setting: what it does, its value now, and every change.', 'Type the new value, say why, then save it.'],
        'approvals' => ['Which work waits for a second person, and which a reviewer checks afterwards.',
            'Switch a rule on or off, or change its limit. Say why each time.'],
        'reason' => ['One reason for a stock change, and every change to it.', 'Rename it, or switch it off or on again.'],
        'warehouses' => ['Where stock is kept, whose stock it is, and which warehouses the websites sell from.',
            'Open a warehouse to rename it, switch it off, or add the places inside it.'],
        'warehouse' => ['One warehouse: its stock, whose it is, and the places inside it.', 'Change it with the boxes below. Say why each time.'],
        'sites' => ['Each website and how its link with the warehouse is doing.', 'Changes are made on the server by ' . self::ASK . ', with the owner\'s OK.'],
        'integrity' => ['Every night the system checks that its stock figures and records agree with each other.',
            'If a check finds a problem, tell ' . self::ASK . ' the same day.'],
        'audit' => ['Everything that was changed in this system, by whom and when.', 'Search by person, day, record or what was done. Nothing here can be changed.'],
        'access' => ['What each job may do in this system.', 'Admins give people their jobs on the Users page.'],
        'enrol' => ['Set up your own sign-in.', 'Use your e-mail, the set-up code on your sheet and the 6-digit code from the code app on your phone.'],
        'new_code' => ['This code is for you only.', 'Scan it with the code app on your phone, then type its 6 numbers to finish.'],
        'staff_sheet' => ['Give this sheet to the person now. It is shown only once.', 'They use it once; then their own page gives them a code nobody else sees.'],
        'staff_requests' => ['Changes of staff access that wait for your OK.', 'Check each one, then say OK or Not OK.'],
        'reasons' => ['The reasons people choose when stock goes up or down outside a sale.', ''],
        'series' => ['Each kind of record has its own numbers (PO-000001, PO-000002 …), with no gaps.', ''],
        'people' => ['Everyone who can use this system and what they may do.', 'Tap a name to change their access.'],
        'person' => ['What this person may do, and their history.', 'Tick their jobs and press Save, or stop them signing in.'],
        'receiving' => ['Each delivery from a supplier, booked in against its supplier invoice.', 'Start a new delivery below, or open one to see where it is.'],
        'receipt_draft' => ['A delivery being keyed: what the supplier invoice says arrived.',
            'Add the products, attach the invoice, and book it in once the goods-in bench has checked it.'],
        'receipt_other' => ['A delivery someone else is keying.', 'You can check it at the goods-in bench, set its supplier invoice, and book it in.'],
        'receipt' => ['One delivery: what arrived, where it went, and who checked it.', ''],
        'bench' => ['Every delivery not booked in yet, the earliest first, with how far its check at the goods-in bench got.',
            'Open one, check each product against the paperwork, then save the check.'],
        'receipt_bench' => ['Check this delivery against its paperwork, one product at a time.',
            'For each line: is the UK duty stamp on the pack, and did anything arrive short, extra, damaged, wrong or without a stamp? Then press "Save the check".'],
        'stock' => ['What is in each warehouse now, product by product.', 'Choose a warehouse or find a product. Open a product to see every change to its stock.'],
        'movements' => ['Every change to the stock, newest first: what changed, where, by whom and why.',
            'Choose a warehouse, a kind of change or a product to narrow the list.'],
        'stock_in' => ['Stock that comes in without a supplier delivery, one record each time.', 'Start a stock in, add the products, then make it final.'],
        'stock_out' => ['Stock that goes out without a website sale, one record each time.', 'Start a stock out, add the products, then make it final.'],
        'adjustments' => ['Corrections and write-offs of the stock figure, each line with its reason.', 'Start an adjustment, add the products, then make it final.'],
        'transfers' => ['Stock moved between our warehouses, or between two places of one warehouse.', 'Start a transfer, add the products, then make it final.'],
        'releases' => ['Another account\'s stock invoiced to us and moved into our warehouse.', 'Start a release from their invoice, add the products and prices, then make it final.'],
        'accounts' => ['What another account\'s room holds, what was released this month, and what we owe that account.',
            'Record each payment to the account here.'],
        'stock_op' => ['One stock record: its products, what it booked and its history.', ''],
        // Stock > Counts (pack A2a): "count" alone is not said here (ReceivingWordsTest: a shelf count, stock counts).
        'counts' => ['The plan of shelf counts at one warehouse, and what is due next.', 'Tick items, or take the next ones of the plan, and open a sheet for the shelf count.'],
        'count_sheets' => ['Every sheet of a shelf count: open, final, cancelled or stopped.', 'Open one to go on with it, or to see what it booked.'],
        'count_differences' => ['Shelf counts whose difference is above the limits, and stock counts the system asks someone to look at.',
            'Accept each one with a note, or reject it to put the old figure back.'],
        'count_sheet_open' => ['An open sheet of a shelf count: nothing is booked until you make it final.',
            'Scan or find each item, enter how many are on the shelf and press Enter, then open "Before you make it final".'],
        'count_sheet' => ['One shelf count record: what was found on the shelf, what it booked, and what became of each difference.', ''],
        'count_before' => ['What making this sheet final would do, the biggest difference first.', 'Answer the marked lines, then make it final.'],
        'reservations' => ['The stock CW keeps for each order of a store until it is sent, freed or runs out of time, newest first.',
            'Choose a store or a state, or search for a store\'s order reference, a product name or a CW number. You can only look: the stores and CW change these.'],
        'reservation' => ['One order of a store: the stock CW keeps for it, and what happened to that stock.', ''],
        // ---- Pack D (docs/decisions.md PD1-PD8) ----
        'sales_orders' => ['Every order of each store, newest first, with what became of it.',
            'Choose a store, the days, a state or how it came in. You can only look: the stores change their orders.'],
        'sales_order' => ['One order of a store: each unit sold, its warehouse item, and every stock change CW booked for it.', ''],
        'sales_dispatch' => ['Paid orders that are not sent yet, oldest first, and what was sent today and this week.',
            'An order waiting longer than the hours set on the Settings page is late. You can only look: the stores send orders.'],
        'sales_returns' => ['Units the customers sent back, and units cancelled before they were sent, newest order first.',
            'The store\'s own return page puts them back in stock. A cancel that could not go back on sale waits in a check room for a recount.'],
        // ---- end of pack D ----
        'incidents' => ['Problems the goods-in bench found in deliveries that are booked in: short, extra, damaged, the wrong product, or no duty stamp.',
            'Close each one with what was done, for example a credit asked for or the goods sent back.'],
        // Pack B
        'item_new' => ['Make a warehouse item that no store sells yet: stock that arrives before its web page, or trade-only or sister-only stock.',
            'Type its name and what you know, then press "Add the item". The rest can be added on its page later.'],
        'families' => ['Families group items: all the flavours or strengths of one product.', 'Open a family to put items in it or take them out.'],
        'family' => ['One family and the items in it.', 'Paste CW numbers or barcodes to put items in it, or tick items to take them out.'],
        'packs' => ['Sealed multipacks kept in stock as packs, each with the single item it holds and how many.',
            'Define a pack item, then book repacks on Stock › Repack. Check the lines that say something.'],
        'pack_links' => ['Every variant matched with more than 1 unit a sale, and what the pack items say of it.', 'Open a variant to change its match.'],
        'repack' => ['Break sealed packs into singles, or make packs from singles, with one record each time.',
            'Choose the pack item, which way and how many packs, then book it.'],
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => ['The invoices of our suppliers, each checked against the order and the delivery.',
            'To book the invoice of a delivery, open "Not invoiced yet" and press "Book its invoice".'],
        'credit_notes' => ['The credit notes our suppliers sent us.', 'Book one from the return it answers, or from the invoice it reduces.'],
        'to_match' => ['Final invoices that do not match their order or delivery, oldest first.',
            'Open one, see which line is off, then ask the supplier for a credit note or accept the difference with a reason.'],
        'not_invoiced' => ['Deliveries booked in that wait for their supplier invoice, newest first.', 'Press "Book its invoice", type the totals from the paper, and make it final.'],
        // pack J (PJ10, PJ14)
        'timed' => ['Draft invoices waiting for the time a person set: each becomes final by itself then.', 'Those that could not be made final come first: open one, put it right, set its time again.'],
        'arriving' => ['The goods of final supplier invoices that have not arrived yet, oldest first.', 'When a delivery comes, press "Receive" on its invoice: check it at the bench, then book it in.'],
        'returns' => ['Goods sent back to suppliers. Each return takes the stock out and is our claim for a credit (a debit note).',
            'Start one from a delivery\'s damaged or unstamped goods, or by hand.'],
        'supplier_invoice' => ['A supplier\'s paper as it was booked: what it bills, and how it matches the order and the delivery.',
            'While it is a draft, check the lines against the paper, type the totals, then make it final.'],
        'supplier_return' => ['Goods going back to a supplier, and what we claim for them.', 'While it is a draft, check the lines, then make it final: the stock goes out.'],
        // Pack F (docs/decisions.md PF6-PF9): Approvals › From the stores.
        'queue_suspense' => ['Lines of ERPNext deliveries, supplier returns, sales and corrections that the warehouse system could not match to an item.',
            'Type the item\'s code and place the line, or close it with a note when there is nothing to book.'],
        'queue_corrections' => ['Stock figures someone set in ERPNext. Nothing is booked until a person confirms one.',
            'Read what confirming would book, then confirm it or close it, each with a note.'],
        'queue_modes' => ['Selling-mode changes made in ERPNext that differ from what the warehouse system has.',
            'Accept a change, or keep what the warehouse system has, each with a note.'],
        'queue_oversold' => ['Paid orders the warehouse did not have the stock for.',
            'Sort each one out with the customer, add notes as you go, then mark it handled.'],
        // ---- Pack E
        'stock_by_store' => ['What each store can sell now, from the warehouse it sells from.', 'Choose a store. Open a product to see every change to its stock.'],
        'sales_by_store' => ['Orders and units each store sold, day by day.', 'Choose the days. The figures come from the orders the stores send to CW.'],
        'low_stock' => ['Products the stores can sell only a few of, or none.',
            'Order more, or check the shelf. A product\'s own "least stock to keep" is set on its reorder page.'],
        'stock_value' => ['What the stock is worth at its average cost, in pounds.', 'Look by product, by brand or by warehouse.'],
        'slow_stock' => ['Products in stock that have not sold for a long time.', 'Think about a promotion, a return to the supplier, or no more orders.'],
        'permission' => ['Which jobs can do one thing in this system.', 'Tick the jobs, say why, then save. Every change is kept with who, when and why.'],
        // ---- end Pack E
        // Pack H (0038; docs/decisions.md PH13, PH15).
        'handovers' => ['Stock we hand to a sister shop, one of our shop units or a trade customer, with a price on every line.',
            'Start a hand-over, add the items and make it final: the stock leaves the warehouse and the customer owes the amount.'],
        'handover' => ['One hand-over: who gets the stock, the items, the prices and the invoice.',
            'Add the items, check the prices, then make it final.'],
        'handover_customers' => ['The sister shops, shop units and trade customers we hand stock to, and the prices they paid last.',
            'Add a customer before its first hand-over. Load old prices from a file once.'],
        'handover_customer' => ['One customer: its details, the prices it paid last and its hand-overs.', 'Change the details when they change.'],
        'handover_balances' => ['What each customer owes us for the stock handed to it, less the payments received.',
            'Record a payment when the money arrives.'],
        'duty' => ['The vaping duty register: every delivery line that needed the duty stamp check, from the deliveries booked in.',
            'Filter it and download it when someone asks for the records.'],
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.3 How sure the computer is (the bands). The URL values (queue=Key ...) stay.

    public const BAND = [
        'Key' => 'Strong match',
        'Check' => 'Likely match – check it',
        'New item' => 'New product',
        "Can't tell" => 'Not sure – you choose',
        'Conflict' => 'Clues disagree',
        'Manual' => 'Renamed range',
    ];

    public const BAND_TITLE = [
        'Key' => 'Strong matches',
        'Check' => 'Likely matches – check them',
        'New item' => 'New products',
        "Can't tell" => 'Not sure – you choose',
        'Conflict' => 'Clues disagree',
        'Manual' => 'Renamed ranges',
    ];

    public const BAND_HELP = [
        'Key' => 'Same barcode, or copied from the other website, and the AI agrees. Usually one tap.',
        'Check' => 'The AI thinks it is the same, but no barcode proves it, or a detail differs.',
        'New item' => 'Nothing in the warehouse list fits. Create a new product.',
        "Can't tell" => 'Two or more products fit, for example the name does not say 10mg or 20mg.',
        'Conflict' => 'The barcode says one product and the AI another, one barcode is on two products, '
            . 'or the barcode points to a product a rule says cannot be right (for example a different strength).',
        'Manual' => 'The websites use different names for the same range (Crystal Pro Max = Hayati Pro Max).',
    ];

    /**
     * The same three strengths when the suggestion was found by the check on arrival (docs/decisions.md U144; the suggestion's flag
     * `rules_only`): a barcode or a copied web address and no AI, so BAND_HELP's "the AI thinks" would not be true. The check never
     * writes the other three strengths.
     */
    public const BAND_HELP_RULES = [
        'Check' => 'A barcode, or a web address copied from the main store, points to this item. No AI has looked at it: check the details, then confirm.',
        "Can't tell" => 'A barcode or a copied web address gives a clue, but not an answer. No AI has looked at it: check the details and choose the item yourself.',
        'Conflict' => 'The clues do not fit: the barcode and the web address point to different items, the barcode is on more than one item or '
            . 'on two variants of one store, or a rule says the item cannot be right. No AI has looked at it: decide it yourself.',
    ];

    /** How the computer found the suggestion (Queries::LANES). */
    public const LANE = [
        'barcode' => 'Same barcode',
        'transfer' => 'Copied between websites',
        'candidates' => 'AI search',
    ];

    /** What the AI said (Band::OUTCOMES, plus the short forms the screens printed). */
    public const AI = [
        'match' => 'Same product',
        'no_match_in_list' => 'No product fits',
        'no_match' => 'No product fits',
        'cannot_tell' => 'Not sure',
        'multiple_plausible' => 'Not sure – more than one fits',
        'not_a_product' => 'Not a real product',
    ];

    /** Warnings on a suggestion (Veto::SOFT_FLAGS, Band::CONFLICT_FLAGS and the queue's own). */
    public const FLAG = [
        'price_outlier' => 'Price very different',
        'target_not_published' => 'Warehouse product not on sale',
        'internal_conflict' => 'The product\'s own details disagree',
        'relabelled_line_unconfirmed' => 'Range has another name',
        'relabel_pending' => 'Range has another name',
        'line_alias_pending' => 'Range has another name',
        'strength_missing' => 'Strength not stated',
        'modifier_extra' => 'Extra word in the range name',
        'flavour_extra' => 'Extra flavour word',
        'line_number_extra' => 'Extra model number',
        'line_number_one_side' => 'Model number on one side only',
        'colour_extra' => 'Extra colour word',
        'volume_diff_attr' => 'Size written differently',
        'pack_one_side' => 'Pack size on one side only',
        'listing_multiplier' => 'Website sells a pack',
        'same_channel_target_shared' => 'Another page of this website uses this product',
        'gtin_also_on_inactive_item' => 'Barcode is also on an old product',
        'barcode_on_two_items' => 'Barcode is on 2 products',
        'multi_sku_gtin' => 'Barcode is on 2 products',
        'gtin_on_multiple_items' => 'Barcode is on 2 products',
        'gtin_dup_in_channel' => 'Barcode is on 2 pages of this website',
        'barcode_transfer_disagree' => 'Barcode and copy disagree',
        // The check on arrival (docs/decisions.md U144): on every suggestion it writes, and on each one that is not a clean match.
        'rules_only' => 'Found by the check on arrival: no AI has looked at it',
        'arrival_warned' => 'A detail needs a look: open it',
    ];

    /** A rule that says "cannot be this product" (Veto::CODES). */
    public const VETO = [
        'strength' => 'different strength',
        'nic_type' => 'different nicotine type',
        'form' => 'different kind of product',
        'line_number' => 'different model number',
        'line_modifier' => 'different range (extra word)',
        'line_word' => 'the range name is different',
        'flavour_superset' => 'one flavour has extra words',
        'flavour_diff' => 'different flavour',
        'liquid_ml' => 'different size (ml)',
        'puffs' => 'different number of puffs',
        'colour' => 'different colour',
        'ohm' => 'different coil (ohm)',
        'pack' => 'different pack size',
        'multipack' => 'one is a multipack',
        'placeholder' => 'not a real product',
        'sku_state' => 'the warehouse product cannot be used',
    ];

    /** "Why it is here" (the band reasons, by prefix). */
    public const BAND_REASON = [
        'barcode_key' => 'barcode and AI agree',
        'transfer_key' => 'copied between websites and the AI agrees',
        'ai_only' => 'AI only',
        'ai_only_max_check' => 'AI only',
        'soft' => 'small warnings',
        'soft_flags' => 'small warnings',
        'barcode_on_2_items' => 'barcode on 2 products',
        'ai_vs_barcode' => 'AI and barcode disagree',
        'veto_on_key' => 'a rule says the item the clue points to cannot be right',
        'alias_pending' => 'the range has another name',
        'no_candidates' => 'nothing similar in the warehouse list',
        'all_candidates_vetoed' => 'every similar product is ruled out',
        'placeholder' => 'not a real product',
        // The check on arrival's reasons (Matching\ArrivalRules::REASONS; docs/decisions.md M65, U144): what the barcode and the copied
        // web address of the variant say. Each key is exact.
        'arrival_barcode' => 'its barcode is on this item',
        'arrival_copy' => 'its web address was copied from this item\'s variant on the main store',
        'arrival_barcode_and_copy' => 'its barcode and its copied web address both point to this item',
        'arrival_not_compared' => 'the item has no variant to compare it with',
        'arrival_renamed_range' => 'the range has another name on the main store',
        'arrival_clues_differ' => 'its barcode points to one item and its web address to another',
        'arrival_barcode_two_items' => 'its barcode is on more than one item',
        'arrival_barcode_same_store' => 'another variant of the same store has the same barcode',
        'arrival_item_taken' => 'the item already has a variant of this store, or is suggested for one',
        'arrival_barcode_pack' => 'its barcode is recorded as a pack of more than one',
        'arrival_copy_pack' => 'its web address was copied from a main-store variant that sells more than one of this item at a time',
        'arrival_barcode_no_item' => 'its barcode is on another variant that has no item yet',
        'arrival_copy_no_item' => 'its web address was copied from a main-store variant that has no item',
        'arrival_copy_several' => 'its web address is used by more than one item',
        'arrival_no_longer' => 'the barcode or web address that pointed to an item is no longer on it',
    ];

    /** The field-by-field comparison. */
    public const FIELD_STATE = [
        'same' => 'Same',
        'differs' => 'Different',
        'conflict' => 'Different',
        'alike' => 'Written differently',
        'spelt' => 'Written differently',
        'unknown' => 'Not stated',
        'missing' => 'Not stated',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.2 / 1.4 Matching decisions and checks

    /** Why a match needs a second OK (match_decision.needs_second). */
    public const NEEDS_SECOND = [
        'protected_sku' => 'Website already sells warehouse stock',
        'units_per_item' => '1 sale is not 1 product',
        'previously_rejected' => 'Was marked wrong before',
        'counted_item' => 'Stock was counted in the warehouse',
        'merge' => 'Joins two products',
        'bulk' => 'Confirmed from a ticked list',
    ];

    /** match_decision.action (DecisionService::ACTIONS). */
    public const ACTION = [
        'link' => 'Match',
        'unlink' => 'Undo the match',
        'new_item' => 'Create a new product',
        'ignore' => 'Ignore: not a real product',
        'reject' => 'No, wrong product',
        'suggest' => 'Suggested by the computer',
        'merge_skus' => 'Join products',
        'split' => 'Undo the join',
        'unignore' => 'Take off the ignored list',
        'send_back' => 'Send back for matching',
    ];

    public const DECISION_STATE = [
        'pending_second' => 'Waiting for a second OK',
        'applied' => 'Done',
        'withdrawn' => 'Cancelled',
    ];

    public const LISTING_STATUS = [
        'unmapped' => 'Not matched yet',
        'suggested' => 'Waiting for a decision',
        'mapped' => 'Matched',
        'ignored' => 'Ignored',
        'quarantined' => 'On hold (shows as out of stock)',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.5 Stock. "Count" is only for counting the shelves.

    /** The sell policy of a warehouse product (Stock::POLICIES), shown as its stock rule. */
    public const POLICY = [
        'legacy' => 'Website uses its own stock (not linked yet)',
        'strict' => 'Website sells warehouse stock only',
        'backorder' => 'Can sell when out (customer waits)',
        'stopped' => 'Not for sale',
        'protected' => 'Website already sells warehouse stock: changes need a second OK',
    ];

    /** The stock columns (Stock::BUCKETS) and their total. */
    public const STOCK = [
        'on_hand' => 'In the building',
        'allocated' => 'Sold, waiting to ship',
        'held' => 'Reserved (not paid)',
        'incoming' => 'On its way', // pack J (PJ1): units of final supplier invoices not arrived yet that the websites may sell
        'available' => 'Free to sell',
        'total' => 'Total we can sell from',
        'warehouse_MAIN' => 'Main warehouse',
    ];

    /** Stock › Overview and Stock › Movements (/ui/stock, /ui/stock/movements; StockController). */
    public const STOCK_VIEW = [
        'warehouse' => 'Warehouse',
        'all_warehouses' => 'All warehouses',
        'find' => 'Product',
        'find_hint' => 'Name, brand or CW number',
        'show' => 'Show',
        'show_stock' => 'Products with stock',
        'show_all' => 'Every product',
        'show_negative' => 'Below zero',
        'apply' => 'Show the list',
        'clear' => 'Clear the filters',
        'product' => 'Product',
        'brand' => 'Brand',
        'not_sellable' => 'not for sale',
        'totals' => 'Each warehouse',
        'products' => 'Products',
        'rows_one' => '1 line.',
        'rows_many' => '%s lines.',
        'page' => 'Page %s of %s.',
        'prev' => 'Previous page',
        'next' => 'Next page',
        'none' => 'No stock to show.',
        'none_text' => 'No warehouse product has stock here yet.',
        'none_filtered' => 'Nothing matches your filter.',
        'none_filtered_text' => 'Your filter hides every line. Clear it to see them all.',
        'later' => 'Another account\'s stock in our building, and what we owe that account: Transfers › Balance owed. Stock by store comes in a later update.',
        // The board (design v4)
        'figures' => 'Main figures',
        'tile_products' => 'Products',
        'tile_products_sub' => 'in the warehouse list',
        'tile_in_stock' => 'Products in stock',
        'tile_in_stock_sub' => 'at least 1 unit the websites can sell from',
        'tile_units' => 'Units',
        'tile_units_sub' => 'in all warehouses',
        'tile_none' => 'Products with no stock',
        'tile_none_sub' => 'nothing the websites can sell',
        'board' => 'Stock by product',
        'board_note' => 'One row per product, grouped by status.',
        'status' => 'Status',
        'reserved' => 'Reserved',
        'group_out' => 'Out of stock',
        'group_in' => 'In stock',
        'shown' => '%s shown · %s in all',
        'showing' => 'Showing %s of %s products.',
        'foot' => 'Reserved = in a customer\'s checkout, or paid and not shipped yet. Available = what the websites can sell: the warehouses they sell from, less reserved.',
        'moves_board' => 'Stock movements',
        'moves_note' => 'Every change to stock, newest first.',
        'moves_one' => '1 movement',
        'moves_many' => '%s movements',
        'moves_foot' => 'A movement is never changed or deleted. A mistake is put right with a new movement, so the history always adds up.',
        // Movements
        'type' => 'Kind of change',
        'all_types' => 'Every kind',
        'figure' => 'Figure',
        'figure_on_hand' => 'Stock in the building',
        'figure_all' => 'Every figure (also orders on the way)',
        'older' => 'Older changes',
        'newest' => 'Back to the newest',
        'moves_none' => 'No stock changes to show.',
        'moves_none_text' => 'Stock changes appear here once stock is booked.',
        'moves_shown' => 'Newest first, %s at a time.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Stock › Reservations (docs/decisions.md RS1-RS12): the stock kept for each store's orders. Read only.

    /** A reservation's state as the engine keeps it (reservation.status). */
    public const RESV_STATE = [
        'held' => 'Reserved (not paid)',
        'committed' => 'Sold (paid)',
        'released' => 'Freed by the store',
        'expired' => 'Ran out of time',
    ];

    /**
     * The board's groups (ReservationViews::GROUPS): the engine's states, with a paid order "waiting to ship" while one of its items
     * is, and "sent, cancelled or returned" once none is.
     */
    public const RESV_GROUP = [
        'held' => 'Reserved now (in a checkout, not paid)',
        'to_ship' => 'Sold, waiting to ship',
        'closed' => 'Sold: sent, cancelled or returned',
        'released' => 'Freed by the store (not paid)',
        'expired' => 'Ran out of time (not paid)',
    ];

    /** A row's status label, by its group. */
    public const RESV_CHIP = [
        'held' => 'Reserved',
        'to_ship' => 'To ship',
        'closed' => 'Dealt with',
        'released' => 'Freed',
        'expired' => 'Ran out',
    ];

    /** What became of one sold item (reservation_unit.state). */
    public const RESV_UNIT = [
        'held' => 'Reserved (not paid)',
        'allocated' => 'Sold, waiting to ship',
        'shipped' => 'Sent to the customer',
        'cancelled' => 'Cancelled',
        'released' => 'Freed (not paid)',
        'returned' => 'Returned by the customer',
    ];

    /** How a paid order reached CW (reservation.origin). */
    public const RESV_ORIGIN = [
        'reserved' => 'Reserved in the checkout first, then paid',
        'unreserved' => 'Paid without a reservation first (for example, CW could not be reached at the checkout)',
        'opening' => 'Already open when the store joined CW',
    ];

    /** Stock › Reservations and one reservation's page (/ui/stock/reservations; ReservationsController). */
    public const RESV = [
        'find' => 'Order reference, product name or CW number',
        'state' => 'State',
        'any_state' => 'Every state',
        'clear' => 'Clear the filters',
        'download' => 'Download this list as a spreadsheet (CSV)',
        'store_count' => 'reserved now',
        // The figures
        'figures' => 'Main figures',
        'tile_held' => 'Units reserved now',
        'tile_held_sub_one' => 'in 1 checkout, not paid yet',
        'tile_held_sub' => 'in %s checkouts, not paid yet',
        'tile_soon' => 'Run out within the hour',
        'tile_soon_sub' => 'reservations CW frees if nobody pays',
        'tile_to_ship' => 'Units sold, waiting to ship',
        'tile_to_ship_sub' => 'paid orders of every store',
        'to_ship_all' => 'Units sold and waiting to ship are added up for every store together: choose All stores to see them.',
        // The board
        'board' => 'Reservations',
        'board_note' => 'Newest first, %s at a time, grouped by state.',
        'group_one' => '1 on this page',
        'group_many' => '%s on this page',
        'order' => 'Order',
        'store' => 'Store',
        'status' => 'Status',
        'products' => 'Products',
        'units' => 'Units',
        'started' => 'Started',
        'expires' => 'Kept until',
        'outcome' => 'What became of it',
        'try' => 'try %s',
        'overdue' => 'time is up: CW frees it within minutes',
        'unlinked_one' => '1 sold item is not linked to a warehouse product: no stock is kept for it',
        'unlinked_many' => '%s sold items are not linked to a warehouse product: no stock is kept for them',
        // What became of it
        'out_held' => 'Waiting for payment',
        'out_all_allocated' => 'Waiting to ship',
        'out_all_shipped' => 'Sent to the customer',
        'out_all_cancelled' => 'Cancelled',
        'out_all_returned' => 'Returned by the customer',
        'out_part_held' => '%s reserved',
        'out_part_allocated' => '%s waiting to ship',
        'out_part_shipped' => '%s sent',
        'out_part_cancelled' => '%s cancelled',
        'out_part_released' => '%s freed',
        'out_part_returned' => '%s returned',
        'out_none' => 'No items',
        'out_released' => 'Freed by the store',
        'out_tombstone' => 'Freed by the store before CW had reserved anything',
        'out_expired' => 'Nobody paid in time: CW freed it',
        'paid_at' => 'Paid %s',
        'freed_at' => 'Freed %s',
        'ran_out_at' => 'Ran out %s',
        // Empty lists, notes, paging
        'none' => 'No reservations yet.',
        'none_store' => 'No reservations for this store yet.',
        'none_text' => 'When a customer starts a checkout on a store, the stock CW keeps for that order appears here. It stays here once it is paid, sent, freed or run out of time.',
        'none_filtered' => 'Nothing matches your filter.',
        'none_filtered_text' => 'No reservation fits the state and the search you chose. Clear them to see every reservation.',
        'product_search' => 'A product search shows the orders that hold the product now: reserved in a checkout, or sold and waiting to ship. A product\'s older orders are in Stock › Movements.',
        'capped' => 'More than %s orders hold these products now, so some are not shown. Search for one product or one order.',
        'older' => 'Older reservations',
        'newest' => 'Back to the newest',
        'foot' => 'Units are warehouse units. Nothing on this page changes a reservation: the stores make, pay, free and send them, and CW frees one that runs out of time.',
        // One reservation
        'title' => 'Order %s',
        'facts' => 'About this reservation',
        'f_store' => 'Store',
        'f_ref' => 'The store\'s order reference',
        'f_state' => 'State',
        'f_started' => 'Started',
        'f_expires' => 'Kept until',
        'f_was_due' => 'Was kept until',
        'f_paid' => 'Paid',
        'f_origin' => 'How it came in',
        'f_freed' => 'Freed',
        'f_ran_out' => 'Ran out',
        'f_tries' => 'Tries',
        'f_tries_text' => '%s (the checkout was started again)',
        'f_products' => 'Products',
        'f_units' => 'Warehouse units',
        'lines' => 'Products on this order',
        'no_lines' => 'No product is recorded for this order.',
        'l_product' => 'Product',
        'l_warehouse' => 'Warehouse',
        'l_status' => 'Status',
        'l_items' => 'Sold items',
        'l_units' => 'Units',
        'l_unlinked' => 'Not linked to a warehouse product: no stock is kept for it',
        'l_store_product' => 'The store\'s product %s',
        'l_per_item' => '1 sold item = %s warehouse units',
        'l_sent' => 'sent %s',
        'history' => 'What happened to the stock',
        'history_text' => 'Every stock change CW booked for this order, oldest first.',
        'no_history' => 'No stock was moved for this order.',
        'h_what' => 'What happened',
        'h_when' => 'When',
        'h_items' => 'Sold items',
        'h_figures' => 'Stock figures',
        'h_who' => 'By',
        'h_cw' => 'CW, by itself',
        'h_figure' => '%s %s',
        // The CSV
        'csv_more' => 'only the newest %s reservations: narrow the filter for the rest',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // ---- Pack D (docs/decisions.md PD1-PD14): Stock › Sales, each store's orders, dispatch and returns. Read only. ----

    /**
     * Store orders' state filter (Ui\SalesViews::STATES): the engine's order states (paid = every paid order), then the orders with at
     * least one sold unit sent, cancelled or returned.
     */
    public const SALE_STATE = [
        'held' => 'Reserved, not paid',
        'paid' => 'Paid (every sale)',
        'shipped' => 'With a unit sent',
        'cancelled' => 'With a unit cancelled',
        'returned' => 'With a unit returned',
        'released' => 'Freed by the store',
        'expired' => 'Ran out of time',
    ];

    /** How an order came in (reservation.origin), short, for the filter and the list (RESV_ORIGIN says it in full on the order). */
    public const SALE_ORIGIN = [
        'reserved' => 'Reserved first',
        'unreserved' => 'Paid without a reservation',
        'opening' => 'Open when the store joined',
    ];

    /** Stock › Sales › Orders (/ui/stock/sales; Ui\Controller\SalesController). */
    public const SALE = [
        'state' => 'State',
        'any_state' => 'Every state',
        'origin' => 'How it came in',
        'any_origin' => 'Any way',
        'from' => 'Placed from (day)',
        'to' => 'Placed until (day)',
        'clear' => 'Clear the filters',
        'download' => 'Download this list as a spreadsheet (CSV)',
        'store_count' => 'paid orders waiting to ship',
        'period_default' => 'Placed in the last %s days',
        'period_today' => 'Placed today',
        'period_range' => 'Placed from %s to %s',
        'period_from' => 'Placed from %s',
        'period_to' => 'Placed until %s',
        'period_all' => 'Placed any time',
        'show_all' => 'Any time',
        'show_default' => 'The last %s days',
        'bad_dates' => 'A day you typed is not a real day, or the first day is after the last one: the list shows the last %s days instead.',
        'board' => 'Orders',
        'board_note' => 'Newest first, %s at a time.',
        'on_page_one' => '1 order on this page',
        'on_page_many' => '%s orders on this page',
        'order' => 'Order',
        'store' => 'Store',
        'status' => 'Status',
        'placed' => 'Placed',
        'paid' => 'Paid',
        'came_in' => 'How it came in',
        'units' => 'Units',
        'outcome' => 'What became of it',
        'older' => 'Older orders',
        'newest' => 'Back to the newest',
        'stopped' => 'This page read %s orders and stopped before it was full. Older orders may still match.',
        'read_on' => 'Read on',
        'none' => 'No orders yet.',
        'none_store' => 'No orders for this store yet.',
        'none_text' => 'When a customer starts a checkout on a store, the order appears here and stays here once it is paid, sent, cancelled, freed or run out of time.',
        'none_filtered' => 'No order matches your filter.',
        'none_filtered_text' => 'No order fits the days, the state or the way in you chose. Clear them to see every order of this store choice.',
        'foot' => 'Units are warehouse units. Nothing on this page changes an order: the stores make, pay, send, cancel and free them.',
        'csv_more' => 'only the newest %s orders: narrow the filter for the rest',
        'csv_stopped' => 'the list stopped after reading %s orders: narrow the filter for the rest',
    ];

    /** One order's page (/ui/stock/sales/{id}): its facts, its sold units, and each unit's stock changes. */
    public const SALE_ORDER = [
        'title' => 'Order %s',
        'facts' => 'About this order',
        'f_store' => 'Store',
        'f_ref' => 'The store\'s order reference',
        'f_state' => 'State',
        'f_placed' => 'Placed',
        'f_paid' => 'Paid',
        'f_origin' => 'How it came in',
        'f_waiting' => 'Waiting to ship since payment',
        'f_products' => 'Store variants',
        'f_units' => 'Warehouse units',
        'f_kept' => 'Stock kept for it',
        'kept_link' => 'See the reservation',
        'units' => 'Sold units',
        'units_text' => 'One row for each unit the customer bought, as the store numbers it.',
        'no_units' => 'No sold unit is recorded for this order.',
        'u_unit' => 'Unit',
        'u_item' => 'Warehouse item',
        'u_variant' => 'Store variant',
        'u_warehouse' => 'Warehouse',
        'u_state' => 'Status',
        'u_sent' => 'Sent',
        'u_unlinked' => 'Not linked to a warehouse item at the sale: no stock is kept for it',
        'u_per_item' => '1 sold unit = %s warehouse units',
        'u_variant_text' => 'Variant %s',
        'more_units' => 'More units of this order',
        'first_units' => 'Back to the first units',
        'steps' => 'What happened to each unit',
        'steps_text' => 'Every stock change CW booked for each unit on this page, oldest first. Open a change to see it among the item\'s movements.',
        'steps_unit' => 'Unit %s',
        'steps_none' => 'No stock change was booked for this unit.',
        'steps_unlinked' => 'Not linked to a warehouse item at the sale, so no stock moved and CW keeps no steps for it. Its status now is shown above.',
        'steps_capped' => 'Only the first %s stock changes of these units are shown.',
        's_what' => 'What happened',
        's_when' => 'When',
        's_where' => 'Warehouse',
        's_figures' => 'Stock figures',
        's_who' => 'By',
        's_open' => 'In Movements',
        's_open_link' => 'Open',
        's_cw' => 'CW, by itself',
        'late' => 'late',
    ];

    /** Stock › Sales › Dispatch (/ui/stock/sales/dispatch; Ui\Controller\DispatchController). */
    public const DISPATCH = [
        'store_count' => 'orders waiting to ship',
        'figures' => 'Main figures',
        'tile_waiting' => 'Waiting to ship',
        'tile_waiting_sub' => '%s units, paid and not sent',
        'tile_late' => 'Late',
        'tile_late_sub' => 'paid more than %s hours ago',
        'tile_late_more' => 'at least %s',
        'tile_today' => 'Sent today',
        'tile_today_sub' => '%s units',
        'tile_week' => 'Sent this week',
        'tile_week_sub' => '%s units since Monday',
        'tile_undone' => 'Sending undone this week',
        'tile_undone_sub' => 'units back with the orders to ship',
        'show' => 'Show',
        'show_waiting' => 'Waiting to ship',
        'show_today' => 'Sent today',
        'show_week' => 'Sent this week',
        'show_undone' => 'Sending undone',
        'order' => 'Order',
        'store' => 'Store',
        'paid' => 'Paid',
        'waiting' => 'Waiting',
        'status' => 'Status',
        'units' => 'Units',
        'note' => 'Note',
        'sent' => 'Sent',
        'now' => 'Now',
        'undone_at' => 'Undone',
        'unit' => 'Unit',
        'who' => 'By',
        'hours_one' => '1 hour',
        'hours_many' => '%s hours',
        'days_many' => '%s days',
        'under_hour' => 'under an hour',
        'was_undone' => 'Sending was undone before',
        'unlinked_one' => '1 unit not linked to a warehouse item',
        'unlinked_many' => '%s units not linked to a warehouse item',
        'board_waiting' => 'Waiting to ship, oldest first, %s at a time.',
        'board_sent' => 'Newest first, %s at a time.',
        'later' => 'Later orders',
        'earlier' => 'Earlier orders',
        'back' => 'Back to the start',
        'none_waiting' => 'Nothing is waiting to ship.',
        'none_waiting_text' => 'Every paid order of this choice is sent, cancelled or returned.',
        'none_today' => 'Nothing was sent today yet.',
        'none_week' => 'Nothing was sent this week yet.',
        'none_sent_text' => 'When a store sends a paid order, it appears here with the time it was sent.',
        'none_undone' => 'No sending was undone.',
        'none_undone_text' => 'When a store resets a dispatch, the units go back to waiting to ship and appear here.',
        'undone_unlinked' => 'Units that are not linked to a warehouse item move no stock, so an undone sending of one is not listed here.',
        'late_capped' => 'The late figure looked at the oldest %s waiting orders only.',
        'foot' => 'Units are sold units. Nothing on this page changes an order: the stores send them and undo a sending.',
        'csv_more' => 'only the oldest %s waiting orders: choose one store for the rest',
    ];

    /** A waiting order's status label on Dispatch: paid longer ago than the setting's hours, or not. */
    public const DISPATCH_CHIP = [
        'late' => 'Late',
        'on_time' => 'On time',
    ];

    /** Stock › Sales › Returns (/ui/stock/sales/returns; Ui\Controller\SalesReturnsController). */
    public const SALE_RETURNS = [
        'store_count' => 'units waiting for a recount',
        'kind' => 'What happened',
        'any_kind' => 'Returned and cancelled',
        'figures' => 'Main figures',
        'tile_verify' => 'Waiting for a recount',
        'tile_verify_sub' => 'cancelled units in %s',
        'board' => 'Returned and cancelled units',
        'board_note' => 'Newest order first, %s at a time.',
        'order' => 'Order',
        'store' => 'Store',
        'unit' => 'Unit',
        'item' => 'Warehouse item',
        'what' => 'What happened',
        'when' => 'When',
        'back_on_sale' => 'Back on sale?',
        'stock_now' => 'Where the stock is',
        'older' => 'Older orders',
        'newest' => 'Back to the newest',
        'none' => 'Nothing was returned or cancelled yet.',
        'none_store' => 'Nothing of this store was returned or cancelled yet.',
        'none_text' => 'When a customer sends units back, or a store cancels paid units before sending them, they appear here.',
        'none_filtered' => 'Nothing matches your filter.',
        'none_filtered_text' => 'Choose Returned and cancelled to see both.',
        'clear' => 'Clear the filter',
        'download' => 'Download this list as a spreadsheet (CSV)',
        'foot' => 'Nothing on this page changes the stock: the store\'s own return page books a return, and a recount settles a unit in the check room.',
        'csv_more' => 'only the newest %s units: choose one store or one kind for the rest',
    ];

    /** Returns' kind filter: what became of the unit (reservation_unit.state). */
    public const SALE_RETURN_KIND = [
        'returned' => 'Returned by the customer',
        'cancelled' => 'Cancelled before sending',
    ];

    /** Back on sale? A short answer per returned or cancelled unit (Ui\SalesViews::restock). */
    public const SALE_RESTOCK = [
        'yes' => 'Yes',
        'no' => 'No',
        'none' => 'No stock kept',
    ];

    /** Where the stock of a returned or cancelled unit is now, in words (%s: a warehouse's name). */
    public const SALE_RESTOCK_TEXT = [
        'returned' => 'Back in stock at %s',
        'cancelled' => 'Back on sale at %s',
        'cancelled_held' => 'Not paid yet: the reservation was freed at %s',
        'verify_open' => 'Not on sale: moved to %s, waiting for a recount',
        'verify_resolved' => 'Moved to %s for a recount: the recount is done',
        'verify_dismissed' => 'Moved to %s for a recount: the recount was closed',
        'unlinked' => 'Not linked to a warehouse item at the sale: no stock was kept',
        'unknown' => 'No stock change was found for it',
    ];
    // ---- end of pack D ----

    // ------------------------------------------------------------------------------------------------------------------
    // Pack A1: Stock In, Stock Out, Adjustments, Transfers, Releases from another account and the balance owed

    // ------------------------------------------------------------------------------------------------------------------
    // Stock > Counts (pack A2a; docs/decisions.md CT14-CT15). One name for one thing: a "count sheet"; "make it final" as every other
    // record; the "difference" between what was counted and what the system held; "put back" for a count that is rejected.

    /** How counting works, on the first page of the tab (a folded list). */
    public const COUNT_HOW = [
        'Choose what to count: tick items below, take the next ones of the plan, search a brand or a word, or start an empty sheet.',
        'On the sheet, scan or find each item, type how many are on the shelf and press Enter: it is saved at once. Leave an item empty if you '
            . 'did not count it; 0 means you counted and found none.',
        'Or download the sheet, count on paper, and upload the filled-in file. You see what it would fill in before anything is saved.',
        'Open "Before you make it final". It lists every difference, the biggest first. If stock of an item moved after you counted it, you '
            . 'choose: count it again, or keep your count.',
        'Make it final: the shelf figures are right at once. A difference above the limits waits on Differences for someone to accept or reject it.',
    ];

    /** The board's groups of "Next to count" (the state of an item in the plan). */
    public const COUNT_GROUP = [
        'never' => 'Never counted',
        'overdue' => 'Overdue',
        'inside' => 'Inside the plan',
    ];

    /** The board's groups of the list of count sheets. */
    public const COUNT_SHEET_GROUP = [
        'draft' => 'Open sheets',
        'posted' => 'Final',
        'reversed' => 'Cancelled',
        'put_back' => 'Records that put a count back',
        'cancelled' => 'Stopped before they were final',
    ];

    /** count_sheet.kind => what the record is. */
    public const COUNT_KIND = [
        'sheet' => 'Count sheet',
        'reversal' => 'Cancellation of a count sheet',
        'line_reversal' => 'A count put back',
    ];

    /** The groups of "Count differences to check": a sheet line above the limits, and the two reviews the count engine opens itself. */
    public const COUNT_REVIEW = [
        'line' => 'Above the limits',
        'count_after_movements' => 'Stock moved after the count',
        'count_near_ship' => 'A parcel left close to the count',
        'ship_near_count' => 'A parcel left close to a count time',
        'unship_near_count' => 'A parcel came back close to a count time',
    ];

    /** One line under each group's title: what it means and what the answers do. */
    public const COUNT_REVIEW_HELP = [
        'line' => 'The count changed the shelf figure by more than the limits allow without a look. Accept it if the count is right. Reject it and '
            . 'the old figure comes back.',
        'count_after_movements' => 'Stock came in, or was corrected, after the item was counted, and the count overwrote it. Accept it if the count '
            . 'already had that stock in it. Reject it and the count is put back.',
        'count_near_ship' => 'A parcel with this item left within ten minutes of the count, so the count may or may not have it. Accept it if the '
            . 'shelf figure is right. Reject it and the count is put back.',
        'ship_near_count' => 'A parcel with this item was reported sent within ten minutes of the item\'s count time, so the shelf figure may take it '
            . 'off once too often or too seldom. Look at the shelf. Accept it with a note if the figure is right; if not, count the item again.',
        'unship_near_count' => 'A parcel with this item was reported back on the shelf within ten minutes of the item\'s count time, so the shelf figure '
            . 'may have it twice. Look at the shelf. Accept it with a note if the figure is right; if not, count the item again.',
    ];

    /** The line filter of an open sheet. */
    public const COUNT_SHOW = [
        'all' => 'Every line',
        'todo' => 'Not counted yet',
        'done' => 'Counted',
    ];

    /** What became of a line when its sheet was made final. */
    public const COUNT_LINE = [
        'booked' => 'Booked',
        'stale' => 'Left out: the item was counted again later',
        'put_back' => 'Booked, then put back by',
    ];

    /** The check of a difference above the limits (count_sheet_line.review). */
    public const COUNT_CHECK = [
        'open' => 'Waiting on Differences',
        'accepted' => 'Difference accepted',
        'rejected' => 'Difference rejected',
    ];

    /** Why a row of an uploaded file cannot be read. */
    public const COUNT_BAD = [
        'no_code' => 'no CW number',
        'not_whole' => 'not a whole number',
        'negative' => 'below zero',
        'too_large' => 'too large to be a count',
        'twice' => 'the item is in the file twice',
    ];

    /** The pages' words. */
    public const COUNTS = [
        // Next to count
        'find' => 'A brand, a word of the name, or a CW number',
        'warehouse' => 'Warehouse',
        'show' => 'How many to show',
        'show_n' => 'The next %s',
        'clear' => 'Back to the plan',
        'figures' => 'The count plan in figures',
        'tile_items' => 'Items in the plan',
        'tile_items_sub' => 'with a stock figure at %s',
        'tile_inside' => 'Inside the plan',
        'tile_inside_sub' => 'counted in the last %s days',
        'tile_overdue' => 'Overdue',
        'tile_overdue_sub' => 'never counted, or more than %s days ago',
        'tile_never' => 'Never counted',
        'tile_never_sub' => 'they are part of the overdue ones',
        'plan' => 'The plan: every item is counted at least once every %s days.',
        'plan_link' => 'Change the days',
        'how' => 'How counting works',
        'none' => 'Nothing to count here yet.',
        'none_text' => 'This warehouse has no stock figure for any item. Search for an item above, or open an empty sheet and scan what is on the shelf.',
        'none_found' => 'No item matches your search.',
        'none_found_text' => 'Try another word, a brand, or a CW number. Or go back to the plan.',
        'board' => 'Next to count',
        'board_note' => 'The first %s: never counted first (best sellers first), then the ones counted longest ago.',
        'board_found' => 'Items found for "%s"',
        'board_found_note' => '%s found, by CW number.',
        'group_one' => '1 on this page',
        'group_many' => '%s on this page',
        'tick' => 'Put on the sheet',
        'tick_one' => 'Put %s on the sheet',
        'item' => 'Item',
        'status' => 'Status',
        'stock_now' => 'Stock now',
        'last_counted' => 'Last counted',
        'never' => 'never',
        'sells' => 'Sold a day',
        'no_sales' => 'no sales figure',
        'foot' => 'This is a work list of up to %s items, not a report: what you count and make final leaves it, and the next ones move up.',
        'open_title' => 'Open a count sheet',
        'open_text' => 'The sheet is for %s. It holds at most %s items; open another one for the rest. Only you enter and finish a sheet you open.',
        'place' => 'Place (optional)',
        'no_place' => 'The whole warehouse',
        'note' => 'Note (optional)',
        'open_ticked' => 'Open a sheet for the ticked items',
        'open_found' => 'Open a sheet for all %s found',
        'open_next_n' => 'How many',
        'open_next' => 'Open a sheet for the next ones',
        'open_empty' => 'Open an empty sheet',
        // Count sheets (the list)
        'find_sheet' => 'A sheet number (CNT-000123), or an item on a sheet',
        'state' => 'Show',
        'any_state' => 'Every sheet',
        'any_warehouse' => 'Every warehouse',
        'clear_sheets' => 'Show every sheet',
        'download_list' => 'Download for Excel (CSV)',
        'by_item' => 'Your search was read as an item: these are the sheets that have it on a line.',
        'no_sheets' => 'No count sheets yet.',
        'no_sheets_text' => 'Open one from Next to count.',
        'no_sheet_found' => 'No count sheet matches.',
        'no_sheet_found_text' => 'Your search or filter hides every sheet. Clear it to see them all.',
        'sheets_board' => 'Count sheets by status',
        'sheets_note' => 'Newest first, %s a page.',
        'sheets_one' => '1 on this page',
        'sheets_many' => '%s on this page',
        'sheet' => 'Sheet',
        'where' => 'Where',
        'lines' => 'Lines',
        'counted_of' => '%s of %s counted',
        'changed' => 'Units changed',
        'waiting' => 'Differences waiting',
        'opened_by' => 'Opened by',
        'next' => 'What next',
        'next_enter' => 'Enter what you count.',
        'next_post' => 'Check the differences, then make it final.',
        'next_check' => '%s differences wait on Differences.',
        'next_done' => 'Nothing: it is done.',
        'next_stopped' => 'Nothing: it was stopped and booked nothing.',
        'next_put_back' => 'It put back the count of line %s of %s.',
        'next_cancels' => 'It cancelled %s and put every figure back.',
        'no_number' => 'No number yet',
        'opened_on' => 'opened %s',
        'posted_on' => 'final %s',
        'newest' => 'Back to the newest',
        'older' => 'Older sheets',
        // A sheet
        'title_open' => 'Count sheet (no number yet)',
        'title_sheet' => 'Count sheet %s',
        'title_put_back' => 'A count put back: %s',
        'facts' => 'About this sheet',
        'kind' => 'What it is',
        'opened' => 'Opened',
        'posted' => 'Made final',
        'stopped' => 'Stopped',
        'about' => 'What it is about',
        'puts_back' => 'It puts back the count of line %s of %s',
        'cancels' => 'It cancels %s',
        'cancelled_by' => 'Cancelled by',
        'cancel_waiting' => 'a cancellation that waits for a reviewer\'s OK',
        'not_opener' => 'Only the person who opened this sheet enters its quantities and makes it final.',
        'open_one' => '1 difference of this sheet waits for an answer:',
        'open_many' => '%s differences of this sheet wait for an answer:',
        'scan_title' => 'Scan an item',
        'scan_label' => 'Barcode or CW number',
        'scan_button' => 'Find it',
        'scan_case' => 'That barcode is an outer case of %s units. Type how many units you counted, not how many cases.',
        'scan_not_on_sheet' => '%s (%s) is not on this sheet.',
        'scan_add' => 'Put it on the sheet',
        'scan_unknown' => 'No item has "%s". Nothing was changed. Check the barcode, or type the CW number with its letters (CW-000123).',
        'scan_ambiguous' => 'The barcode "%s" is on more than one item, so it is not used. Nothing was changed. Type the item\'s CW number instead.',
        'lines_title' => 'Items on this sheet',
        'lines_counted' => '%s of %s counted',
        'no_lines' => 'This sheet has no items yet. Scan the first one above.',
        'no_lines_shown' => 'No line to show here. Choose "Every line".',
        'line_no' => 'line %s',
        'barcodes' => 'Barcodes: %s',
        'mode' => 'Selling mode',
        'no_mode' => 'not set',
        'reserved' => '%s of them reserved for orders',
        'blind' => 'shown once counted',
        'counted' => 'Counted',
        'counted_of_item' => 'How many %s you counted',
        'counted_when' => 'counted %s',
        'counted_by_when' => 'Counted by %s, %s',
        'unsaved' => 'not saved yet: press Enter',
        'unsaved_leave' => 'You typed quantities on this page that are not saved yet. They are lost if you go on. Press Cancel, then press Enter in a '
            . 'quantity box to save them. (OK goes on without them.)',
        'save_page' => 'Save this page',
        'save_hint' => 'Press Enter after each quantity: it is saved at once. This button saves every quantity you typed on this page.',
        'held' => 'System held',
        'difference' => 'Difference',
        'plus' => '+%s',
        'minus' => '−%s',
        'no_difference' => 'none',
        'not_counted' => 'not counted',
        'what_happened' => 'What happened',
        'page' => 'Page %s of %s',
        'prev' => 'Lines before',
        'next_page' => 'Next lines',
        'final_title' => 'When you have counted',
        'final_text' => 'Look at the differences first. Nothing is booked until you make the sheet final there.',
        'final_link' => 'Before you make it final',
        'paper_title' => 'Count on paper: download and upload',
        'paper_text' => 'Download the sheet, fill in the column "counted" (leave it empty for an item you did not count), and upload the file. Check it '
            . 'first: nothing is saved until you choose "Fill in the sheet". An upload never makes the sheet final.',
        'download' => 'Download this sheet (CSV)',
        'upload_file' => 'The filled-in file',
        'upload_hint' => 'A CSV with the columns of the download, up to %s MB.',
        'upload_when' => 'When was it counted?',
        'upload_when_hint' => 'Leave it empty for "just now". A count made earlier keeps its own time, up to %s days back.',
        'upload_check' => 'Check the file only (nothing is saved)',
        'upload_apply' => 'Fill in the sheet',
        'upload_button' => 'Send the file',
        'report_title' => 'Checked: %s. Nothing was saved.',
        'report_counts' => '%s rows read: %s would be filled in, %s already hold that quantity, %s are empty, %s are not on this sheet, %s cannot be read.',
        'report_fill' => 'Would be filled in:',
        'report_fill_new' => 'Row %s: %s %s: %s',
        'report_fill_change' => 'Row %s: %s %s: from %s to %s',
        'report_more' => 'and %s more.',
        'report_not_on_sheet' => 'Not on this sheet (nothing is added):',
        'report_row' => 'Row %s: %s',
        'report_bad' => 'Cannot be read (skipped):',
        'report_bad_row' => 'Row %s: %s: %s ("%s")',
        'report_next' => 'If this is right, choose the file again, choose "Fill in the sheet", and send it.',
        'stop_title' => 'Stop this sheet',
        'stop_text' => 'A stopped sheet is kept, never deleted. It took no number and booked nothing.',
        'stop_button' => 'Stop this sheet',
        'cancel_title' => 'Cancel this whole sheet',
        'cancel_text' => 'A final sheet is never changed or deleted. Cancelling it makes a new record that puts every figure of the sheet back. To '
            . 'put back one line only, reject its difference on Differences.',
        'cancel_button' => 'Cancel this sheet',
        // Before you make it final
        'before_title' => 'Before you make it final: %s',
        'back_to_sheet' => 'Back to the sheet',
        'tile_counted' => 'Lines counted',
        'tile_counted_sub' => 'of %s on the sheet; the others are not booked',
        'tile_choice' => 'Need your answer',
        'tile_choice_sub' => 'stock moved after you counted them',
        'tile_over' => 'Above the limits',
        'tile_over_sub' => 'they will wait on Differences',
        'tile_stale' => 'Left out',
        'tile_stale_sub' => 'counted again later on another sheet',
        'nothing_counted' => 'Nothing is counted on this sheet yet.',
        'nothing_counted_text' => 'Enter what you counted first.',
        'too_many_times' => 'This sheet needs more count times than one record holds (%1$s, each up to %2$s minutes), so it cannot be made final as '
            . 'it is. Nothing was booked. The %3$s lines marked "Count again" were counted earliest: count them again close together, one after the '
            . 'other, and they share one time.',
        'retime_chip' => 'Count again: too many count times',
        'retime_all' => 'Count the marked lines again',
        'retime_all_text' => 'Empties the marked lines. Then enter what you count on the sheet.',
        'holds_now' => 'System holds now',
        'what_happens' => 'What happens',
        'left_out' => 'left out',
        'over_chip' => 'Above the limits',
        'over_units' => 'more than %s units',
        'over_percent' => 'more than %s%% of what the system held',
        'too_old_chip' => 'Counted too long ago',
        'too_old_text' => 'A count older than %s days is not booked. Count it again.',
        'moved_title' => 'Stock moved after you counted it:',
        'moved_row' => '%s: %s units, %s',
        'moved_more' => 'and %s more movements.',
        'does_stale' => 'This item was counted again on %s. Your older count is left out. Count it again if you want yours to stand.',
        'does_ships' => 'If you keep your count, the shelf figure becomes %1$s: your count less the %2$s that left after it.',
        'does_overwrite' => 'If you keep your count, the shelf figure becomes %1$s and the %3$s units that came in or were corrected after it are '
            . 'overwritten. Someone is then asked to look at it on Differences.',
        'as_counted_chip' => 'You chose to keep this count',
        'recount' => 'Count it again',
        'as_counted' => 'Keep my count',
        'make_final_title' => 'Make the sheet final',
        'make_final_text' => 'The counted lines are booked at once and get a number. Lines without a quantity are not booked. After that the sheet '
            . 'cannot be changed: a wrong line is put back by rejecting its difference, or the whole sheet is cancelled.',
        'make_final_button' => 'Make the count final',
        'make_final_blocked' => 'Answer the marked lines first: count each again, or keep your count.',
        // Differences
        'rules_link' => 'Approval Rules',
        'second_none' => 'Anyone who counts stock may answer, also for their own count. Whether a second person is needed is set on',
        'second_over' => 'A difference of more than %s units needs a second person: not the one who counted it. The rule is on',
        'second_all' => 'Every difference needs a second person: not the one who counted it. The rule is on',
        'no_differences' => 'No count difference is waiting.',
        'no_differences_text' => 'A difference above the limits shows here when its sheet is made final.',
        'differences_board' => 'Differences by kind',
        'differences_note' => '%s waiting, newest first.',
        'differences_more' => 'The newest %s of each kind are shown: answer them and the older ones move up.',
        'differences_foot' => 'Accept keeps the count. Reject makes a new record that puts that one count back; the sheet and its other lines stay.',
        'counted_by' => 'Counted by',
        'why' => 'Why it waits',
        'why_after' => 'The count overwrote %s units that were booked after it (%s movements).',
        'why_near' => '%s parcels left within ten minutes of the count.',
        'answer' => 'Your answer',
        'answer_note' => 'Why (3 words or more help the next person)',
        'accept' => 'Accept the count',
        'reject' => 'Reject: put it back',
        'no_sheet' => 'not counted on a sheet',
        'counted_again' => 'Counted again since: accept it. The newer count already set the shelf figure, so this one cannot be put back.',
        'why_ship_near' => 'Order %s: a parcel left %s, close to the item\'s count time %s. %s',
        'why_unship_near' => 'Order %s: a parcel came back %s, close to the item\'s count time %s. %s',
        'near_taken_off' => 'It was taken off the shelf figure.',
        'near_not_taken_off' => 'It was not taken off: the count had already left it out.',
        'near_added' => 'It was added to the shelf figure.',
        'near_not_added' => 'It was not added: the count already had it.',
    ];

    /** After a click on the counting pages: what happened and what comes next. */
    public const COUNT_NOTICE = [
        'opened' => 'The sheet is open. Scan or find each item and enter what you count.',
        'lines' => 'Saved: %s lines changed.',
        'lines_same' => 'Nothing changed: every quantity was already saved.',
        'added' => 'Added to the sheet. Enter what you counted.',
        'uploaded' => 'Done: %s lines were filled in from the file. Nothing is booked yet: check the differences, then make it final.',
        'posted' => 'Done: %s is final and the shelf figures are booked.',
        'posted_check' => 'Done: %s is final and the shelf figures are booked. Some differences are above the limits and wait on Differences.',
        'stopped' => 'Stopped. The sheet is kept with its reason; nothing was booked.',
        'cancelled' => 'Done: %s cancels the sheet, and every figure is put back.',
        'cancel_waits' => 'The cancellation waits for a reviewer\'s OK: it puts back more than the limit. Nothing is put back until they say OK.',
        'accepted' => 'Accepted: the count stays.',
        'rejected' => 'Rejected: %s puts that count back. Count the item again on a new sheet.',
        'rejected_already' => 'Rejected. That count had been put back already, so nothing more was booked.',
        'recount' => 'The line is empty again. Go back to the sheet and enter the new count.',
        'as_counted' => 'Kept: this line will be booked as you counted it.',
        'recount_times' => 'The %s marked lines are empty again. Go back to the sheet and enter their new counts, one after the other.',
    ];

    /** The counting pages' refusals, by the service's code ("Nothing was saved." where nothing was). */
    public const COUNT_ERROR = [
        'nothing_counted' => 'No line of this sheet has a quantity yet. Nothing was booked. Enter what you counted first.',
        'moved_since' => 'Stock of line %s moved after it was counted. Nothing was booked. For each such line choose: count it again, or keep your count.',
        'moved_meanwhile' => 'Stock of line %s moved in the very moment you made the sheet final. Nothing was booked. Look at the differences again.',
        'count_too_old' => 'Line %s was counted more than %s days ago, which is too old to book. Nothing was booked. Count it again.',
        'bad_counted_at' => 'The time it was counted must be a date and time that is not after now and not more than %s days ago. Nothing was saved.',
        'too_many_lines' => 'A count sheet holds at most %s items. Nothing was saved. Open another sheet for the rest.',
        'bad_next' => 'How many is a whole number from 1 to %s. Nothing was opened.',
        'counted_again' => 'This item was counted again after this count, and the newer count already set the shelf figure. Nothing was put back. '
            . 'Accept this difference with a note instead.',
        'line_counted_again' => 'The item of line %s was counted again after this sheet, and the newer count already set its shelf figure. Nothing was '
            . 'changed. Answer the sheet\'s differences one by one instead.',
        'cancel_waiting' => 'A cancellation of this whole sheet waits for a reviewer\'s OK. Nothing was changed. Answer this difference once the reviewer '
            . 'has decided.',
        'accept_only' => 'This is about the time of a count, not a line of a count sheet, so there is nothing to put back. Nothing was changed. Accept '
            . 'it with a note; if the shelf figure is wrong, count the item again.',
        'bad_count_time' => 'The moment line %s was filled in is after now, or before the line was on the sheet. Nothing was saved, and what you '
            . 'typed is still in the boxes. Press Enter again: it is saved with the time now.',
        'too_many_times' => 'This sheet needs more count times than one record holds (more than %s). Nothing was booked. The lines to count again are '
            . 'marked below: count them again close together.',
        'bad_quantity' => 'The quantity of line %s must be a whole number of units, 0 or more. Nothing was saved, and what you typed is still '
            . 'in the boxes. Correct it and press Enter again.',
        'barcode_in_quantity' => 'A barcode went into the quantity box of line %s. Nothing was saved, and what you typed is still in the boxes. '
            . 'Clear that box, then scan into "Barcode or CW number" at the top.',
        'unknown_line' => 'This sheet has no line %s. Nothing was saved. Reload the page.',
        'line_put_back' => 'Line %s of this sheet was already put back on its own, so the sheet cannot be cancelled as a whole. Nothing was changed. '
            . 'Reject its other differences one by one instead.',
        'not_creator' => 'Only the person who opened this sheet enters its quantities and makes it final. Nothing was saved.',
        'not_draft' => 'This sheet is not open any more, so it cannot be changed. Nothing was saved.',
        'version_conflict' => 'The sheet changed after this page was drawn (a quantity was entered, perhaps in another tab). Nothing was booked. '
            . 'Look at the differences again.',
        'sheet_changed' => 'The sheet changed after this page was drawn. Nothing was booked. Look at it again.',
        'nothing_ticked' => 'Tick the items to count first. Nothing was opened.',
        'bad_mode' => 'Choose one of the buttons to open a sheet. Nothing was opened.',
        'warehouse_required' => 'Choose the warehouse first. Nothing was opened.',
        'unknown_sku' => 'One of these items does not exist any more. Nothing was saved. Reload the page.',
        'merged_item' => 'One of these items was joined into another one. Nothing was saved. Count that other item.',
        'no_file' => 'Choose the file first. Nothing was saved.',
        'bad_file' => 'This file cannot be read as a count sheet. Nothing was saved. It needs the columns of the downloaded sheet: the CW number and '
            . '"counted".',
        'upload_failed' => 'The file did not arrive. Nothing was saved. Try again.',
        'too_many_rows' => 'The file has too many rows for one sheet. Nothing was saved.',
        'note_required' => 'Say why, in a few words (3 characters or more). Nothing was changed.',
        'not_open' => 'This difference is not waiting any more: someone answered it already. Nothing was changed.',
        'own_count' => 'You counted this line, or opened or finished its sheet. A second person must answer this one.',
        'role_not_allowed' => 'Only people who count stock can do this. Nothing was changed.',
        'admin_cannot_post' => 'An Admin never counts stock or answers count differences. Nothing was changed.',
        'not_from_sheet' => 'This count was not made on a count sheet, so it cannot be put back here. Nothing was changed. You can accept it with a note.',
        'nothing_to_put_back' => 'There is nothing to put back: the count changed nothing, it was put back already, or its sheet was cancelled. '
            . 'Nothing was changed.',
        'unknown_review' => 'We cannot find that difference. Nothing was changed.',
        'bad_answer' => 'Choose Accept or Reject. Nothing was changed.',
        'not_reversible' => 'This record cannot be cancelled. Nothing was changed.',
    ];

    /**
     * Each kind of stock record (StockOps::KINDS): its names, its list and its create form. Controllers hand the kind's array to the
     * views (no template types a word).
     */
    public const STOCK_KIND = [
        'in' => [
            'tab' => 'Stock In', 'one' => 'Stock in', 'many' => 'Stock in records', 'lower' => 'stock in',
            'start' => 'Start a stock in', 'start_button' => 'Start the stock in',
            'start_text' => 'Stock that comes in without a supplier delivery: opening stock, stock found, a trade customer\'s return. A delivery from a supplier is booked in Purchasing › Goods In.',
            'how' => ['Choose the warehouse and why the stock comes in.', 'Scan or find each product and say how many units. Add the cost of a unit when you know it.',
                'Make it final: the units are added to the warehouse at once.'],
            'none' => 'No stock in records yet.', 'none_text' => 'Start one above when stock comes in without a supplier delivery.',
            'goods_in' => 'A delivery from a supplier?', 'goods_in_link' => 'Book it in Goods In',
            'final_button' => 'Make it final: add the stock', 'final_does' => 'The units are added to the warehouse now. A mistake is put right by cancelling the record.',
        ],
        'out' => [
            'tab' => 'Stock Out', 'one' => 'Stock out', 'many' => 'Stock out records', 'lower' => 'stock out',
            'start' => 'Start a stock out', 'start_button' => 'Start the stock out',
            'start_text' => 'Stock that leaves without a website sale: samples, staff use, a trade sale, goods sent for repair or return.',
            'how' => ['Choose the warehouse and why the stock goes out. A sample or staff use names the person it was given to.',
                'Scan or find each product and say how many units.', 'Make it final: the units are taken off the warehouse at once, at the average cost.'],
            'none' => 'No stock out records yet.', 'none_text' => 'Start one above when stock leaves without a website sale.',
            'final_button' => 'Make it final: take the stock off', 'final_does' => 'The units are taken off the warehouse now. A mistake is put right by cancelling the record.',
        ],
        'adjust' => [
            'tab' => 'Adjustments', 'one' => 'Adjustment', 'many' => 'Adjustments', 'lower' => 'adjustment',
            'start' => 'Start an adjustment', 'start_button' => 'Start the adjustment',
            'start_text' => 'Put the stock figure right: stock found, lost or damaged, a correction, or a write-off. Each line says why.',
            'how' => ['Choose the warehouse.', 'Scan or find each product, type + for units found or - for units gone, and choose why.',
                'Make it final. Units gone for good are booked as a write-off; a big adjustment may wait for a reviewer\'s OK first.'],
            'none' => 'No adjustments yet.', 'none_text' => 'Start one above when the stock figure is wrong.',
            'final_button' => 'Make it final: change the stock', 'final_does' => 'The stock figure changes now, or after a reviewer\'s OK when a rule asks for one.',
        ],
        'transfer' => [
            'tab' => 'Transfers', 'one' => 'Transfer', 'many' => 'Transfers', 'lower' => 'transfer',
            'start' => 'Start a transfer', 'start_button' => 'Start the transfer',
            'start_text' => 'Move stock between two of our warehouses, or between two places of one warehouse (for example a shelf and the overflow room).',
            'how' => ['Choose where the stock leaves and where it goes. Places are optional, unless it stays in one warehouse.',
                'Scan or find each product and say how many units.',
                'Make it final: the units move at once. A move between two places of one warehouse changes no figure; the record says where they went.'],
            'none' => 'No transfers yet.', 'none_text' => 'Start one above when stock moves.',
            'final_button' => 'Make it final: move the stock', 'final_does' => 'The units move now. Whose stock it is never changes.',
            'pdf' => 'Download the transfer note (PDF)',
        ],
        'release' => [
            'tab' => 'Releases', 'one' => 'Release', 'many' => 'Releases', 'lower' => 'release',
            'start' => 'Start a release', 'start_button' => 'Start the release',
            'start_text' => 'Another account\'s stock kept in our building becomes ours when that account invoices it to us. The release moves it into our warehouse and adds the amount to what we owe that account.',
            'how' => ['Choose the other account\'s room and our warehouse it goes to. Type the number of their invoice.',
                'Scan or find each product, say how many units and the agreed price of one unit. The last supplier price, else the average cost, is filled in.',
                'Make it final: the stock moves into our warehouse and the amount is added to the balance owed.'],
            'none' => 'No releases yet.', 'none_text' => 'Start one above when the other account invoices stock to us.',
            'final_button' => 'Make it final: release the stock', 'final_does' => 'The stock moves into our warehouse at the agreed prices and the total is added to what we owe the account.',
            'pdf' => 'Download the release invoice (PDF)',
        ],
    ];

    /** The stock records' lists, pages and forms (StockOpsController). */
    public const STOCK_OPS = [
        'search' => 'Number, reference, note or name',
        'state' => 'Show',
        'any_state' => 'Every record not stopped',
        'stopped' => 'Show stopped drafts too',
        'warehouse' => 'Warehouse',
        'any_warehouse' => 'Every warehouse',
        'clear' => 'Show every record',
        'download' => 'Download for Excel (CSV)',
        'how' => 'How it works',
        'board' => 'Records by status',
        'total_one' => '1 record, newest first.',
        'total_many' => '%s records, newest first.',
        'limit' => 'Only the newest %s are shown: use Search or Filter to find older ones.',
        'group_one' => '1 record',
        'group_many' => '%s records',
        'group_total' => 'Total for %s',
        'add_row' => '+ Start a new one',
        'group_reversal' => 'Cancellation records',
        'none_filter' => 'No record matches these filters.',
        'none_filter_text' => 'Your filter hides every record. Clear it to see them all.',
        // columns
        'record' => 'Record',
        'where' => 'Where',
        'why' => 'Why',
        'status' => 'Status',
        'products' => 'Products',
        'units' => 'Units',
        'value' => 'Value',
        'made_by' => 'Made by',
        'next' => 'Next step',
        'no_number' => 'No number yet (draft)',
        'started' => 'Started %s',
        'dated' => 'Dated %s',
        'to' => '%s → %s',
        'in_place' => '%s, %s',
        'given' => 'Given to %s',
        'cancels' => 'Cancels %s',
        'reasons_many' => 'Several reasons',
        // next steps
        'next_draft' => 'Not final yet: add the products, then make it final.',
        'next_waiting' => 'Waits for a reviewer\'s OK. Nothing is booked until then.',
        'next_check' => 'Final. A reviewer checks it within the days the rule gives.',
        'next_done' => 'Final. Nothing to do.',
        'next_reversed' => 'Cancelled by %s.',
        'next_reversal' => 'Puts back everything %s did.',
        'next_stopped' => 'Stopped before it was final.',
        // the create form and the header
        'from' => 'From',
        'warehouse_to' => 'Goes to',
        'place' => 'Place (optional)',
        'place_to' => 'Place it goes to',
        'no_place' => 'No place',
        'reason' => 'Why',
        'choose' => 'Choose',
        'given_to' => 'Given to (name)',
        'given_hint' => 'Needed for: %s.',
        'ref' => 'Reference (optional)',
        'ref_release' => 'Their invoice number',
        'date' => 'Date',
        'note' => 'Note (optional)',
        'other_room' => '%s (another account\'s stock: %s)',
        'no_other' => 'No warehouse holds another account\'s stock yet. Add the room on the Warehouses page as "another account\'s stock" first.',
        'no_other_link' => 'Open Warehouses',
        // the record page
        'facts' => 'About this record',
        'kind' => 'Kind',
        'made' => 'Made',
        'asked' => 'Sent for an OK',
        'final' => 'Final',
        'stopped_at' => 'Stopped',
        'by' => '%s by %s',
        'cancelled_by' => 'Cancelled by',
        'cancel_waiting' => 'a cancellation waiting for an OK',
        'account' => 'Owed to',
        'details' => 'Change the details',
        'save_details' => 'Save the details',
        'add' => 'Add a product',
        'add_q' => 'Scan a barcode, or type a CW number or a name',
        'add_qty' => 'Units',
        'add_qty_adjust' => 'Units (+ found, - gone)',
        'add_cost' => 'Cost of one unit in £ (optional)',
        'add_price' => 'Agreed price of one unit in £',
        'add_price_hint' => 'Leave it empty to take the last supplier price, else the average cost so far.',
        'add_button' => 'Add',
        'choices' => 'Several products match "%s". Choose one:',
        'choose_this' => 'Add this one',
        'lines' => 'Products',
        'no_lines' => 'No products yet. Add the first one above.',
        'line_product' => 'Product',
        'line_units' => 'Units',
        'line_cost' => 'Cost of a unit',
        'line_price' => 'Price of a unit',
        'line_amount' => 'Amount',
        'line_reason' => 'Why',
        'line_stock' => 'Stock here now',
        'line_stock_text' => '%s in the building, %s free',
        'line_average' => 'Average cost so far: %s',
        'line_remove' => 'Remove',
        'save_lines' => 'Save the lines',
        'total' => 'Total',
        'total_units' => '%s units',
        'final_title' => 'Make it final',
        'final_waits' => 'A rule asks for a reviewer\'s OK first for this record: making it final sends it to a reviewer, and nothing is booked until they say OK.',
        'stop_title' => 'Stop this draft',
        'stop_text' => 'A stopped draft is kept, never deleted. It took no number and booked nothing.',
        'stop_why' => 'Why it is stopped',
        'stop_button' => 'Stop this draft',
        'cancel_title' => 'Cancel this record',
        'cancel_text' => 'A final record is never changed or deleted. Cancelling it makes a new record that puts everything back. You can then make a new, correct one.',
        'cancel_why' => 'Why',
        'cancel_note' => 'Note (optional)',
        'cancel_button' => 'Cancel this record',
        'withdraw_title' => 'Take it back to change it',
        'withdraw_text' => 'It is still waiting for a reviewer\'s OK. Taking it back makes it a draft again.',
        'withdraw_button' => 'Take it back',
        'files' => 'Files',
        'no_files' => 'No files yet.',
        'file' => 'File',
        'file_role' => 'What it is',
        'file_button' => 'Add the file',
        'file_hint' => 'A PDF or a photo, at most %s MB. Files are kept, never deleted.',
        'not_creator' => 'Only the person who started this draft changes it.',
        'value_note' => 'Values at the average cost so far are an estimate: stock values for the accounts come later.',
        'booked_none' => 'A move between two places of one warehouse changes no figure.',
        'back' => 'Back to %s',
    ];

    /** A stock record's attachment roles (StockOps::FILE_ROLES). */
    public const STOCK_FILE = [
        'delivery_note' => 'Delivery note',
        'supplier_invoice' => 'Invoice',
        'photo' => 'Photo',
        'evidence' => 'Other evidence',
        'generated_pdf' => 'Printed copy',
    ];

    /** Notices after a stock record changed. */
    public const STOCK_NOTICE = [
        'created' => 'Started. Add the products below, then make it final.',
        'header' => 'Saved: the details are changed.',
        'lines' => 'Saved: the lines are changed.',
        'added' => 'Added: %s, %s units (line %s).',
        'incremented' => 'Added %s units to line %s (%s).',
        'posted' => 'Done: %s is final and the stock is booked.',
        'posted_none' => 'Done: %s is final. It moved no figure: the record says where the stock went.',
        'submitted' => 'Sent for a reviewer\'s OK. Nothing is booked until they say OK.',
        'cancelled' => 'Stopped. The draft is kept with its reason; nothing was booked.',
        'reversed' => 'Done: %s cancels it, and everything is put back.',
        'reversal_submitted' => 'The cancellation waits for a reviewer\'s OK: it puts back more than the limit.',
        'withdrawn' => 'Taken back: it is a draft again. Change it, then make it final.',
        'file' => 'Added: the file is kept with the record.',
        'file_again' => 'This file was already kept with the record: nothing new was added.',
    ];

    /** A stock record's refusals, by the service's code ("Nothing was saved." where nothing was). */
    public const STOCK_ERROR = [
        'warehouse_required' => 'Choose the warehouse. Nothing was saved.',
        'warehouse_inactive' => 'That warehouse is switched off. Nothing was saved. Choose another one.',
        'unknown_warehouse' => 'We cannot find that warehouse. Nothing was saved.',
        'location_mismatch' => 'That place is not inside the warehouse you chose. Nothing was saved.',
        'location_inactive' => 'That place is switched off. Nothing was saved. Choose another one, or none.',
        'not_own_stock' => 'That room holds another account\'s stock: it is not ours to give. Nothing was saved. It becomes ours with a release.',
        'release_from_own' => 'A release comes from another account\'s room. Nothing was saved. Choose that room.',
        'release_to_other' => 'A release goes into one of our own warehouses. Nothing was saved.',
        'owner_differs' => 'These two warehouses hold different owners\' stock, and a transfer never changes whose stock it is. Nothing was saved. Stock from another account comes in with a release.',
        'to_warehouse_required' => 'Choose where the stock goes. Nothing was booked.',
        'same_place' => 'A move inside one warehouse goes from one place to another: choose both places, and two different ones. Nothing was booked.',
        'reason_required' => 'Say why: choose a reason for every line. Nothing was booked.',
        'reason_direction' => 'Line %s: that reason takes stock the other way. Nothing was booked. Choose another reason or change + and -.',
        'reason_not_applicable' => 'That reason is not offered here. Nothing was saved.',
        'reason_inactive' => 'That reason is switched off. Nothing was saved. Choose another one.',
        'given_to_required' => 'Say who it was given to: this reason needs a name. Nothing was booked.',
        'qty_positive' => 'The units are a whole number above 0. Nothing was saved.',
        'qty_nonzero' => 'The units are a whole number: + for units found, - for units gone, never 0. Nothing was saved.',
        'price_required' => 'Line %s has no price: type the agreed price of one unit. Nothing was booked.',
        'cost_not_allowed' => 'Stock that goes out is valued at the average cost: it takes no cost of its own. Nothing was saved.',
        'below_zero' => 'Line %s: %s would go below zero (%s free, %s going out). Nothing was booked. Count it first, or ask a Reviewer about the reason\'s rule.',
        'not_enough_held' => 'Line %s: the room holds only %s of %s. Nothing was booked.',
        'no_lines' => 'There are no products on it yet. Nothing was booked.',
        'bad_line' => 'Every line names a product and its units. Nothing was saved.',
        'too_many_lines' => 'A record has at most %s lines: make it final and start another one. Nothing was saved.',
        'unknown_sku' => 'We cannot find that product. Nothing was saved.',
        'merged_item' => 'That product was joined into another one: use that one. Nothing was saved.',
        'not_found' => 'Nothing matches "%s". Try the barcode, the CW number or other words.',
        'bad_cost' => 'A price is in pounds with at most 6 decimals, like 1.25. Nothing was saved.',
        'bad_field' => 'Something typed is not right: check the fields. Nothing was saved.',
        'not_draft' => 'This record is not a draft any more, so it cannot be changed. Nothing was saved.',
        'version_conflict' => 'Someone changed this record while you had the page open. Nothing was saved. Look at it again, then try again.',
        'not_creator' => 'Only the person who started this draft changes it. Nothing was saved.',
        'not_reversible' => 'This record cannot be cancelled: it is not final, it is cancelled already, or it is itself a cancellation.',
        'reversal_pending' => 'A cancellation of this record already waits for a reviewer\'s OK.',
        'not_withdrawable' => 'Only a record waiting for a reviewer\'s OK can be taken back.',
        'not_requester' => 'Only the person who sent it for the OK can take it back.',
        'note_required' => 'This reason needs a note. Nothing was saved.',
        'bad_cancel_reason' => 'Say why in 3 to 500 characters. Nothing was saved.',
        'file_store_unconfigured' => 'Files cannot be kept on this server yet. Nothing was saved. Ask ' . self::ASK . '.',
        'document_cancelled' => 'A stopped draft takes no files.',
        'admin_cannot_post' => 'Admin cannot keep stock records.',
        'role_not_allowed' => 'Your jobs cannot keep this kind of record.',
    ];

    /** The balance owed to another account (/ui/stock/accounts; OtherAccounts). */
    public const ACCOUNTS = [
        'title' => 'Balance owed',
        'room' => '%s, %s',
        'switched_off' => 'Switched off',
        'ours_now' => 'Holds our own stock now',
        'holds' => 'Holds now',
        'holds_units' => '%s units of %s products',
        'holds_value' => 'About %s at the suggested prices',
        'holds_unpriced' => '%s products have no price yet.',
        'month' => 'Released this month',
        'month_units' => '%s units',
        'balance' => 'Balance owed',
        'credit' => 'They owe us %s',
        'last_paid' => 'Last payment: %s on %s (%s).',
        'release' => 'Release stock',
        'pay' => 'Record a payment to the account',
        'pay_text' => 'A payment lowers the balance owed. It is kept, never deleted: a mistake is reversed with a reason.',
        'amount' => 'Amount in £',
        'paid_on' => 'Paid on',
        'reference' => 'Reference (bank or remittance)',
        'note' => 'Note (optional)',
        'pay_button' => 'Record the payment',
        'history' => 'Releases and payments',
        'no_history' => 'Nothing released or paid yet.',
        'when' => 'When',
        'what' => 'What',
        'amount_col' => 'Amount',
        'ref_col' => 'Reference',
        'who' => 'Who',
        'entry_release' => 'Release %s',
        'entry_release_reversal' => 'Release cancelled: %s',
        'entry_payment' => 'Payment',
        'entry_payment_reversal' => 'Payment reversed',
        'reversed_mark' => 'reversed',
        'reverse' => 'Reverse this payment',
        'reverse_why' => 'Why',
        'reverse_button' => 'Reverse the payment',
        'none' => 'No warehouse holds another account\'s stock.',
        'none_text' => 'When a room in the building keeps another account\'s stock, add it on the Warehouses page as "another account\'s stock". It then shows here with what it holds and what we owe.',
        'none_link' => 'Open Warehouses',
        'paid' => 'Recorded: the payment of %s. The balance owed is now %s.',
        'reversed' => 'Reversed: the balance owed is now %s.',
        'tile_holds' => 'Holds now',
        'tile_month' => 'Released this month',
        'tile_balance' => 'Balance owed',
        'units_sub' => '%s products',
        'month_sub' => '%s units',
        'balance_sub' => 'releases less payments',
    ];

    /** The balance owed's refusals, by the service's code. */
    public const ACCOUNT_ERROR = [
        'bad_amount' => 'The amount is in pounds above 0, like 1250.00. Nothing was saved.',
        'bad_date' => 'The date it was paid is a day up to today. Nothing was saved.',
        'bad_reference' => 'Type the payment\'s reference (1 to 100 characters). Nothing was saved.',
        'bad_reason' => 'Say why in 3 to 500 characters. Nothing was saved.',
        'more_than_owed' => 'The payment is more than the %s owed. Nothing was saved. Check the amount.',
        'nothing_owed' => 'Nothing is owed to this account. Nothing was saved.',
        'unknown_account' => 'That warehouse holds no other account\'s stock. Nothing was saved.',
        'unknown_entry' => 'We cannot find that payment. Nothing was changed.',
        'not_a_payment' => 'Only a payment is reversed here: a release is cancelled on its own page. Nothing was changed.',
        'already_reversed' => 'This payment is reversed already. Nothing was changed.',
        'role_not_allowed' => 'Your jobs cannot record payments. Nothing was saved.',
        'admin_cannot_post' => 'Admin cannot record payments. Nothing was saved.',
    ];

    /** The transfer note and the release invoice (StockOpPdf): plain words, printed. */
    public const STOCK_PDF = [
        'transfer_title' => 'Transfer note %s',
        'release_title' => 'Release invoice %s',
        'draft' => '(draft, not final)',
        'from' => 'From',
        'to' => 'To',
        'date' => 'Date',
        'made_by' => 'Made by',
        'final_by' => 'Final by',
        'note' => 'Note',
        'seller' => 'Sold by',
        'buyer' => 'Bought by',
        'their_ref' => 'Their invoice number',
        'line' => '#',
        'code' => 'CW number',
        'product' => 'Product',
        'units' => 'Units',
        'price' => 'Price of a unit',
        'amount' => 'Amount',
        'total' => 'Total',
        'picked' => 'Picked by: ____________________',
        'received' => 'Received by: ____________________',
        'pounds' => 'Prices in pounds. The stock moved from %s to %s when this release was made final, and the total was added to what we owe %s.',
        'moves_none' => 'A move between two places of one warehouse: no stock figure changes.',
        'cancelled' => 'Cancelled by %s.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // The spot check (KeySample)

    /** A member of a spot check (SamplesController::STATE_LABELS keys). */
    public const SAMPLE_STATE = [
        'open' => 'Not checked yet',
        'confirmed' => 'Confirmed',
        'rejected' => 'Marked wrong',
        'waiting_second' => 'Waiting for a second OK',
        'needed_second' => 'Confirmed with a second OK',
        'superseded' => 'Replaced by a newer suggestion',
        'decided_otherwise' => 'Decided another way',
        'confirmed_by_other' => 'Checked by someone else',
        'confirmed_not_by_lead' => 'Confirmed, but not as a matching lead',
        'changed_since' => 'Changed since',
    ];

    /** A whole spot check. */
    public const SAMPLE_RESULT = [
        'waiting' => 'In progress',
        'passed' => 'All right: ready to confirm the rest',
        'failed' => 'Failed: check the rest one by one',
        'unusable' => 'All right, but cannot be used (ask ' . self::ASK . ')',
    ];

    /** The answers on a spot-check member (compare.md §2.1: "Not a match" has a second step before anything is saved). */
    public const SPOT = [
        'yes' => 'Yes, same product',
        // The page reloads with "Done" and the button to the next one (the redirect after a decision is unchanged).
        'yes_sub' => 'Matches it. One more yes for the spot check.',
        'no' => 'Not a match',
        'no_sub' => 'Stops the bulk link for good',
        'no_confirm_title' => 'This stops the bulk link for good.',
        'no_confirm_text' => 'One wrong answer fails the whole spot check. Then the other strong matches must be checked one at a time.',
        'no_confirm_what' => 'Then say what it is instead: wrong product, a new product, not a real product, or another warehouse product.',
        'no_confirm_button' => 'Yes, it is not a match: stop the bulk link',
        'unsure' => 'Not sure',
        'unsure_sub' => 'Leaves it for later. Nothing is saved.',
        // The answers written out (design B: "What each answer does")
        'does_yes' => 'The website product is matched to %s. When all %s are yes, the rest can be confirmed together.',
        'does_no' => 'Nothing is matched yet: next you say what it is instead. The spot check then fails for good: the other strong matches are not confirmed together, and are checked one at a time instead.',
        'does_unsure' => 'Nothing is saved. It stays in the spot check for later. All %s need a yes before the rest can be confirmed together.',
        // The spot-check box on a member's page
        'box' => 'Spot check %s: number %s of %s',
        'mine' => 'Say yes only if it is right. If you mark it wrong or change it, "confirm the rest together" is cancelled for this spot check.',
        'other' => '%s is checking this one in a spot check. Please do not decide it.',
        'other_text' => 'A decision by anyone else fails the spot check, so the answer forms are not shown here.',
        // A member answered "yes" already (its suggestion is closed): any change of its match fails the spot check as well.
        'other_done' => '%s checked this one in a spot check. Please do not change its match.',
        'other_done_text' => 'Any change to this match fails the spot check, so the forms to change it are not shown here.',
        'mine_done' => 'You said yes to this one. If you change its match now, "confirm the rest together" is cancelled for this spot check.',
        'change' => 'Change this match: stops the bulk link for good',
        'change_button' => 'Change it: stop the bulk link',
        // "Not a match" when another warehouse product is picked: matching it fails the spot check too (it is not the suggestion).
        'instead_link' => 'It is %s instead: match to it',
        'picked_other' => 'You are looking at %s. "Yes, same product" still matches the suggested product, %s. If it is %s instead, use "Not a match".',
        'to_answers' => 'Go to the answers',
        'next' => 'Check the next one (%s of %s)',
        'all_answered' => 'Every match of this spot check has an answer.',
        'back' => 'Back to the spot check',
        'progress' => '%s of %s checked',
        'legend_yes' => 'Yes: %s',
        'legend_wrong' => 'Wrong or changed: %s',
        'legend_now' => 'This one: number %s',
        'legend_todo' => 'Still to do: %s',
        'block_yes' => 'Number %s: yes',
        'block_wrong' => 'Number %s: wrong or changed',
        'block_now' => 'Number %s: this one',
        'block_todo' => 'Number %s: not checked yet',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 1.6 Buying

    /** A purchase order's state (Document status + PurchaseOrders::STATES). */
    public const PO_STATE = [
        'draft' => 'Draft',
        'awaiting_approval' => 'Waiting for a reviewer\'s OK',
        'approved' => 'Confirmed, not sent',
        'sent' => 'Sent',
        'part_received' => 'Partly delivered',
        'received' => 'Delivered',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /** Words on the purchase-order pages. */
    public const PO = [
        'confirm' => 'Confirm order',
        // Over the approval limit the order is not confirmed at once: it goes to a reviewer first (correction f, 7 Oct).
        'confirm_over_limit' => 'Ask a reviewer to OK this order',
        'confirm_over_limit_note' => 'This order is over the approval limit. A reviewer must OK it before it gets its PO number.',
        'sent' => 'I have sent it',
        'send_how' => 'How did you send it?',
        'send_again' => 'Record another sending',
        'no_email' => 'CW does not send e-mails.',
        'amend' => 'Correct',
        'close' => 'Close: stop waiting for the rest',
        'cancellation' => 'cancellation record',
        'draft_title' => 'Draft (no PO number yet)',
    ];

    /** How an order was sent (PurchaseOrders::SEND_VIA keys). */
    public const SEND_VIA = [
        'email' => 'By e-mail',
        'portal' => 'On the supplier\'s website',
        'phone' => 'By phone',
        'in_person' => 'In person',
        'imported' => 'Brought in from ERPNext',
        'other' => 'Another way',
    ];

    /**
     * Why an order was cancelled or corrected: only a FALLBACK. The order screens show each reason's own name from the Reasons page
     * (PurchaseOrdersController::reasonLabel; review finding I6), which the owner renames there; these words are used only for a
     * reason without a name to show.
     */
    public const REASON = [
        'not_needed' => 'Not needed any more',
        'supplier_cannot_supply' => 'Supplier cannot supply',
        'entered_in_error' => 'Made by mistake',
        'duplicate' => 'Ordered twice',
        'po_amended' => 'Replaced by a corrected order',
        'other' => 'Other (write why below)',
    ];

    /** Suppliers::STATUSES: can we order from it? */
    public const SUPPLIER_STATUS = [
        'draft' => 'Draft',
        'pending_approval' => 'Waiting for a reviewer\'s OK',
        'active' => 'Can order',
        'inactive' => 'Stopped',
    ];

    /** Why something waits for a reviewer (review_task.reason). */
    public const CHECK_REASON = [
        'new_supplier' => 'New supplier',
        'reactivation' => 'Supplier to be used again',
        'import_route' => 'Duty-stamp arrangement changed',
        'over_limit' => 'Over the approval limit',
        'over_value' => 'Over the approval limit',
        'positive_without_supplier_doc' => 'Stock added without a supplier document',
        'over_size' => 'Bigger than the size that needs an OK first',
        'supplier_changed' => 'Supplier details changed',
        'all_documents' => 'Every order is checked',
        'company_changed' => 'Company details changed',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'over_difference' => 'Invoice does not match by more than the limit',
    ];

    /** review_task.kind. */
    public const CHECK_KIND = [
        'approval' => 'Needs your OK before anything happens',
        'review' => 'Already done: please check it',
    ];

    /** document.review_state. */
    public const REVIEW_STATE = [
        'not_required' => 'No check needed',
        'pending' => 'Reviewer check: waiting',
        'approved' => 'Reviewer check: OK',
        'rejected' => 'Reviewer check: not OK',
    ];

    /** review_task.state. */
    public const TASK_STATE = [
        'open' => 'Waiting',
        'approved' => 'OK',
        'rejected' => 'Not OK',
        'withdrawn' => 'Cancelled by the person who asked',
    ];

    /** Document::STATUSES. */
    public const DOC_STATUS = [
        'draft' => 'Draft',
        'awaiting_approval' => 'Waiting for OK',
        'posted' => 'Final',
        'reversed' => 'Cancelled',
        'cancelled' => 'Stopped before it was final',
    ];

    /** ReorderController::FLAG_TEXT keys. */
    public const REORDER_FLAG = [
        'urgent' => 'Will run out',
        'no_supplier' => 'No main supplier – set one',
        'supplier_draft' => 'Supplier not approved yet',
        'supplier_pending_approval' => 'Supplier waiting for a reviewer\'s OK',
        'supplier_inactive' => 'Supplier stopped',
        'no_price' => 'No price yet',
        'merged' => 'Replaced by another product',
        'do_not_reorder' => 'Never suggested',
        'no_history' => 'No sales data',
        'site_stock_unreliable' => 'Website stock may be wrong',
        'in_draft' => 'On a draft order already',
        'discontinued' => 'Not sold any more (product card)',
        // Correction d (7 Oct): until IM6/IM10 enforce the block, the product is still on sale: say so.
        'card_blocked' => 'Blocked: a person must confirm its product card. It is still on sale on the website: take it off by hand.',
        'card_warning' => 'Product card has a warning',
    ];

    /** Plain names of the settings (app_setting keys); SETTING_HELP says what each does (the raw description is the fallback). */
    public const SETTING = [
        // Stock counting (pack A2a, 0029; docs/decisions.md CT4, CT6, CT10).
        'counts.plan_days' => 'Days between two counts of an item',
        'counts.sheet_shows_stock' => 'Count sheets show what the system holds',
        'counts.review_over_units' => 'Check a count difference above this many units',
        'counts.review_over_percent' => 'Check a count difference above this percent',
        'counts.time_window_max_minutes' => 'Share a count time within this many minutes',
        'company.legal_name' => 'Company name (legal)',
        'company.trading_name' => 'Trading name',
        'company.address' => 'Registered address',
        'company.company_number' => 'Company number',
        'company.vat_number' => 'VAT number',
        'company.phone' => 'Phone',
        'company.email' => 'E-mail',
        'company.delivery_address' => 'Delivery address',
        'company.confirmed' => 'Company details confirmed',
        'costs.site_writeback' => 'Copy CW costs to the websites',
        'suppliers.approval_due_days' => 'Days a reviewer has to approve a supplier',
        'suppliers.change_review' => 'A reviewer checks changed suppliers',
        'po.terms' => 'Terms printed on orders',
        'po.default_vat_code' => 'VAT code for new order lines',
        'po.over_delivery_tolerance_pct' => 'Extra delivered we still accept (%)',
        'reorder.default_lead_days' => 'Delivery days (when nothing else says)',
        'reorder.default_review_days' => 'Order every N days (when the supplier does not say)',
        'reorder.default_safety_days' => 'Spare days of stock',
        'reorder.short_window_days' => 'Recent sales period (days)',
        'reorder.long_window_days' => 'Longer sales period (days)',
        'reorder.short_weight' => 'Weight of the recent sales',
        'reorder.min_valid_days_short' => 'Fewest usable days (recent sales)',
        'reorder.min_valid_days_long' => 'Fewest usable days (longer period)',
        'reorder.spike_cap_multiple' => 'Very high day: times a normal day',
        'reorder.spike_cap_floor' => 'Very high day: never lowered below',
        'reorder.promo_price_drop' => 'Promotion check: price drop',
        'reorder.promo_units_uplift' => 'Promotion check: sold more',
        'reorder.promo_min_units' => 'Promotion check: at least this many sold',
        'reorder.stale_history_days' => 'Sales data is old after (days)',
        // --- Pack G
        'reorder.own_sales_stores' => 'Stores whose sales come from CW\'s own record',
        'reorder.refresh_minutes' => 'Work out the reorder list every N minutes',
        'reorder.refresh_chunk_items' => 'Products worked out together in one step',
        'reorder.refresh_inline_max_items' => 'Most products worked out at once after a click',
        'reorder.demand_hour' => 'Hour (UK) of the nightly sales work-out',
        'reorder.fold_refold_days' => 'Days of own sales read again each night',
        'reorder.fold_days_per_run' => 'Most days of own sales read in one run',
        'reorder.low_stock_bulk_max' => 'Most products in one low-stock click',
        'reorder.draft_skip_no_price' => 'Draft orders leave out products without a price',
        // --- end of Pack G
        'receiving.unstamped_refusal_from' => 'Unstamped goods refused from',
        'receiving.duty_pence_per_ml' => 'Vaping duty (pence per ml)',
        'receiving.backdate_max_days' => 'Days back a delivery may be dated',
        'receiving.mode_after_out_of_stock' => 'Selling mode after Out-Of-Stock',
        'site_writer.receipt_mode_sites' => 'Websites where a delivery sets the selling mode',
        'approvals.supplier_activation' => 'New suppliers need a reviewer\'s OK',
        'approvals.match_multiple' => 'Matches where 1 sale is not 1 product need a second OK',
        'approvals.match_counted' => 'Joining counted products needs a second OK',
        'approvals.company_own_change' => 'Own change of the company details is checked',
        'approvals.staff_grant' => 'Giving Admin or Reviewer needs a reviewer\'s OK',
        'approvals.staff_reset' => 'Resetting an Admin\'s or a Reviewer\'s sign-in needs a reviewer\'s OK',
        'staff.setup_max_fails' => 'Wrong tries before a sign-in set-up closes',
        'staff.sign_in_address' => 'Sign-in address',
        'approvals.spot_check_size' => 'Matches in a spot check',
        'approvals.mapping_bulk_second_ok' => 'Matches confirmed from a ticked list need a second OK',
        'mapping.bulk_confirm_bands' => 'Match strengths that may be confirmed together',
        'mapping.bulk_max_rows' => 'Most products in one bulk action',
        'mapping.by_item_page_size' => 'Items on a page of By Item',
        'mapping.new_variants_days' => 'Days New Variants looks back',
        'mapping.arrival_stores' => 'Stores whose new variants the computer checks',
        'mapping.arrival_main_store' => 'The main store',
        'mapping.arrival_bulk_confirm' => 'Matches from the check on arrival may be confirmed from a ticked list',
        'mapping.arrival_max_per_run' => 'Most variants one run of the check looks at',
        'mapping.arrival_max_seconds' => 'Longest one run of the check may take, in seconds',
        'mapping.arrival_queue_alarm' => 'Most waiting variants the timer takes by itself',
        'mapping.arrival_state_max_days' => 'How old the website\'s state may be',
        // ---- Pack E
        'reports.low_stock_default' => 'Low stock: units left',
        'reports.slow_after_days' => 'Slow stock: days without a sale',
        'reports.dead_after_days' => 'Dead stock: days without a sale',
        'reports.sales_max_days' => 'Longest period of Sales by Store',
        'reports.refresh_on' => 'Keep the reports up to date',
        'reports.refresh_max_rows' => 'Stock changes read in one report run',
        'reports.refresh_max_days' => 'Days of sales worked out in one report run',
        // ---- end Pack E
        'staff.setup_hours' => 'Hours to set up a sign-in',
        'staff.min_reviewers' => 'Reviewers needed',
        // ---- Pack D (docs/decisions.md PD9) ----
        'sales.page_size' => 'Rows on a page of Sales',
        'sales.default_days' => 'Days Store orders looks back',
        'sales.late_hours' => 'Hours before a paid order is late',
        'sales.csv_max_rows' => 'Most rows in a Sales spreadsheet',
        'sales.scan_max_rows' => 'Most rows one Sales page reads',
        // ---- end of pack D ----
        // Pack B
        'items.pack_suggest_single_sold' => 'Suggest selling a pack as singles only when the single is sold on its own',
        'items.family_bulk_max_rows' => 'Most items one change of a family takes',
        'items.repack_carry_cost' => 'A repack carries the cost to what it makes',
        'approvals.item_by_hand_check' => 'Items made by hand are checked by a second person',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices.check_price' => 'Check invoice prices against the order', // PC19 (0040)
        // --- Pack J (0041): goods on a final supplier invoice that have not arrived yet.
        'arrivals.late_hours' => 'Late after (hours)',
        'arrivals.sell_before_default' => 'Sell before it arrives (suppliers without their own choice)',
        'arrivals.default_mode' => 'How invoices come in (suppliers without their own choice)',
        'invoices.price_tolerance_pct' => 'Invoice price may differ by (%)',
        'invoices.price_tolerance_gbp' => 'Invoice price may differ by (£ a line)',
        'invoices.qty_tolerance_pct' => 'Invoice may bill more units than received (%)',
        'invoices.total_tolerance_per_line' => 'Invoice lines may differ from its total (£ a line)',
        'invoices.freight_spread' => 'Freight spread over the goods by',
        'invoices.duty_spread' => 'Duty spread over the goods by',
        'approvals.invoice_accept_other' => 'Accepting an invoice\'s differences needs a second person',
        // Pack F (0036; docs/decisions.md PF3, PF7, PF10, PF11).
        'alerts.webhook_url' => 'Web address the alerts are sent to',
        'alerts.email_to' => 'E-mail address the alerts go to',
        'alerts.repeat_minutes' => 'Send the same alert again after this many minutes',
        'alerts.heartbeat_late_seconds' => 'A store is late after this many seconds without a report',
        'alerts.outbox_max_depth' => 'Most messages a store may have waiting',
        'alerts.outbox_max_age_seconds' => 'Oldest a store\'s waiting message may be, in seconds',
        'alerts.dead_letters_max' => 'Messages failed for good before an alert',
        'alerts.feed_lag_max_seconds' => 'A store is behind the stock changes after this many seconds',
        'alerts.answer_max_ms' => 'Slowest answer time of the warehouse system, in milliseconds',
        'alerts.answer_probes' => 'How many times the answer time is measured',
        'alerts.invariants_max_age_hours' => 'Hours without a nightly safety check before an alert',
        'alerts.send_timeout_seconds' => 'Seconds sending one alert may take',
        'housekeeping.idempotency_keep_days' => 'Days the stores\' stored answers are kept',
        'housekeeping.channel_health_keep_days' => 'Days the stores\' reports are kept',
        'housekeeping.audit_keep_years' => 'Years the audit log is kept',
        'housekeeping.chunk_rows' => 'Rows removed at a time by the clean-up',
        'housekeeping.max_seconds' => 'Longest one clean-up may take, in seconds',
        'relay.correction_is_for_sale' => 'An ERPNext correction is the figure for sale',
        'relay.mode_in_stock' => 'Stock rule an ERPNext change to In-Stock asks for',
        'relay.mode_from_warehouse' => 'Stock rule an ERPNext change to From-Warehouse asks for',
        'relay.mode_out_of_stock' => 'Stock rule an ERPNext change to Out-Of-Stock asks for',
        // ---- Pack I (0039, the ERPNext opening import; docs/decisions.md PI1-PI6) ----
        'erp_opening.warehouses' => 'ERPNext warehouses the opening stock comes from',
        'erp_opening.companies' => 'ERPNext companies the opening stock comes from',
        'erp_opening.blocked_names' => 'Names never read from ERPNext',
        'erp_opening.below_zero' => 'What an item below zero in ERPNext opens at',
        'erp_opening.placeholder_costs' => 'ERPNext costs that are only placeholders',
        'erp_opening.listing_store' => 'The store whose variant numbers ERPNext items carry',
        'erp_opening.cw_warehouse' => 'Warehouse the opening stock is put in',
        'erp_opening.require_pending_sales' => 'Opening stock waits for the list of sales not yet in ERPNext',
        // ---- end of pack I ----
        // Pack H (0038; docs/decisions.md PH6-PH17).
        'handovers.pdf_needs_confirmed_company' => 'Hand-over invoices wait for confirmed company details',
        'handovers.invoice_terms' => 'Words printed under hand-over invoices',
        'handovers.price_fallback' => 'Price of a new hand-over line without a remembered price',
        'handovers.allow_overpayment' => 'Accept a payment that is more than the customer owes',
        'handovers.price_import_max_rows' => 'Most rows in one file of old prices',
        'duty.register_lines' => 'Lines the duty register lists',
        'duty.unstamped_warehouses' => 'Warehouses that hold unstamped stock',
        'duty.unstamped_zero_from' => 'Unstamped stock must be gone from',
        'duty.page_rows' => 'Lines of the duty register on a page',
        'duty.export_max_rows' => 'Most lines in one download of the duty register',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Errors: by HTTP status (heading) and by error code (message). Service messages are never edited (they reach the API).

    public const ERROR_TITLE = [
        '400' => 'This did not work',
        '401' => 'Please sign in',
        '403' => 'You cannot open this page',
        // A refused button press (a POST): the page opened; what was refused is the action.
        '403_post' => 'You cannot do this',
        '404' => 'Page not found',
        '405' => 'Use the button on the page',
        '409' => 'This changed meanwhile',
        '413' => 'The file is too big',
        '422' => 'Please check what you typed',
        '429' => 'Too many tries',
        '500' => 'Something went wrong',
        '503' => 'The system is not reachable',
    ];

    /**
     * Error code => the message the screens show: the kernel's own codes, and a service code only where one text is right for
     * every service message of that code (a code like lead_required, whose messages differ, keeps the service's message).
     */
    public const ERROR = [
        'csrf' => 'This page was open too long, so the form expired. Nothing was saved. Go back, reload the page (on a phone: pull down), and send it again.',
        'cross_site' => 'This form could not be accepted. Nothing was saved. Open the page from the menu and try again.',
        'lead_only' => 'Only a Matching lead can do this. Nothing was changed.',
        'decide_required' => 'You can look at this, but only Matchers and Matching leads can match products. Nothing was changed.',
        'not_found' => 'This address does not exist. The link may be old or mistyped.',
        'method_not_allowed' => 'This address only works from a button on a page. Go back and use the button.',
        'too_large' => 'The file is too big (over %d MB). Nothing was saved. Use a smaller file, or take the photo at a lower size.',
        'form_truncated' => 'This form is too long to send in one go (more than %d fields). Nothing was saved. '
            . 'For a long purchase order, download its lines, change them in Excel and import the file.',
        'bad_form_key' => 'This form is out of date. Nothing was saved. Reload the page and fill it in again.',
        'idempotency_key_reused' => 'You already sent this form with different details. Only the first one was saved. Reload the page to start again.',
        'unavailable' => 'The system is not reachable right now. Wait a minute, then reload the page.',
        'unconfigured' => 'This system is not set up yet. Tell the developer.',
        'busy' => 'The system is busy. Wait a few seconds and try again.',
        'internal' => 'Something went wrong on our side. Your last change may not have been saved: check before you try again. '
            . 'If it keeps happening, send the number below to the developer.',
        'unknown_staff' => 'We cannot find this person. They may have been removed, or the link is wrong.',
        'unknown_queue' => 'We could not find this list. It may have been renamed.',
        'unknown_listing' => 'This website product does not exist (the link may be old). Search for it instead.',
        'unknown_item' => 'This warehouse product does not exist (the link may be old). Search for it instead.',
        'unknown_group' => 'We cannot find this group of possible duplicates. The link may be old.',
        'unknown_sample' => 'We cannot find this spot check. The link may be old.',
        'unknown_store' => 'We cannot find this store. The link may be old: open By Item and choose again.',
        'unknown_variants_store' => 'We cannot find a store with variants by that name. The link may be old: open New Variants and choose a store.',
        'item_joined' => 'This warehouse item was joined into another one, so nothing is matched to it any more. Open the item it was joined into.',
        'unknown_document' => 'We cannot find this record. The link may be old or wrong.',
        'unknown_task' => 'We cannot find this check. It may be closed already, or the link is wrong.',
        'task_closed' => 'This check is closed already: someone decided it meanwhile. Nothing was changed.',
        'note_required' => 'This needs a note. Nothing was saved. Write why, then send it again.',
        'bad_version' => 'This form is out of date. Nothing was saved. Reload the page and fill it in again.',
        'bad_form' => 'This form is incomplete. Nothing was saved. Reload the page and try again.',
        'unknown_file' => 'We cannot find this file. The link may be old or wrong.',
        'no_setting' => 'We cannot find this setting. The link may be old.',
        'no_reason' => 'We cannot find this reason. The link may be old.',
        'no_warehouse' => 'We cannot find this warehouse. The link may be old.',
        'no_request' => 'We cannot find this request. It may be decided already.',
        // A line of a record whose reason needs a note (Documents names the line in detail.line).
        'note_required_line' => 'Line %s needs a note: its reason needs one. Write it on the line, or as a note for the whole record. Nothing was saved.',
        // Downloads scoped like the pages that link them (FilesController).
        'file_suppliers' => 'This file is a supplier\'s proof. It is shown to %s only.',
        'file_orders' => 'This file belongs to a purchase order. It is shown to %s only.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 5. The "?" help texts (**bold** marks the word being explained)

    public const HELP = [
        'how_sure' => 'The computer compares each website product with our warehouse products, using the barcode and an AI that reads the names. '
            . '**Strong match:** same barcode, or copied from the other website, and the AI agrees, so usually one tap. '
            . '**Likely match:** the AI thinks it is the same, but no barcode proves it, or a detail differs. '
            . '**New product:** nothing fits, so create one. **Not sure:** two or more fit, so you choose. '
            . '**Clues disagree:** the barcode says one product and the AI another, one barcode is on two products, or a rule says the barcode\'s product cannot be right; only a matching lead decides. '
            . '**Renamed range:** the websites name the range differently. (Older notes call a strong match "Key".)',
        'set_aside' => 'Some strong matches look doubtful, for example "mango ice" against "mango". They are set aside: a matching lead checks each one by hand, '
            . 'and they are never confirmed together with the rest.',
        'stock' => '**In the building:** what is on the shelves. **Sold, waiting to ship:** paid, not sent yet. '
            . '**Reserved (not paid):** in a customer\'s checkout; it is freed if they do not pay. **Free to sell:** in the building, minus the two above.',
        'reservations' => 'A **reservation** is the stock CW keeps for one order of a store, so that no other customer can buy it. '
            . '**Reserved (not paid):** the customer is in the checkout; the stock is kept until the time shown, then freed if nobody pays. '
            . '**Sold, waiting to ship:** the order is paid; the stock stays off sale until the store sends it. '
            . '**Freed by the store:** the store let the stock go, for example because the customer left the checkout. '
            . '**Ran out of time:** nobody paid in time, so CW freed the stock.',
        // Pack D (docs/decisions.md PD3, PD5)
        'sales_orders' => '**Paid (every sale)** is every order that was paid, whatever happened next. **With a unit sent, cancelled or returned** '
            . 'shows the orders that have at least one such unit. **Placed** is when the store first told CW about the order, in UK days. '
            . 'An order that was already open when the store joined CW shows the day it joined.',
        'sales_dispatch' => '**Waiting to ship** is every paid unit the store has not sent yet. **Late** is the number of orders paid longer ago than the hours '
            . 'set on the Settings page. **Sent today** and **Sent this week** (from Monday) are the orders with a unit sent then, also when the customer '
            . 'sent it back later. **Sending undone** is a dispatch the store reset: the unit is back with the orders waiting to ship.',
        'join_undo' => 'Vape and Go sometimes sells one product on two pages. **Join** them when they are the same product: both pages then use one warehouse product, '
            . 'so stock and sales are kept together on one product. Nothing changes on the website. If you joined two that are different, **Undo the join**: the page gets its own product '
            . 'back (with its stock, less what sold since), or becomes a new product. Once the website sells warehouse stock, joining or undoing needs a second matching lead.',
        'second_ok' => 'Some work needs two people, so one mistake cannot change stock or money on its own. **OK first:** nothing happens until a second person says yes '
            . '(for example a new supplier, or an order over the approval limit). '
            . '**Check after:** it happens at once, and a reviewer checks it later (for example every confirmed order and every delivery booked in). '
            . '**Nobody approves or checks their own work.** If you made it, or checked the delivery at the goods-in bench, someone '
            . 'else must. The Approval Rules page lists every rule, its limit and its days.',
        // Correction a (7 Oct): never suggest a second account (the spot check owner-1 belongs to this account).
        'admin_off' => 'Admin gives people access. To keep that safe, the person who gives access must not also approve work. '
            . 'So while an account has Admin, its working jobs (Reviewer, Matching lead, Buyer …) are switched off. '
            . 'Only these jobs still work: Look only (matching), Accountant, Auditor. Ask ' . self::ASK . ' to take Admin off the account that does the work.',
        'spot_check' => 'Instead of checking every strong match one by one, one matching lead checks a number of them picked at random '
            . '(the Approval Rules page says how many). If all are right, the rest are confirmed together in one step (' . self::ASK . ' runs it, and it can be undone). '
            . 'If even one is wrong, the rest must be checked one by one.',
        'sale_uses' => 'Most website products are single items: 1 sale uses 1 warehouse product. A 10-pack page uses 10. '
            . 'If this number is wrong, stock goes wrong, so anything other than 1 needs a second OK while that approval rule is on.',
        'stock_rule' => '**Website uses its own stock (not linked yet):** the website still uses its own stock figure. '
            . '**Website sells warehouse stock only:** the website can sell only what the warehouse has. '
            . '**Can sell when out:** customers can order and wait. **Not for sale.** '
            . 'Once a website sells warehouse stock, matching or joining its product needs a second OK.',
        // Deliveries (IM6): the stamp check, the unstamped rule, where the items go, what booking in does; the selling mode (IM10).
        'duty_stamp' => 'Every pack of vape liquid sold in the UK needs a UK duty stamp. **On the pack:** the stamp is on the outer retail pack and seals it, so '
            . 'the pack cannot be opened without breaking it. **Kind of stamp:** digital or transitional; the stamp itself shows which. **Stamp code:** the code on '
            . 'the stamp; scan it if you can (you may leave it empty). Coils, tanks and other products without liquid need no stamp.',
        'unstamped_rule' => 'From 1 Jan 2027 an unstamped duty item is never taken into stock: it is **refused at the door** (not taken in at all) or kept in the '
            . '**unstamped quarantine** to go back to the supplier. Until then, unstamped stock may be taken in only on the supplier\'s **proof that it was made '
            . 'or imported before 1 Oct 2026**, and it must be sold, sent back or destroyed by 31 Mar 2027. Anything made or imported later that arrives '
            . 'unstamped is always refused or kept apart. The rule goes by the day the goods arrived.',
        'where_units_go' => '**Into stock:** the items that arrived right, in the main warehouse and for sale. **Set aside to check:** damaged, wrong and extra '
            . 'items; they are not sold until stock control has looked at them. **Unstamped quarantine:** duty items without a valid stamp; never sold, they go '
            . 'back to the supplier. **Refused at the door:** not taken in. **Short:** on the paperwork, but not delivered. (Older notes and the stock records '
            . 'call the two places VERIFY and UNSTAMPED.)',
        'posting' => '**Booking in** adds the stock at once: the items that arrived right go into stock, the others where the goods-in bench put them. It also '
            . 'marks what arrived on the purchase order, opens an incident for each problem the bench found, and sets the selling mode on the websites '
            . 'deliveries reach. A reviewer checks every delivery within 3 days, and Not OK takes it back. Nobody checks a delivery they keyed, booked in, '
            . 'checked at the bench, or set the supplier invoice of.',
        'stock_owner' => '**Ours:** the stock belongs to our company; it is sold once a website sells from the warehouse. **Another account\'s:** for example the '
            . 'VPG 2 room: the stock belongs to someone else, is never sold from, and moves into our stock only with a release invoice.',
        'places' => 'A **place** is a shelf or a room inside a warehouse, for example the overflow room. Places are optional: nothing asks for one, '
            . 'and stock is not split by place yet.',
        'sign_up' => 'A new person gets a **sign-up sheet**, shown once on your screen: a QR code and a set-up code. They scan the QR code with a code '
            . 'app on their phone (Google Authenticator, Microsoft Authenticator), open "Set up my sign-in" and type their e-mail, the set-up code and '
            . 'the 6 numbers from the app. That sheet then stops working: **their own page** shows a new code, which they scan, and they choose their own '
            . 'password. Nobody else ever sees that code or the password. A new sign-in code for a lost phone works the same way, but only together '
            . 'with their own password. You never hold both of anybody\'s keys, so you can never sign in as them.',
        'safety_check' => 'Every night the system adds up every stock figure and checks it against the movements behind it, and checks that records, '
            . 'checks, approvals and settings agree. A **problem** means two of them disagree somewhere. Nothing is fixed by itself.',
        'site_modes' => '**Off:** the website and the warehouse do not talk. **Watching only:** CW follows the website\'s sales without changing what it '
            . 'sells. **Live:** the website sells warehouse stock.',
        'selling_mode' => 'What a website shows for a product whose stock rule is still "Website uses its own stock". **In-Stock:** it sells whatever the stock figure says. '
            . '**From-Warehouse:** it sells while there is stock. **Out-Of-Stock:** it does not sell. A website takes the mode from here only once its stock '
            . 'link is on. A delivery booked in sets the mode too, on the websites deliveries reach.',
    ];

    /** Sign-in and the password page (plan F062-F073). */
    public const SIGN_IN = [
        'failed' => 'Sign-in did not work. Check your e-mail and password. Each code works only once: wait for the next code on your phone and try again.',
        'locked' => 'Too many wrong tries. Wait 15 minutes, then try again. If you are still stuck, ask ' . self::ASK_ROLE . '.',
        'wrong_current' => 'Your old password is wrong.',
        'too_short' => 'The new password must have at least %d characters.',
        'too_long' => 'The new password must have at most %d characters.',
        'mismatch' => 'The two new passwords are not the same. Type the new one twice.',
        'unchanged' => 'The new password must be different from the old one.',
        'email' => 'Do not use your e-mail address as a password.',
        'changed' => 'Done: your password is changed.',
        // The sign-in page
        'email_label' => 'E-mail address',
        'password_label' => 'Password',
        'code_label' => '6-digit code from the code app on your phone',
        'code_hint' => 'For example Google Authenticator. It changes every 30 seconds.',
        'sign_in' => 'Sign in',
        // Behaviour item 3 (F059): the sign-in form was open longer than its cookie lives (1 hour).
        'expired' => 'This page was open too long. Please sign in again.',
        // The password page
        'welcome' => 'Welcome! First choose your own password. Then you can use the system.',
        'one_time' => 'The one-time password you were given',
        'current' => 'Your password now',
        'new' => 'New password (at least %s characters)',
        'again' => 'New password again',
        'change' => 'Change password',
        'after' => 'After the change you stay signed in here. On your other phones or computers you will need to sign in again.',
        // The "Show" button next to a password field (app.js reads these from the field's data- attributes).
        'reveal_show' => 'Show',
        'reveal_hide' => 'Hide',
        'reveal_show_label' => 'Show the password',
        'reveal_hide_label' => 'Hide the password',
    ];

    /** The frame's own words. */
    public const UI = [
        'brand' => 'Central Warehouse',
        'skip' => 'Skip to the page',
        'menu' => 'Menu',
        'close_menu' => 'Close the menu',
        'search_label' => 'Find a product by name, barcode or CW number',
        'search_placeholder' => 'Product name, barcode or CW number (CW-000123)',
        'search_button' => 'Find',
        'account' => 'Your account',
        'you_work_as' => 'You work as:',
        'my_account' => 'Change password',
        'open_menu' => 'Open the menu',
        'find' => 'Find a product',
        'soon' => 'Soon',
        'soon_title' => 'Coming soon: not built yet',
        'more_new' => 'More to create',
        // The toolbar under the tabs (design v4): a split create button, then Search, Filter, Sort, and Export at the end.
        'search' => 'Search',
        'filter' => 'Filter',
        'sort' => 'Sort',
        'export' => 'Export',
        'apply' => 'Apply',
        'help_link' => 'Help: what this system is',
        'fold' => 'Show or hide %s',
        'sign_out' => 'Sign out',
        'no_jobs' => 'no job yet',
        'off' => 'off',
        'help' => 'What does this mean?',
        'quote_rid' => 'If you report a problem, quote this number:',
        'back_home' => 'Back to the Dashboard',
        'back_sign_in' => 'Back to sign in',
        'coming_later' => 'Coming later:',
        'test_system' => 'TEST SYSTEM: nothing here is real',
        'look_only' => 'You can look; %s change this.',
        'job' => 'Job',
        'progress_done' => '%s of %s done',
        'progress_short' => '%s of %s',
        'what_happens' => 'What happens:',
        'safer' => 'Safer',
        'what_each_answer_does' => 'What each answer does',
        'login_first_time' => 'First time? Use the one-time password you were given. You will choose your own password next.',
        'login_stuck' => 'Lost your phone or forgot your password? Ask ' . self::ASK_ROLE . '.',
        'signed_out' => 'You were signed out: you were away for 30 minutes, 12 hours passed since you signed in, or your password changed. Sign in again to carry on.',
        // Behaviour item 1 (F057): a form sent while signed out is dropped.
        'lost' => 'What you sent was NOT saved, because you were not signed in any more. Sign in, then do it again.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 3. Home: "What needs doing", "What is this system?", matching progress, what you can use

    /** Home's own words (plan §3.1, F075-F093). */
    public const HOME = [
        'title' => 'Dashboard',
        'hello' => 'Hello %s.',
        'needs' => 'What needs doing',
        'jobs_one' => '1 job needs you. Start with it.',
        'jobs_many' => '%s jobs need you. Start with the top one.',
        'start_here' => 'Start here',
        'nothing' => 'Nothing is waiting for you right now.',
        'nothing_text' => 'When something needs you, it shows here.',
        'notes' => 'Good to know',
        'needs_you' => 'Needs you',
        'waiting' => 'Waiting',
        'out_of_date' => 'Out of date',
        'about' => 'What is this system?',
        'progress' => 'Matching progress',
        'look_match' => 'You can look; Matchers and Matching leads decide.',
        'to_match' => 'Website products still to match',
        'how_sure' => 'How sure',
        'total' => 'Total',
        'not_checked' => 'Not checked by the computer yet',
        'not_checked_help' => 'They have no suggestion. Where a barcode or a copied web address points to an item, the check on arrival suggests it; '
            . 'the rest wait for a person, or for the next full computer check.',
        'best_first' => 'Lists show best sellers first.',
        'pending_one' => '1 match is waiting for a second OK from a matching lead.',
        'pending_many' => '%s matches are waiting for a second OK from a matching lead.',
        'pending_none' => 'Nothing is waiting for a second OK.',
        'open' => 'Open',
        'duplicates' => 'Possible duplicates on Vape and Go: %s.',
        'duplicates_note' => 'Many are really different products. A matching lead checks each one. Nothing is joined without a person.',
        'coverage' => 'How much of what we sell is matched',
        'coverage_text' => 'Of all units sold, the share sold on website products already matched to a warehouse product. '
            . '100% means everything is matched. Products marked "ignore" are treated as not matched.',
        'site' => 'Website',
        'listings' => 'Website products',
        'matched' => 'Matched',
        'sold_30' => 'Sold (30 days)',
        'share_30' => 'Matched share (30 days)',
        'sold_365' => 'Sold (1 year)',
        'share_365' => 'Matched share (1 year)',
        'ignored_30' => 'Sold but ignored (30 days)',
        'no_sales' => 'no sales yet',
        'uses' => 'What you can use',
        'to_look' => 'To look at',
        // "What needs doing" as a board (design v4): the jobs first ("Ready to do"), then the notes
        'ready' => 'Ready to do',
        'tasks_one' => '1 task',
        'tasks_many' => '%s tasks',
        'col_task' => 'Task',
        'col_where' => 'Where',
        'col_status' => 'Status',
        'col_count' => 'How many',
        'col_next' => 'Next step',
        'tiles' => 'Main numbers',
    ];

    /** The Dashboard's widget tiles (Ui\DashboardTiles): title, the line under the number, the link. */
    public const TILE = [
        'products' => ['title' => 'Products', 'sub' => 'in the warehouse list', 'link' => 'All Products'],
        'units' => ['title' => 'Units in stock', 'sub' => 'on %s products', 'sub_one' => 'on 1 product', 'link' => 'Stock overview'],
        'matches' => ['title' => 'Matches to review', 'sub' => 'website products with a suggested match', 'link' => 'Mapping'],
        'approvals' => ['title' => 'Waiting for your approval', 'sub' => '%s to OK first · %s to check', 'link' => 'Approvals'],
        'orders' => ['title' => 'Open purchase orders', 'sub' => '%s draft · %s for approval · %s confirmed or sent', 'link' => 'Purchase Orders'],
        'deliveries' => ['title' => 'Deliveries to receive', 'sub' => 'not booked in yet', 'link' => 'Goods In'],
    ];

    /** "What is this system?" (plan §3.3), one paragraph each: open for a person's first 14 days, then folded. */
    public const ABOUT = [
        'Central Warehouse (CW) keeps one stock figure for each real product that Vape and Go, Electrofag and Vape Big sell.',
        'Each website has its own product pages. We match each website product to its warehouse product, so all websites share one stock figure.',
        'Here you also buy: suppliers, purchase orders, and a list of what to buy, worked out from sales.',
        'Nobody approves their own work. Some steps need a second person\'s OK.',
        'The cards above show what is waiting for you. Start with the top one. The ? next to a word explains it.',
        'Stuck? Ask ' . self::ASK_ROLE . '.',
    ];

    /**
     * Home's task cards (plan §3.2 C1-C20, with B's "What happens:" line): card key (Ui\HomeTasks) => title, unit ([one, many]
     * after the big number), text (why it matters), what (what happens when you press the button), button. `%s` is filled in
     * by HomeTasks.
     */
    public const TASK = [
        'company_confirm' => [
            'title' => 'Confirm our company details',
            'text' => 'Until they are confirmed, every purchase order PDF says DO NOT SEND.',
            'missing' => 'Still missing: %s.',
            'what' => 'You check each detail and change what is wrong, then confirm them. Nothing is sent to anyone.',
            'button' => 'Check and confirm',
            'button_missing' => 'Fill in and confirm',
        ],
        'company_wait' => [
            'title' => 'Order PDFs say DO NOT SEND',
            'text' => 'Our company details are not confirmed yet. Until a reviewer confirms them, every purchase order PDF says DO NOT SEND.',
            'button' => 'See the company details',
        ],
        'approvals' => [
            'title' => 'Things that need your OK first',
            'unit' => ['waits for you', 'wait for you'],
            'text' => 'Nothing goes ahead until a reviewer says yes: new suppliers, and orders over the approval limit.',
            'what' => 'You open each one and say OK or Not OK. Nobody approves their own work.',
            'button' => 'Open the list',
        ],
        'checks' => [
            'title' => 'Done work to check',
            'unit' => ['waits for you', 'wait for you'],
            'text' => 'These already happened: confirmed orders, and changes to suppliers or to our company details. A reviewer checks each one by its '
                . 'check-by date (an order within 7 days). Deliveries booked in have their own card.',
            'what' => 'You look at each one and say OK or Not OK.',
            'button' => 'Check them',
        ],
        'spot_check' => [
            'title' => 'Spot check %s: are these %s matches right?',
            'unit' => 'of %s checked',
            'text' => 'If all %s are right, about %s more strong matches are confirmed together. If one is wrong, the rest are checked one at a time instead.',
            'text_no_rest' => 'If all %s are right, the other strong matches are confirmed together. If one is wrong, they are checked one at a time instead.',
            'what' => 'You see one match at a time. Say yes only if it is right. You can stop and carry on later.',
            'button' => 'Check the next one (%s of %s)',
            'button_list' => 'Open the spot check',
        ],
        'duplicates' => [
            'title' => 'Possible duplicates to decide',
            'unit' => ['group', 'groups'],
            'text' => 'Vape and Go may sell the same product on two pages. Many are really different products. The list shows the biggest sellers first.',
            'what' => 'You compare the pages, then join them (same product) or keep them apart. Nothing is joined without a person.',
            'button' => 'Open the list',
        ],
        'set_aside' => [
            'title' => 'Strong matches set aside to check by hand',
            'unit' => ['to check', 'to check'],
            'text' => 'They looked doubtful, so they are never confirmed together with the rest of spot check %s.',
            'what' => 'You open each one and decide it on its own page.',
            'button' => 'Open the list',
        ],
        'second_ok' => [
            'title' => 'Matches waiting for your second OK',
            'unit' => ['waits for you', 'wait for you'],
            'text' => 'Another person made these. They only take effect when a second matching lead says yes.',
            'what' => 'You check each one and give your OK if it is right.',
            'button' => 'Open the list',
        ],
        'clues' => [
            'title' => 'Matches where the clues disagree',
            'unit' => ['to decide', 'to decide'],
            'text' => 'The clues point to different products. Only a matching lead decides these.',
            'what' => 'You look at the barcode and the names, then pick the right product.',
            'button' => 'Start',
        ],
        'strong' => [
            'title' => 'Strong matches to confirm',
            'unit' => ['to confirm', 'to confirm'],
            'text' => 'Same barcode, or copied from the other website, and the AI agrees. Best sellers come first.',
            'spot_first' => 'If your spot check %s passes, about %s of them are confirmed together, so do the spot check first.',
            'what' => 'Check the names, then confirm the match. A few seconds each. If a page says it is in someone else\'s spot check, leave it to them.',
            'button' => 'Start',
        ],
        'other' => [
            'title' => 'Other website products to match',
            'unit' => ['to match', 'to match'],
            'part' => '%s: %s',
            'what' => 'You compare each one and pick the right warehouse product, or create a new one.',
            'button' => 'Start',
        ],
        'bench' => [
            'title' => 'Deliveries waiting for the goods-in bench',
            'unit' => ['delivery', 'deliveries'],
            'text' => 'The goods are here, but nobody has checked them against the paperwork yet. They cannot be booked in before that.',
            'what' => 'Open each one on the tablet, check the duty stamps and anything short, extra, damaged or wrong, then save the check.',
            'button' => 'Open the bench',
        ],
        'bench_refused' => [
            'title' => 'Deliveries refused at the goods-in bench',
            'unit' => ['delivery', 'deliveries'],
            'text' => 'The goods-in bench said the supplier or the paperwork is not right, so they cannot be booked in.',
            'what' => 'Open each one, sort it out with the supplier (or send the goods back), then cancel the delivery. If the bench was wrong, '
                . 'it checks the delivery again.',
            'button' => 'Open the list',
        ],
        'to_post' => [
            'title' => 'Checked deliveries to book in',
            'unit' => ['delivery', 'deliveries'],
            'text' => 'The goods-in bench has checked them. Until they are booked in, their stock cannot be sold.',
            'what' => 'Open each one, settle what "Before it can be booked in" lists (the invoice number and its copy), then book it in.',
            'button' => 'Open the list',
        ],
        'deliveries_check' => [
            'title' => 'Deliveries booked in to check',
            'unit' => ['waits for you', 'wait for you'],
            'text' => 'Their stock is added already. A reviewer checks each one within 3 days. You never check one you keyed, booked in, checked at the bench '
                . 'or set the supplier invoice of.',
            'what' => 'You look at what arrived and where it went, then say OK or Not OK. Not OK takes the delivery back.',
            'button' => 'Check them',
        ],
        'incidents' => [
            'title' => 'Open incidents from deliveries',
            'unit' => ['incident', 'incidents'],
            'text' => 'Problems the goods-in bench found: short, extra, damaged, the wrong product, or no duty stamp.',
            'what' => 'Deal with each one (ask for a credit, send the goods back), then close it with what was done.',
            'button' => 'Open the incidents',
        ],
        'barcodes' => [
            'title' => 'Barcodes to check',
            'unit' => ['to check', 'to check'],
            'text' => 'Barcodes the computer could not place.',
            'what' => 'You decide which product (single or pack) each one belongs to.',
            'button' => 'Open the list',
        ],
        'stock_drafts' => [
            'title' => 'Your stock records not final yet',
            'unit' => ['draft', 'drafts'],
            'text' => 'A stock in, stock out, adjustment, transfer or release you started books nothing until it is made final.',
            'what' => 'Open each one, check the products, then make it final or stop it.',
            'button' => 'Open the drafts',
        ],
        'stock_waiting' => [
            'title' => 'Your stock records waiting for an OK',
            'unit' => ['waits for a reviewer', 'wait for a reviewer'],
            'text' => 'A rule asks a reviewer to say OK first. Nothing is booked until they do.',
            'what' => 'Nothing for you to do. You can take one back to change it.',
            'button' => 'See them',
        ],
        'drafts' => [
            'title' => 'Draft orders you have not confirmed',
            'unit' => ['draft', 'drafts'],
            'text' => 'A draft has no PO number yet, and the supplier does not have it.',
            'what' => 'Open each draft, check it, then confirm it or cancel it. An order over the approval limit goes to a reviewer first.',
            'button' => 'Open the drafts',
        ],
        'not_sent' => [
            'title' => 'Confirmed orders not sent yet',
            'unit' => ['order', 'orders'],
            'text' => 'The supplier does not have these orders yet. CW does not send e-mails.',
            'what' => 'Send each order to its supplier, then record on its page how you sent it.',
            'button' => 'Open the list',
        ],
        'not_ok' => [
            'title' => 'Orders a reviewer said are not OK',
            'unit' => ['order', 'orders'],
            'text' => 'The order still stands until you change it.',
            'what' => 'Open each one and read why, then cancel it or correct it.',
            'button' => 'Open the list',
        ],
        'to_buy' => [
            'title' => 'See what to buy now',
            'text' => 'Worked out from recent sales. The most urgent products come first.',
            'what' => 'Tick what you want, then make draft orders.',
            'button' => 'See what to buy',
        ],
        'old_sales' => [
            'title' => 'Sales data is %s days old',
            'text' => 'So Reorder may be too low. Ask ' . self::ASK . ' to load the new sales.',
            'button' => 'See the sales data',
        ],
        'supplier_drafts' => [
            'title' => 'Suppliers not ready yet',
            'unit' => ['draft', 'drafts'],
            'text' => 'A new supplier needs its details and a background check before a reviewer can approve it.',
            'what' => 'Fill in what is missing, then ask a reviewer to approve the supplier.',
            'button' => 'Open the drafts',
        ],
        'checks_due' => [
            'title' => 'Background checks due soon',
            'unit' => ['supplier', 'suppliers'],
            'text' => 'Due within 30 days, or already late.',
            'what' => 'Check each supplier again, then save the new dates on its page.',
            'button' => 'Open the list',
        ],
        'test_accounts' => [
            'title' => 'Test accounts that can still sign in',
            'unit' => ['account', 'accounts'],
            'text' => 'Nobody approves their own work. A test account that can sign in is a way round that rule.',
            'what' => 'Open each one and stop it signing in.',
            'button' => 'Open it',
            'button_many' => 'Open the staff list',
        ],
        'reviewers' => [
            'title' => 'Only 1 person can approve work',
            'title_none' => 'Nobody can approve work',
            'text' => 'At least %s Reviewers are needed, so a holiday never stops the checks.',
            'what' => 'Give the Reviewer job to another person.',
            'button' => 'Open the staff list',
        ],
        'admin_clash' => [
            'title' => '%s: jobs switched off by Admin',
            'text' => '%s has Admin, so these jobs do nothing: %s.',
            'what' => 'Untick Admin on their page and save. Their other jobs then work again.',
            'button' => 'Open %s',
        ],
        'own_clash' => [
            'title' => 'Your jobs are switched off',
            // The yellow strip at the top of every page says the whole sentence; the card points to it instead of repeating it.
            'text' => 'The yellow note at the top of the page says why, and who can fix it.',
            'what' => 'You see your jobs. Until Admin is taken off this account, you can look but not approve or decide.',
            'button' => 'See your access',
        ],
        'integrity' => [
            'title' => 'The nightly system check found %s problems',
            'title_one' => 'The nightly system check found 1 problem',
            'text' => 'Stock figures or records do not agree somewhere. Nothing is fixed by itself.',
            'what' => 'You see what was found. Tell ' . self::ASK . ' the same day.',
            'button' => 'See the system checks',
        ],
        'integrity_stale' => [
            'title' => 'The nightly system check has not run since %s',
            'text' => 'It should run every night.',
            'what' => 'Tell ' . self::ASK . ', so he can start it again.',
            'button' => 'See the system checks',
        ],
        'staff_requests' => [
            'title' => 'Staff access waiting for your OK',
            'unit' => ['waits for you', 'wait for you'],
            'text' => 'An admin gave someone the Admin or Reviewer job, or asked to reset their sign-in. It happens only after a reviewer says OK.',
            'what' => 'You check each one and say OK or Not OK.',
            'button' => 'Open the list',
        ],
        // Review finding I1 (Y45): what an admin did to staff access and the approval rules lately, for the reviewers to look at.
        'watch' => [
            'title' => 'Staff and rule changes to look at',
            'unit' => ['change in the last %s days', 'changes in the last %s days'],
            'text' => 'People added, sign-ins reset and approval rules made looser. Check that each one was wanted.',
            'what' => 'You read each one. If one was not wanted, tell the owner and ' . self::ASK . ' the same day.',
            'button' => 'Open the audit log',
        ],
        // Review finding I5 (Y42): a set-up window closed after too many wrong tries.
        'setup_closed' => [
            'title' => 'A sign-in set-up was closed after too many wrong tries',
            'unit' => ['person', 'people'],
            'text' => 'Someone typed wrong codes at "Set up my sign-in" too often, so it closed. If the person did not make those tries, someone else did.',
            'what' => 'Ask the person. If it was them, the admin makes them a new sheet. If not, tell the owner.',
            'button' => 'See who',
            'button_admin' => 'Open their page',
        ],
        'no_job' => [
            'title' => 'You cannot use anything yet',
            'text' => 'No job has been set for you. Ask ' . self::ASK_ROLE . ' to give you access. Tell him what your job is.',
        ],
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Start and settings pages: sign-in, password, To check, records, company details, settings and lists, staff
    // (plan §6.3-6.8, 6.13, 6.31-6.36). Service messages are never edited: these pages translate them by error code.

    /** Why this person cannot decide a check (Documents/Suppliers/CompanyDetails::refusal codes; their messages are the API's). */
    public const REFUSAL = [
        'own_document' => 'This is your own work, so another reviewer must check it.',
        'own_supplier' => 'You made or changed this supplier, so another reviewer must decide.',
        'own_change' => 'You made or confirmed this change, so another reviewer must check it.',
        'admin_cannot_review' => 'Admin cannot check or approve work.',
        'role_not_allowed' => 'Only Reviewers can decide this.',
    ];

    /** document_type.code => the screens' name of one record of that kind (document_type.name is the fallback). */
    public const DOC_TYPE = [
        'PO' => 'Purchase order',
        'GRN' => 'Delivery',
        'SINV' => 'Supplier invoice',
        'DN' => 'Return to a supplier',
        'CNT' => 'Stock count',
        'ADJ' => 'Adjustment',
        'WO' => 'Write-off',
        'TRD' => 'Hand-over',
        'SIN' => 'Stock in',
        'SOUT' => 'Stock out',
        'TRF' => 'Transfer',
        'REL' => 'Release',
        'RPK' => 'Repack', // Pack B
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'SCN' => 'Supplier credit note',
    ];

    /** document_type.code => many of that kind (lists, filters, sentences). */
    public const DOC_TYPES = [
        'PO' => 'Purchase orders',
        'GRN' => 'Deliveries',
        'SINV' => 'Supplier invoices',
        'DN' => 'Returns to suppliers',
        'CNT' => 'Stock counts',
        'ADJ' => 'Adjustments',
        'WO' => 'Write-offs',
        'TRD' => 'Hand-overs',
        'SIN' => 'Stock in records',
        'SOUT' => 'Stock out records',
        'TRF' => 'Transfers',
        'REL' => 'Releases',
        'RPK' => 'Repacks', // Pack B
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'SCN' => 'Supplier credit notes',
    ];

    /** document.review_state, short (the "Reviewer check" filter and column). */
    public const CHECK_STATE = [
        'not_required' => 'Not needed',
        'pending' => 'Waiting',
        'approved' => 'OK',
        'rejected' => 'Not OK',
    ];

    /** Things to check (/ui/documents/reviews, plan §6.8). */
    public const CHECKS = [
        'how' => 'Top list: nothing happens until you say yes. Bottom list: already done – check it is right. You never check your own work: another reviewer does.',
        'kind' => 'Show',
        'everything' => 'Everything',
        'suppliers' => 'Suppliers',
        'company' => 'Company details',
        'show' => 'Show',
        'none' => 'Nothing is waiting for you to check.',
        'none_text' => 'When someone needs your OK or your check, it shows here.',
        'none_kind' => 'Nothing of this kind is waiting.',
        'none_kind_text' => 'Other kinds of work may be waiting.',
        'show_all' => 'Show everything',
        'empty_list' => 'Nothing here.',
        'what' => 'What',
        'why' => 'Why',
        'value' => 'Value (no VAT)',
        'items' => '%s items',
        'asked_by' => 'Asked by',
        'asked_on' => 'Asked on',
        'check_by' => 'Check by',
        'next' => 'Next step',
        'late' => 'Late',
        'open' => 'Open',
        'order_no_number' => 'Order for %s (no number yet)',
        'record_no_number' => '%s (no number yet)',
        'cancellation' => 'Cancellation of %s',
        'supplier' => 'Supplier: %s',
        'company_changed' => 'Company details changed',
    ];

    /** All records (/ui/documents, plan §6.31). */
    public const RECORDS = [
        'today' => 'Today this list holds %s, and their cancellations.',
        'later' => '%s will appear here when those screens are added.',
        'none_live' => 'No kind of record is in use yet.',
        'po_hidden' => 'Purchase orders are only shown to buying staff.',
        'kind' => 'Kind',
        'all_kinds' => 'All kinds',
        'status' => 'Status',
        'any_status' => 'Any status',
        'check' => 'Reviewer check',
        'any_check' => 'Any',
        'search' => 'Number or supplier\'s reference',
        'show' => 'Show',
        'total_one' => '1 record, newest first.',
        'total_many' => '%s records, newest first.',
        'none' => 'No records yet.',
        'none_text' => 'A record appears here when it becomes final.',
        'none_po' => 'No records you can see yet.',
        'none_filter' => 'No record matches these filters.',
        'clear' => 'Show all records',
        'number' => 'Number',
        'what' => 'What',
        'date' => 'Date',
        'ref' => 'Supplier\'s reference',
        'made_by' => 'Made by',
        'final_on' => 'Final on',
        'no_number' => 'no number yet',
        'order_for' => 'Order for %s',
        'newer' => 'Newer',
        'older' => 'Older',
        'page' => 'Page %s of %s',
    ];

    /** One record (/ui/documents/{id}, plan §6.32). */
    public const RECORD = [
        'later' => 'The screens for %s are coming later.',
        'open_order' => 'Open the order',
        'open_stock' => 'Open the stock record',
        'open_stock_note' => 'Its places, products, prices and files are on its own page in Stock.',
        'open_order_note' => 'Its own page under Purchasing: lines, sending, cancelling, and the PDF for the supplier.',
        'open_receipt' => 'Open the delivery',
        'open_receipt_note' => 'Its own page under Goods In: lines, the goods-in bench check, problems found, and the files.',
        'status' => 'Status',
        'check' => 'Reviewer check',
        'not_final' => 'Not final yet',
        'supplier' => 'Supplier',
        'value' => 'Value (no VAT)',
        'date' => 'Date',
        'warehouse' => 'Warehouse',
        'ref' => 'Supplier\'s reference',
        'reason' => 'Reason',
        'note' => 'Note',
        'made' => 'Made',
        'asked' => 'OK asked for',
        'final' => 'Final on',
        'cancelled' => 'Stopped',
        'by' => '%s by %s',
        'by_cw' => 'CW (set up)',
        'cancels' => 'Cancels',
        'cancelled_by' => 'Cancelled by',
        'cancel_asked' => 'Cancellation asked for',
        'cancel_waiting' => 'Cancellation waiting for a reviewer\'s OK',
        'download' => 'Download record (for the files – not for the supplier)',
        'tech' => 'Technical details',
        'fingerprint' => 'Fingerprint (shows the record was not changed)',
        // The decision
        'approval_title' => 'Needs your OK first',
        'review_title' => 'Reviewer check',
        'approval_text' => 'This %s (%s) is held back until a reviewer says OK. Check by %s.',
        'review_text' => 'This %s (%s) is final and waits for a reviewer\'s check. Check by %s.',
        'waiting' => 'Waiting for a reviewer.',
        'ok_approval' => 'OK, go ahead',
        'ok_review' => 'OK, it is right',
        'not_ok' => 'Not OK – say why',
        'note_optional' => 'Note (optional)',
        'why_not_ok' => 'Why it is not OK (required)',
        'does_ok_approval' => 'It becomes final now, as if the person who asked had done it.',
        'does_ok_cancel' => 'The cancellation becomes final now: the stock of the original record is put back.',
        'does_ok_review' => 'The check is closed. Nothing else changes.',
        'does_not_ok_approval' => 'The request is cancelled. Nothing is booked.',
        'does_not_ok_review' => 'A cancellation record is made now: the stock is put back.',
        'does_not_ok_cancel' => 'Your "not OK" is recorded and nothing is booked: a cancellation is never cancelled. '
            . 'If the original was right, the person who made it makes it again as a new record.',
        'does_not_ok_record' => 'Your "not OK" is recorded and the record stays (an order may already be with the supplier). The person who made it then cancels or corrects it.',
        // Cancelling a record (a cancellation record, the "reversal")
        'cancel_title' => 'Cancel this record',
        'cancel_text' => 'A final record is never changed. Cancelling it makes a cancellation record: a new record with every line the other way round. '
            . 'It puts the stock back exactly, and a reviewer checks it like any other. If it puts back more than the limit without a supplier document, a reviewer must OK it first.',
        'why' => 'Why',
        'choose' => 'Choose a reason',
        'cancel_button' => 'Make the cancellation record',
        'cancel_not_allowed' => 'You cannot cancel this kind of record. Nothing was changed.',
        'e_not_reversible' => 'This record cannot be cancelled: it is cancelled already, or it is a cancellation itself. Nothing was changed.',
        'e_reversal_pending' => 'A cancellation of this record already waits for a reviewer\'s OK. Nothing was changed.',
        'e_done' => 'There is nothing left to decide here: someone decided it meanwhile. Nothing was changed.',
        'reason_required' => 'Choose why the record is cancelled. Nothing was saved.',
        // Lines, files, history
        'lines' => 'Lines',
        'no_lines' => 'No lines.',
        'line' => 'Line',
        'product' => 'Product',
        'items' => 'Items',
        'per_item' => '£ per item',
        'total' => '£ total',
        'description' => 'Description',
        'files' => 'Files',
        'no_files' => 'No files.',
        'file_role' => 'What it is',
        'file' => 'File',
        'size' => 'Size',
        'added' => 'Added on',
        'history' => 'Checks',
        'no_history' => 'No check or OK was asked for.',
        'kind_approval' => 'OK asked for',
        'kind_review' => 'Reviewer check asked for',
        'asked_line' => '%s: %s by %s.',
        'why_line' => 'Why: %s.',
        'open_until' => 'Waiting. Check by %s.',
        'decided' => '%s by %s on %s.',
        'closed_cancelled' => 'Closed: the record was cancelled (%s).',
        'closed_withdrawn' => 'Closed: the person who asked cancelled the request.',
        'closed' => 'Closed on %s.',
        // --- Pack C: a supplier invoice, credit note or return opens on its own page in Purchasing.
        'open_paper' => 'Open it in Purchasing',
        'open_paper_note' => 'Its lines, how it matches, its credit notes and its files are there.',
    ];

    /** Notices after a click on a record page (DocumentsController::NOTICES). */
    public const RECORD_NOTICE = [
        'approved' => 'Done: you checked it and said OK.',
        'approved_posted' => 'Done: you said OK. It is final now, as if the person who asked had done it.',
        'rejected_review' => 'Done: you said not OK, so a cancellation record was made (linked below). The stock is put back.',
        'rejected_reversal' => 'Done: your "not OK" is recorded and nothing was booked, because a cancellation is never cancelled. '
            . 'If the original was right, the person who made it makes it again as a new record.',
        'rejected_approval' => 'Done: the request was cancelled. Nothing was booked.',
        'rejected_recorded' => 'Done: your "not OK" is recorded and the record stays (an order may already be with the supplier). The person who made it cancels or corrects it.',
        'reversed' => 'Done: the cancellation record is made. The stock of the original record is put back.',
        'reversal_submitted' => 'Cancellation asked for: it puts stock back without a supplier document above the limit, so a reviewer must OK it first. '
            . 'Nothing is numbered or booked until then.',
    ];

    /** Company details (/ui/reference/company and /edit, plan §6.13). */
    public const COMPANY = [
        'confirmed' => 'Confirmed',
        'not_confirmed' => 'Not confirmed',
        'confirmed_by' => 'Confirmed by %s on %s. Purchase orders print these details.',
        'banner' => 'Every purchase order PDF says "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND" until a reviewer checks these details and confirms them.',
        'missing' => 'Still missing: %s.',
        'problems' => 'These details need correcting:',
        'problems_confirmed' => 'These details need correcting (they were confirmed before today\'s checks):',
        'check_first' => 'Check every detail below against the company\'s records (Companies House, the VAT registration certificate) and the warehouse address first.',
        'confirm_after' => 'After this, new purchase order PDFs no longer say DO NOT SEND.',
        'confirm' => 'These details are correct',
        'unsaved' => 'These details were copied from the old settings. Press "Change the details" and Save once (spaces and line breaks are tidied), then confirm them here.',
        'fill_in' => 'Press "%s", fill in what is missing or wrong, then confirm them here.',
        'add' => 'Add or change the details',
        'change' => 'Change the details',
        'vat_digits' => 'The check digits of the VAT number do not add up. Check it against the VAT registration certificate (it is kept as typed).',
        'rejected_one' => 'A reviewer said a change of these details is wrong. 1 confirmed order still prints it and says DO NOT SEND. Open it and press Cancel or Correct:',
        'rejected_many' => 'A reviewer said a change of these details is wrong. %s confirmed orders still print it and say DO NOT SEND. Open each one and press Cancel or Correct:',
        'stale_one' => '1 confirmed order still prints the old, unconfirmed details and says DO NOT SEND:',
        'stale_many' => '%s confirmed orders still print the old, unconfirmed details and say DO NOT SEND:',
        'stale_fix' => 'Open it and press Correct to make a copy with the right details.',
        'stale_fix_first' => 'Confirm the details first. Then open it and press Correct to make a copy with the confirmed details.',
        'sample' => 'See how a purchase order will look (PDF)',
        'look_reviewer' => 'Only a reviewer can change these. Tell them if something is wrong.',
        'look_admin' => 'You can only look here: Admin switches off your Reviewer job.',
        'details' => 'The details',
        'legal_name' => 'Legal name',
        'trading_name' => 'Trading name',
        'company_number' => 'Company number',
        'vat' => 'VAT',
        'address' => 'Registered address',
        'phone' => 'Purchasing phone',
        'email' => 'Purchasing e-mail',
        'delivery_address' => 'Delivery address',
        'not_given' => 'not given (prints "[to be confirmed]")',
        'none' => 'none',
        'not_vat' => 'Not VAT registered',
        'last_saved' => 'Last saved',
        'by' => '%s by %s',
        'no_bank' => 'We never keep bank details here.',
        'checks' => 'Changes another reviewer must check',
        'checks_text' => 'Another reviewer must check this change (orders can still be sent meanwhile). If they say it is wrong, the PDFs say DO NOT SEND again until it is fixed.',
        'check_line' => '%s confirmed a change on %s.',
        'check_waiting' => 'Waiting for a check.',
        'check_by' => 'Check by %s.',
        'late' => 'Late',
        'check_decided' => '%s by %s on %s',
        'changed' => 'What changed since the details were last confirmed:',
        'was' => 'Was:',
        'now' => 'Now:',
        'empty' => '(empty)',
        'right' => 'Yes, the change is right',
        'right_does' => 'The check is closed. Nothing else changes.',
        'wrong' => 'Not OK – say why',
        'wrong_does' => 'If the details in use still carry this change, they are not confirmed any more: new purchase order PDFs say DO NOT SEND until someone corrects and confirms them.',
        'note_optional' => 'Note (optional)',
        'why_wrong' => 'What is wrong (required)',
        'alone' => 'Nobody else is a Reviewer yet, so this check stays open. It stops nothing. When a second person has the Reviewer job (Users), they can close it.',
        'history' => 'History',
        'no_history' => 'No details were saved yet.',
        'h_seed' => 'Copied from the old settings',
        'h_confirm' => '%s confirmed them',
        'h_unconfirm' => '%s made them not confirmed',
        'h_change' => '%s changed them',
        'h_check_open' => 'another reviewer still has to check this change',
        'h_check_ok' => 'another reviewer checked it: OK',
        'h_check_wrong' => 'another reviewer said it is wrong',
        'h_check_closed' => 'the check was closed',
        'why' => 'Why: %s',
        'broken' => 'This entry changed details, which this screen never does: tell the developer.',
        'set_up' => 'the set-up (copied from the old settings)',
        'old_settings' => 'the old settings',
        'set_up_short' => 'the set-up',
        // The form
        'back' => 'Back to the company details',
        'until_confirmed' => 'Until then the PDFs say DO NOT SEND.',
        'invalid' => 'Nothing was saved: some details need correcting (marked below).',
        'changed_meanwhile' => 'What changed meanwhile (your form still shows what you typed; saving it now replaces these):',
        'is_confirmed' => 'These details are confirmed. Saving a change makes them "not confirmed" until someone confirms them again. '
            . 'If you confirm your own change of the legal name, company number, VAT, purchasing e-mail or delivery address, another reviewer is asked to check it.',
        'the_company' => 'The company',
        'legal_name_hint' => '(as registered at Companies House, for example Example Vapes Ltd)',
        'trading_name_hint' => '(optional: the name customers know, printed under the legal name)',
        'company_number_hint' => '(8 characters, for example 01234567 or SC123456)',
        'address_hint' => '(press Enter for each new line, up to %s lines)',
        'vat_registered' => 'VAT registered',
        'vat_not' => 'Not VAT registered',
        'vat_not_hint' => '(no VAT number is printed)',
        'vat_unknown' => 'Not known yet',
        'vat_number' => 'VAT number',
        'vat_number_hint' => '(if VAT registered, for example GB 123 4567 89; spaces are fine)',
        'contact' => 'Purchasing contact',
        'phone_label' => 'Phone',
        'optional' => '(optional)',
        'email_label' => 'E-mail',
        'email_hint' => '(where suppliers reply about orders)',
        'delivery' => 'Delivery',
        'delivery_hint' => '(the warehouse goods are delivered to, printed in the "Deliver to" box; up to %s lines)',
        'why_legend' => 'Why',
        'reason' => 'Reason for the change',
        'reason_hint' => '(optional, kept in the history)',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'letters' => 'Letters such as é, ü, ß, £ and € print on the PDF; other alphabets and emoji do not, so they are refused. We never keep bank details here.',
        'saved_twice' => 'You already saved this form once. What you typed is kept below: check it and press Save again.',
        'now_details' => 'Here are the details as they are now: check them, then confirm again.',
        'changed_saved' => 'Someone changed the company details while you had them open (%s saved them on %s). Nothing was saved.',
        'changed_confirmed' => 'Someone changed the company details while you had them open (%s saved them on %s). Nothing was confirmed.',
        'changed_same_time' => 'Someone else saved the company details at the same moment. Nothing was saved.',
        'not_reviewer' => 'Only Reviewers change or check the company details. Nothing was changed.',
        // Pack H (0038; docs/decisions.md PH3): the VAT code of our own invoices and the currency.
        'vat_code' => 'VAT code of our invoices',
        'vat_code_hint' => '(the VAT code a hand-over invoice charges, unless the customer has its own)',
        'vat_code_none' => 'Not said yet',
        'currency' => 'Currency',
        'currency_gbp' => 'Pounds (GBP)',
        'currency_line' => 'Every amount is in pounds (GBP): the only currency the warehouse uses.',
    ];

    /** Notices after a click on the company details (CompanyController::NOTICES). */
    public const COMPANY_NOTICE = [
        'saved' => 'Saved. The details are not confirmed yet: until someone confirms them, every purchase order PDF says DO NOT SEND.',
        'unchanged' => 'Nothing changed.',
        'confirmed' => 'Confirmed: purchase orders now print these details without DO NOT SEND.',
        'confirmed_review' => 'Confirmed: purchase orders now print these details without DO NOT SEND. You confirmed your own change of the legal name, '
            . 'company number, VAT, purchasing e-mail or delivery address, so another reviewer is asked to check it (orders can still be sent meanwhile).',
        'already' => 'These details were already confirmed.',
        'review_approved' => 'Done: you checked the change and said it is right.',
        'review_rejected' => 'Done: you said the change is wrong. The details in use carried it, so they are not confirmed any more: new purchase order PDFs '
            . 'say DO NOT SEND until someone corrects and confirms them.',
        'review_rejected_kept' => 'Done: you said the change is wrong. The details in use no longer carry it, so nothing else changed.',
    ];

    /** Settings and lists (/ui/reference/settings, reasons, series; plan §6.33, 6.34). */
    public const SETTINGS_PAGE = [
        'company_text' => 'Our name, numbers and addresses, printed on every purchase order',
        'not_confirmed_text' => 'Every purchase order PDF says DO NOT SEND until they are confirmed.',
        'see_company' => 'See the company details',
        'edit_company' => 'Add or change the company details',
        'settings' => 'Settings',
        'settings_text' => '"Not agreed yet" means the owner still has to say yes to this value. Open a setting to change it or to mark it agreed. '
            . 'Company details: use the box above. Approval rules have their own page.',
        'setting' => 'Setting',
        'value' => 'Value',
        'status' => 'Status',
        'what' => 'What it does',
        'changed' => 'Changed',
        'agreed' => 'Agreed',
        'not_agreed' => 'Not agreed yet',
        'not_set' => 'not set',
        'yes' => 'Yes',
        'no' => 'No',
        'set_up' => 'When CW was set up (%s)',
        'on_server' => '%s (changed on the server)',
        'by' => '%s by %s',
        'open' => 'Open',
        'approval_rules' => 'Approval rules',
        'approval_rules_text' => 'Who checks what, the limits and the days are changed on the Approval Rules page.',
        'access' => 'Who can do what',
        'rules' => 'Who checks what',
        'rules_text' => 'Only the kinds of record in use today. Nobody checks their own work.',
        'rule_all' => 'a reviewer checks every one within %s days.',
        'rule_over' => 'a reviewer checks those over %s items within %s days.',
        'rule_none' => 'no reviewer check.',
        'rule_value' => 'Over £%s needs a reviewer\'s OK first.',
        'rule_units' => 'Putting back more than %s items without a supplier document needs a reviewer\'s OK first.',
        'rule_record_po' => 'If the reviewer says not OK, the order stays and the buyer cancels or corrects it.',
        'rule_record' => 'If the reviewer says not OK, the record stays and the person who made it fixes it.',
        // A stock count's rule since CT8 (review M-4, 14 Oct 2026): the second person who answers a count difference.
        'rule_cnt_none' => 'no second person: whoever counted may answer their own count differences on Stock > Counts > Differences.',
        'rule_cnt_over' => 'a count difference of more than %s units is answered by a second person, not the one who counted it.',
        'rule_cnt_all' => 'every count difference is answered by a second person, not the one who counted it, and a reviewer also checks every count '
            . 'record within %s days.',
        'rule_record_cnt' => 'If the reviewer says not OK, the count stays: a count is put right by counting again.',
        'rule_reverse' => 'If the reviewer says not OK, a cancellation record is made.',
        'vat' => 'VAT codes',
        'code' => 'Code',
        'meaning' => 'Meaning',
        'rate' => 'Rate',
        'in_use' => 'In use',
        'footer' => 'A new supplier, or one used again, needs a reviewer\'s OK before anyone orders from it, and so does a change of a supplier\'s duty-stamp arrangement. '
            . 'We keep no supplier bank details. CW does not copy its costs to the websites.',
        // Reasons for stock changes
        'reasons_text' => 'For example a count, a write-off or a return. The list is not agreed yet. Reasons marked "set by CW" are never offered on a form. '
            . 'A free gift is reported apart; vaping and nicotine products may not be given away free to the public from 29 Oct 2026.',
        'download_reasons' => 'Download as a spreadsheet (CSV, opens in Excel)',
        'reason' => 'Reason',
        'used_for' => 'Used for',
        'direction' => 'Up or down',
        'needs_note' => 'Needs a note',
        'gift' => 'Free gift',
        'by_cw' => 'Set by CW',
        // How record numbers are made
        'series_text' => 'The numbers never restart each year. A record gets its number only when it becomes final; a draft or a cancelled request has none.',
        'kind' => 'Kind of record',
        'starts' => 'Starts with',
        'last' => 'Last number',
        'next' => 'Next number',
        'checks' => 'Reviewer checks',
        'ok_first' => 'OK needed first',
        'days' => 'Days to check',
        'screens' => 'Screens',
        'none_yet' => 'none yet',
        'live' => 'In use',
        'later' => 'Coming later',
        'every' => 'Every one',
        'over_items' => 'Over %s items',
        'none' => 'None',
        'over_value' => 'Over £%s (no VAT)',
        'over_units' => 'Putting back over %s items without a supplier document',
        // --- Pack C follow-up (PC19): a setting that is not used while the switch it depends on is off (greyed in the list).
        'unused_while_off' => 'Not used while "%s" is off.',
    ];

    /** reason_code.applies_to and direction words (Reasons for stock changes). */
    public const REASON_USE = [
        'adjustment' => 'stock corrections',
        'write_off' => 'write-offs',
        'count' => 'stock counts',
        'return' => 'customer returns',
        'supplier_return' => 'returns to suppliers',
        'reversal' => 'cancelling records (deliveries and others)',
        // The order screens (Y51).
        'po_cancel' => 'cancelling a confirmed order',
        'po_draft_cancel' => 'cancelling an order not confirmed yet',
        'po_amend' => 'correcting a confirmed order',
        // The stock records of pack A1.
        'stock_in' => 'stock in',
        'stock_out' => 'stock out',
        'increase' => 'Up',
        'decrease' => 'Down',
        'either' => 'Up or down',
    ];

    /** app_setting key prefix => the topic its settings are listed under. */
    public const SETTING_TOPIC = [
        'counts' => 'Stock counts',
        'po' => 'Purchase orders',
        'suppliers' => 'Suppliers',
        'reorder' => 'Reorder',
        'costs' => 'Costs',
        'company' => 'Company details',
        'receiving' => 'Deliveries',
        'site_writer' => 'Websites',
        'approvals' => 'Approval rules',
        'staff' => 'Staff',
        'mapping' => 'Matching products',
        'sales' => 'Sales and dispatch', // pack D (PD9)
        'items' => 'Items', // Pack B
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'Supplier invoices',
        'arrivals' => 'Goods on their way', // pack J (PJ5, PJ6, PJ12)
        // Pack F (PF10, PF11, PF3, PF7)
        'alerts' => 'Alerts',
        'housekeeping' => 'Clean-up',
        'relay' => 'Messages from ERPNext',
        // ---- Pack E
        'reports' => 'Reports',
        // ---- end Pack E
        // Pack I (0039).
        'erp_opening' => 'Opening stock from ERPNext',
        // Pack H (0038).
        'handovers' => 'Hand-overs',
        'duty' => 'Vaping duty',
    ];

    /** app_setting key => what the setting does, in words (the migration's description is the fallback). */
    public const SETTING_HELP = [
        // Stock counting (pack A2a, 0029; docs/decisions.md CT4, CT6, CT10).
        'counts.plan_days' => 'The count plan: every item should be counted at least once in this many days (1 to 730). Stock > Counts says how many '
            . 'items are inside the plan and how many are overdue, and lists what to count next.',
        'counts.sheet_shows_stock' => 'On: a count sheet, and its download, show what the system holds beside each item. Off: the person counting does '
            . 'not see it until they have entered a quantity (a blind count).',
        'counts.review_over_units' => 'After a count is made final, a difference of more than this many units, up or down, waits on Stock > Counts > '
            . 'Differences for a person to accept or reject it. 0: every difference waits.',
        'counts.review_over_percent' => 'After a count is made final, a difference of more than this percent of what the system held waits on Stock > '
            . 'Counts > Differences for a person to accept or reject it. 0: the percent is not used. Where the system held nothing, any difference waits.',
        'counts.time_window_max_minutes' => 'When a count sheet is made final, a line may be booked under the count time of a line counted a little '
            . 'earlier, when nothing moved in between: one record holds at most 20 count times. This is the most minutes a line\'s time may move back '
            . '(0 to 60). Up to 10, a parcel sent or brought back in that gap and reported late is flagged on Stock > Counts > Differences; above 10 '
            . 'it may not be. A sheet that needs more count times is refused, and the lines to count again are marked.',
        'po.terms' => 'The text printed under every purchase order.',
        'po.default_vat_code' => 'Used on a new order line when the supplier has no VAT code.',
        'po.over_delivery_tolerance_pct' => 'How much more than ordered goods in may still accept on a delivery.',
        'receiving.unstamped_refusal_from' => 'From this date (the day the delivery arrived) an e-liquid that needs a duty stamp and has none is refused, or held '
            . 'back as unstamped. Before it, unstamped stock is taken only with the supplier\'s proof that it was made or brought in before 1 Oct 2026.',
        'receiving.duty_pence_per_ml' => 'Vaping Products Duty per ml (£2.20 per 10 ml from 1 Oct 2026). A delivery shows the duty to expect, for information only.',
        'receiving.backdate_max_days' => 'How many days back a delivery may say the goods arrived, when it is booked in later from a paper sheet because the '
            . 'system was down. A reason is always needed.',
        'receiving.mode_after_out_of_stock' => 'The selling mode a delivery gives a product that was Out-Of-Stock (or had no mode) when its earlier mode is not '
            . 'known: In-Stock or From-Warehouse.',
        'site_writer.receipt_mode_sites' => 'The websites on which a booked-in delivery sets the product\'s selling mode (empty: none). Only for products whose '
            . 'stock is not counted yet: counted products follow their stock rule.',
        'costs.site_writeback' => 'Write the warehouse average cost into the websites\' cost field (not available yet).',
        'suppliers.approval_due_days' => 'A reviewer should decide a new supplier, or a change of its duty-stamp arrangement, within this many days.',
        'suppliers.change_review' => 'When the details of a supplier we order from change, a reviewer checks them afterwards. Orders are not stopped.',
        'reorder.default_lead_days' => 'Days from order to delivery, when neither the product, the supplier\'s product nor the supplier says.',
        'reorder.default_review_days' => 'Days until the next order to the same supplier, when the supplier does not say.',
        'reorder.default_safety_days' => 'Extra days of stock to keep (5, the same as now).',
        'reorder.short_window_days' => 'Reorder looks at the sales of this many recent days (4 weeks).',
        'reorder.long_window_days' => 'Reorder also looks at the sales of this many days (3 months).',
        'reorder.short_weight' => 'How much the recent days weigh in the mix (0.5 = half).',
        'reorder.min_valid_days_short' => 'With fewer usable days than this, the recent days are not used.',
        'reorder.min_valid_days_long' => 'With fewer usable days than this, the longer period is not used.',
        'reorder.spike_cap_multiple' => 'A day that sold more than this many times a normal day is lowered to that.',
        'reorder.spike_cap_floor' => 'A very high day is never lowered below this many items.',
        'reorder.promo_price_drop' => 'A brand\'s day is treated as a promotion when all three promotion checks are met. First: its price per item is this much below normal (0.10 = 10% lower).',
        'reorder.promo_units_uplift' => 'Second promotion check: it sold at least this many times its normal amount (1.5 = one and a half times).',
        'reorder.promo_min_units' => 'Third promotion check: the brand sold at least this many items that day.',
        'reorder.stale_history_days' => 'Warn when a website\'s loaded sales end more than this many days ago.',
        // --- Pack G
        'reorder.own_sales_stores' => 'For these stores, sales from the day after they connected come from CW\'s own record. Before that day, and for other stores, the loaded sales are used. Empty: loaded sales only.',
        'reorder.refresh_minutes' => 'Stock, orders and suggestions on Reorder are worked out again this often.',
        'reorder.refresh_chunk_items' => 'The list is worked out in steps of this many products, so no step holds the database long.',
        'reorder.refresh_inline_max_items' => 'After a click or a settings change, up to this many products are worked out at once. More wait for the next time.',
        'reorder.demand_hour' => 'From this hour, UK time, sales are worked out again once a day.',
        'reorder.fold_refold_days' => 'The last days of CW\'s own sales are read again each night, so a late cancellation is counted.',
        'reorder.fold_days_per_run' => 'One run reads at most this many days of CW\'s own sales. The next run goes on.',
        'reorder.low_stock_bulk_max' => 'One click on Low-stock warnings changes at most this many products.',
        'reorder.draft_skip_no_price' => 'When on, "Create draft orders" leaves out a product with no price yet and lists it.',
        // --- end of Pack G
        'company.confirmed' => 'Until the owner confirms the company details, every purchase order PDF says DO NOT SEND.',
        'approvals.supplier_activation' => 'A new supplier, a supplier used again, and an overseas supplier\'s duty-stamp arrangement wait for a reviewer\'s OK. '
            . 'Off: a buyer\'s request makes the supplier usable at once, marked "approved alone".',
        'approvals.match_multiple' => 'A match where 1 sale uses more or less than 1 warehouse product waits for a second matching lead.',
        'approvals.match_counted' => 'Joining (or undoing a join of) products whose stock was counted waits for a second matching lead.',
        'approvals.company_own_change' => 'A reviewer who confirms their own change of our name, numbers, purchasing e-mail or delivery address gets another '
            . 'reviewer\'s check afterwards.',
        'approvals.staff_grant' => 'Giving someone the Admin or Reviewer job, or a job given one of their powers on the Roles and permissions page, '
            . 'waits for a reviewer\'s OK. Off: the admin\'s change works at once.',
        'approvals.staff_reset' => 'A new sign-in code, password or sign-up sheet for someone with Admin or Reviewer, or with a job given one of their '
            . 'powers on the Roles and permissions page, waits for a reviewer\'s OK. Off: the admin makes it at once.',
        'staff.setup_max_fails' => 'After this many wrong tries, a sign-in set-up closes: the person cannot try again until the admin makes a new sheet, and the Dashboard says so.',
        'staff.sign_in_address' => 'The address staff open to sign in, for example https://warehouse.example.com. The sign-up sheets print it. Leave it empty until you know it.',
        'approvals.spot_check_size' => 'How many strong matches a spot check holds. A smaller spot check never confirms the rest together.',
        'approvals.mapping_bulk_second_ok' => 'Matches confirmed, and new products created, from a ticked list wait for a second matching lead.',
        'mapping.bulk_confirm_bands' => 'On these lists people may tick website products and confirm the suggested matches together. '
            . 'The other lists are decided one at a time. Untick all to allow none.',
        'mapping.bulk_max_rows' => 'The most website products one bulk action takes. A page shows 50. On By Item it is the most suggested variants '
            . 'of the ticked items.',
        'mapping.by_item_page_size' => 'How many items Products › By Item shows on a page when it opens: 50, 100 or 200. On the page itself anyone '
            . 'can show another number for a while.',
        'mapping.new_variants_days' => 'The New Variants page opens with the variants first seen in this many days (30: the last month). '
            . 'The page also offers 7, 30 and 90 days and any time.',
        'mapping.arrival_stores' => 'The check on arrival looks at the newly arrived variants of these stores (store codes, with commas between them). '
            . 'Empty: the check is switched off. It only suggests: a variant whose barcode, or whose web address copied from the main store, points to '
            . 'an item gets a suggestion for a person to confirm. No AI looks at it. It leaves every variant a person or another computer check has '
            . 'already dealt with.',
        'mapping.arrival_main_store' => 'The store the warehouse items were made from (one store code). A variant of another store whose web address '
            . 'was copied from one of its variants is suggested for that variant\'s item. Empty: no copied web address is used.',
        'mapping.arrival_bulk_confirm' => 'Off: a match found by the check on arrival has no tick box, and each is opened and decided on its own page. '
            . 'On: a match with nothing against it can be ticked and confirmed with others. One with a warning, a "not sure" and a "clues disagree" are '
            . 'always decided on their own page.',
        'mapping.arrival_max_per_run' => 'The most variants the check on arrival looks at in one run. The rest wait for the next run.',
        'mapping.arrival_max_seconds' => 'A run of the check on arrival stops after this many seconds and goes on at its next run.',
        'mapping.arrival_queue_alarm' => 'When more variants of one store than this wait for the check on arrival (a whole store was just loaded, '
            . 'say), the timer leaves that store alone until someone has run the check for it by hand (ask ' . self::ASK . ').',
        'mapping.arrival_state_max_days' => 'In days. The warehouse keeps what each website last said of a variant: whether it is on sale, and '
            . 'whether it is a page heading. "Create as new items" takes a variant only while that is known and not older than this. An older '
            . 'state reads as not known: such a variant is skipped until its store\'s list is loaded again, or a person opens it and decides.',
        'staff.setup_hours' => 'How long a new person, or one told to choose a new password, has to set up their sign-in (at most a week).',
        'staff.min_reviewers' => 'Users and the Dashboard warn when fewer people than this can approve work.',
        // ---- Pack D (docs/decisions.md PD9) ----
        'sales.page_size' => 'How many rows Stock › Sales shows on a page (Orders, Dispatch, Returns), and how many sold units of one order: 25, 50, 100 or 200.',
        'sales.default_days' => 'Store orders opens with the orders placed in this many days, today included (7: the last week). '
            . 'The page also offers any time and dates of your own.',
        'sales.late_hours' => 'A paid order that is not sent this many hours after payment is late on Dispatch (24: a day). At most 30 days.',
        'sales.csv_max_rows' => 'The most rows one spreadsheet of Stock › Sales holds. A spreadsheet that stops says so in its last row.',
        'sales.scan_max_rows' => 'Some lists of Stock › Sales cannot find their rows directly, for example orders with a unit returned that came in '
            . 'without a reservation. Such a page reads at most this many rows, then says it stopped and offers to read on.',
        // ---- end of pack D ----
        // Pack B
        'items.pack_suggest_single_sold' => 'On: Products › Multipacks suggests selling a pack item\'s variants as that many singles a sale only when the single item is '
            . 'sold on its own (a variant is matched to it with 1 unit a sale). Off: it suggests it for every pack item. A person always decides on the variant\'s page.',
        'items.family_bulk_max_rows' => 'The most items one change of a family takes: a pasted list, or the ticked items taken out.',
        'items.repack_carry_cost' => 'On: what a repack makes carries the average cost of what it took apart (a box of 10 at £12 gives singles at £1.20). '
            . 'Off: what it makes has no cost of its own and is valued at the average cost later.',
        'approvals.item_by_hand_check' => 'A second person checks each item made by hand, afterwards, on Products › Add Item. The item can be used at once.',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        // --- Pack J (0041)
        'arrivals.late_hours' => 'An invoice\'s goods that have not arrived this many hours after it was made final show as late on Goods In › Arriving.',
        'arrivals.sell_before_default' => 'On: the goods on a final invoice of a supplier without its own choice are sold on the websites before they arrive '
            . '(customers can order overnight; the orders go out when the goods land). Off: they are sold once received. Each supplier can choose on its page.',
        'arrivals.default_mode' => 'count: the goods are counted on arrival by Goods In (they wait in Arriving). received: they are booked as received the moment '
            . 'the invoice is final, without a count. For a supplier without its own choice; each invoice can still choose.',
        'invoices.check_price' => 'Off (as the owner chose on 15 Oct 2026): the price on the supplier\'s invoice is the right price. A line whose price differs from '
            . 'the order is still matched; the difference is shown on the invoice and recorded for the stock value. On: a price outside the two price tolerances '
            . 'below needs a person on "To match". The units are checked either way: billing more than arrived always needs a person.',
        'invoices.price_tolerance_pct' => 'A line whose price differs from the order (else the delivery) by at most this percentage still matches "within the tolerance". 0: this test is off.',
        'invoices.price_tolerance_gbp' => 'Or by at most this many pounds on the line. Either test is enough. With both at 0 the price must be exact.',
        'invoices.qty_tolerance_pct' => 'An invoice may bill this percentage more units than were received and not invoiced yet. 0: the units must be exact.',
        'invoices.total_tolerance_per_line' => 'How far, in pounds a line, the lines may be from the total the paper states (rounding). Beyond it the invoice cannot be made final.',
        'invoices.freight_spread' => 'value: in proportion to what each delivered line costs; ml: in proportion to its liquid. Recorded for the stock value.',
        'invoices.duty_spread' => 'ml: in proportion to the liquid of each delivered line; value: to what it costs. Recorded for the stock value.',
        'approvals.invoice_accept_other' => 'On: someone other than the person who made an invoice final accepts its differences. Off by default.',
        // Pack F (0036; docs/decisions.md PF3, PF7, PF10, PF11).
        'alerts.webhook_url' => 'An https address of a chat channel (its incoming address). Every alert is sent there. Empty: nothing is sent there. '
            . 'The screens show only its server; it must be a public server.',
        'alerts.email_to' => 'One e-mail address that gets every alert. Empty: nothing is e-mailed. The warehouse server must be able to send e-mail '
            . '(ask ' . self::ASK . ').',
        'alerts.repeat_minutes' => 'While a problem lasts, the same alert is sent again at most once in this many minutes. A problem that clears and '
            . 'comes back is sent at once.',
        'alerts.heartbeat_late_seconds' => 'Each store that is watching or live reports every minute. No report for this many seconds is an alert.',
        'alerts.outbox_max_depth' => 'More messages than this waiting on a store to reach the warehouse system is an alert.',
        'alerts.outbox_max_age_seconds' => 'A store\'s oldest waiting message older than this many seconds is an alert.',
        'alerts.dead_letters_max' => 'Messages a store gave up sending. More than this many is an alert (0: any is).',
        'alerts.feed_lag_max_seconds' => 'A store this many seconds or more behind the warehouse system\'s stock changes is an alert.',
        'alerts.answer_max_ms' => 'The warehouse system measures how long it takes to answer, on its own server. Above this many milliseconds is an '
            . 'alert. What a store sees at Place order is measured on the store.',
        'alerts.answer_probes' => 'How many times the answer time is measured in one run. The middle value counts.',
        'alerts.invariants_max_age_hours' => 'The nightly safety check: an alert when the last one found a problem, or when none finished for this '
            . 'many hours.',
        'alerts.send_timeout_seconds' => 'How long sending one alert to the web address may take before it counts as failed (it is tried again '
            . 'on the next run).',
        'housekeeping.idempotency_keep_days' => 'Each store call\'s answer is kept this many days, so a call sent again gets the same answer. 30 at '
            . 'the least: a store keeps its messages 14 days.',
        'housekeeping.channel_health_keep_days' => 'The stores\' reports are kept this many days. Each store\'s newest report is always kept.',
        'housekeeping.audit_keep_years' => 'The audit log is kept this many years, 7 at the least. Only a person removes older entries, never the '
            . 'nightly clean-up.',
        'housekeeping.chunk_rows' => 'The nightly clean-up removes old rows this many at a time, so the stores\' calls never wait long for it.',
        'housekeeping.max_seconds' => 'The nightly clean-up stops after this many seconds; the rest goes the next night.',
        'relay.correction_is_for_sale' => 'On: a figure set in ERPNext is what the website may sell, so the units kept for paid orders not sent '
            . 'yet are added to get the shelf figure. Off: the figure is the shelf figure.',
        'relay.mode_in_stock' => 'When ERPNext sets a counted, protected item to In-Stock, accepting the change gives it this stock rule.',
        'relay.mode_from_warehouse' => 'When ERPNext sets a counted, protected item to From-Warehouse, accepting the change gives it this stock rule.',
        'relay.mode_out_of_stock' => 'When ERPNext sets a counted, protected item to Out-Of-Stock, accepting the change gives it this stock rule.',
        // ---- Pack E
        'reports.low_stock_default' => 'In units. Low Stock lists a product when the stores can sell this many or fewer. A product with its own '
            . '"least stock to keep" on its reorder page uses that number instead.',
        'reports.slow_after_days' => 'Slow Stock lists a product in stock that has had no sale for this many days.',
        'reports.dead_after_days' => 'Slow Stock calls a product dead when it has had no sale for this many days. At least the slow-stock days.',
        'reports.sales_max_days' => 'The most days Sales by Store shows at once.',
        'reports.refresh_on' => 'Yes: every 15 minutes the reports read the new stock changes and orders. No: the reports keep the figures of the last run.',
        'reports.refresh_max_rows' => 'The most stock changes one run of the reports reads. A big backlog is read over several runs.',
        'reports.refresh_max_days' => 'The most days of store sales one run of the reports works out. The first run catches up over several runs.',
        // ---- end Pack E
        // ---- Pack I (0039, the ERPNext opening import; docs/decisions.md PI1-PI6) ----
        'erp_opening.warehouses' => 'The opening stock is taken from ERPNext\'s stock report. Only the rows of these ERPNext warehouses are read, '
            . 'one name per line, written as ERPNext writes them. Rows of other warehouses are left out and counted in the summary.',
        'erp_opening.companies' => 'When ERPNext\'s stock report has a company column, only the rows of these companies are read, one name per '
            . 'line. Empty: the company is not checked and the warehouse list alone decides.',
        'erp_opening.blocked_names' => 'A company or warehouse whose name contains one of these words is never read, whatever the two lists above '
            . 'say: another account\'s stock never becomes ours by mistake. Spaces and capitals do not matter. One per line.',
        'erp_opening.below_zero' => 'ERPNext shows some items below zero. "zero": such an item opens at nothing in the warehouse. "carry": the '
            . 'minus is carried into the warehouse as it is, so it is put right by the next delivery or a count. Either way the summary lists them.',
        'erp_opening.placeholder_costs' => 'Costs in ERPNext that only fill the field and are not what the item cost, in pounds, separated by '
            . 'commas. They are never used. An item whose only cost is one of them, or blank, opens without a cost and is listed to be costed.',
        'erp_opening.listing_store' => 'ERPNext items carry the variant number of one store. An ERPNext item becomes a warehouse item through '
            . 'that store\'s match. Items matched in the warehouse to an ERPNext item directly do not need it.',
        'erp_opening.cw_warehouse' => 'The warehouse the opening stock from ERPNext is put in, by its short code.',
        'erp_opening.require_pending_sales' => 'Website sales reach ERPNext through a queue, so some are not in ERPNext yet. On: the opening stock '
            . 'is only put in together with the list of those sales, which are taken off; an empty list says there are none. Off: it is put in '
            . 'without, and the summary says that correction is still to do.',
        // ---- end of pack I ----
        // Pack H (0038; docs/decisions.md PH6-PH17).
        'handovers.pdf_needs_confirmed_company' => 'On: the invoice PDF of a hand-over is not printed until a reviewer has confirmed the company details printed on it. '
            . 'Off: it prints, with "do not send" on it, as a purchase order does.',
        'handovers.invoice_terms' => 'The words printed under every hand-over invoice, such as payment terms. Empty: nothing is printed. The system '
            . 'never writes legal words by itself: ask your accountant what an invoice must say.',
        'handovers.price_fallback' => 'A new line gets the price the customer paid last. When there is none: "none" leaves it empty for the person to '
            . 'type; "cost" fills in the last supplier price, else the average cost so far.',
        'handovers.allow_overpayment' => 'Off: a payment received that is more than the customer owes is refused, so a zero too many is caught. On: it is '
            . 'recorded and the customer has a credit.',
        'handovers.price_import_max_rows' => 'The most rows one file of old trade-invoice prices may have (1 to 100,000).',
        'duty.register_lines' => '"stamp": the duty register lists the delivery lines that needed the duty stamp check. "all": every delivery line.',
        'duty.unstamped_warehouses' => 'The warehouses that hold unstamped stock, by their codes, separated by commas. The duty register shows what they '
            . 'hold. Empty: none.',
        'duty.unstamped_zero_from' => 'From this day the duty register shows an alarm while those warehouses hold anything. Holding unstamped vaping '
            . 'liquid is an offence from 1 Apr 2027.',
        'duty.page_rows' => 'How many lines of the duty register one page shows.',
        'duty.export_max_rows' => 'The most lines one download of the duty register holds (100 to 100,000), newest first.',
    ];

    /** Staff and access (/ui/people, /ui/people/{id}; plan §6.35, 6.36). */
    public const STAFF = [
        'add' => 'To add a staff member, use "Add a staff member" below. You then show them a QR code to scan with the code app on their phone.',
        'look_only' => 'You can look; only the Admin changes things.',
        'download' => 'Download as a spreadsheet (CSV, opens in Excel)',
        'name' => 'Name',
        'email' => 'E-mail',
        'jobs' => 'Jobs',
        'can_sign_in' => 'Can sign in',
        'last_signed_in' => 'Last signed in',
        'added_on' => 'Added on',
        'yes' => 'Yes',
        'no' => 'No',
        'never' => 'never',
        'you' => '(you)',
        'reviewers_one' => 'Only 1 person can approve work (Reviewer). You need at least %s, so approvals do not stop when someone is on holiday.',
        'reviewers_none' => 'Nobody can approve work (Reviewer). You need at least %s, so approvals do not stop when someone is on holiday.',
        'reviewers_admin' => 'A Reviewer who also has Admin cannot approve.',
        'no_admin' => 'Nobody is Admin, so nobody can change staff access here. Ask the developer to make someone Admin (not a Reviewer).',
        'test_account' => '%s is a test account and it can still sign in. Switch it off: open it and press "Stop this person signing in". '
            . 'A test account could be used to give a second OK to your own work.',
        'clash' => '%s has Admin, so these jobs do nothing: %s. Open their page, untick Admin and save.',
        'off_note' => '"(off)" after a job means Admin switches it off for that person.',
        'clash_person' => 'These jobs do not fit together: while Admin is ticked, %s do nothing. Untick Admin, then save. Their other jobs then work again.',
        'yours' => 'This is you. Nobody can change their own access. Ask another admin (if you are the only admin: ask the developer).',
        'auditor' => 'You can look but not change anything here (only the Admin can).',
        'code' => 'code: %s',
        'rule' => 'Admin can only go with %s, so the person who gives access never also approves work. '
            . 'A change works the next time the person opens a page. Every change is kept in the history below.',
        'save' => 'Save the jobs',
        'account' => 'Signing in',
        'stop' => 'Stop this person signing in',
        'stop_note' => 'They are signed out everywhere straight away. You can let them sign in again later.',
        // Behaviour item 4 (F441): a tick first, so one tap on a phone cannot sign a working person out.
        'stop_confirm' => 'Yes, sign them out now',
        'stop_unconfirmed' => 'Tick "Yes, sign them out now" first. Nothing was changed: the person can still sign in.',
        'allow' => 'Let this person sign in again',
        'allow_note' => 'They sign in with their own password and the code on their phone.',
        'test_off' => 'This is a test account. It stays switched off for safety. For a real person, ask the developer to create a proper account.',
        'history' => 'History of their jobs',
        'no_history' => 'This person was never given a job.',
        'job' => 'Job',
        'given' => 'Given on',
        'given_by' => 'Given by',
        'taken' => 'Taken away on',
        'taken_by' => 'Taken away by',
        'held_now' => 'still has it',
        'server' => 'set up on the server',
        'server_taken' => 'on the server',
        // Refusals, translated by code (the service messages stay the API's)
        'role_conflict' => 'Admin cannot go with %s. Nothing was saved. Untick Admin or %s, then save. (The person who gives access must not also do the work.)',
        'no_roles' => 'Tick at least one job. Nothing was saved. To stop someone using the system, use "Stop this person signing in" below.',
        'roles_changed' => 'Someone else changed this person\'s jobs while you had the page open. Nothing was saved. Their jobs now: %s. '
            . 'Your ticks are kept below. Check them and press Save again.',
        'own_account' => 'Nobody can change their own access. Nothing was changed. Ask another admin.',
        'placeholder' => 'A test account is never switched on or given a job here: it could be used to give a second OK to your own work. '
            . 'Nothing was changed. For a real person, ask the developer to create a proper account.',
        'not_admin' => 'Only the Admin changes staff access. Nothing was changed.',
        // The set-it-yourself pack (G06): adding people, their sign-in, their devices, requests for Admin or Reviewer
        'add_title' => 'Add a staff member',
        'add_text' => 'Fill in their name, e-mail and jobs. The next page shows their sign-up sheet once: a QR code and a set-up code. They use it once, '
            . 'then their own page gives them a code nobody else sees, and they choose their own password. No password is shown to you.',
        'add_name' => 'Their name',
        'add_email' => 'Their e-mail address',
        'add_jobs' => 'Their jobs',
        'add_button' => 'Add them and show their sign-up sheet',
        'devices' => 'Signed in now',
        'devices_text' => 'Each line is a phone or computer where someone is signed in.',
        'devices_none' => 'Nobody is signed in right now.',
        'person_devices_none' => 'This person is not signed in anywhere right now.',
        'device_person' => 'Person',
        'device_since' => 'Signed in',
        'device_seen' => 'Last active',
        'device_from' => 'From address',
        'sign_out_device' => 'Sign out this device',
        'sign_out_all' => 'Sign them out everywhere',
        'sign_out_all_note' => 'They can sign in again straight away with their password and code.',
        'setup_open' => 'Waiting to set up their sign-in until %s.',
        'setup_over' => 'Their time to set up the sign-in ran out on %s. Make a new sign-up sheet for them.',
        'new_code' => 'Make a new sign-in code',
        'new_code_text' => 'For a lost or new phone. Their old code stops at once and they are signed out everywhere. '
            . 'You then show them a new QR code. It works only together with their own password, once: then their own page gives them a code nobody else sees.',
        'new_code_confirm' => 'Yes, their old code stops now',
        'new_password' => 'Let them choose a new password',
        'new_password_text' => 'For a forgotten password. Their old password stops at once and they are signed out everywhere. '
            . 'You then show them a set-up code. On the "Set up my sign-in" page they use it with the code app on their own phone, and choose a new password.',
        'new_password_confirm' => 'Yes, their old password stops now',
        'reset_unconfirmed' => 'Tick the box first. Nothing was changed.',
        'request_waiting' => 'Waiting for a reviewer\'s OK: their jobs become %s. Asked by %s on %s.',
        'request_withdraw' => 'Withdraw this request',
        'requests_open' => 'Changes of staff access waiting for a reviewer\'s OK: %s.',
        'requests_link' => 'See them',
        'request_open' => 'A request about this person already waits for a reviewer. Nothing was saved. Withdraw it first.',
        'bad_name' => 'Type their name (2 to 128 characters). Nothing was saved.',
        'bad_email' => 'Type a real e-mail address. Nothing was saved.',
        'staff_exists' => 'Someone with this e-mail address is already set up. Nothing was saved. Open them in the list below.',
        'bad_session' => 'That device is not signed in any more. Nothing was changed.',
        // Who holds which sign-in factor (review finding B1, Y40-Y44): the person page's sign-in part.
        'set_up' => 'Set up their sign-in',
        'set_up_at' => '%s, from address %s',
        'set_up_not_yet' => 'not yet',
        'set_up_server' => 'on the server',
        'setup_not_done' => 'They have not set up their sign-in. Make a new sign-up sheet for them.',
        'setup_pending' => 'They are setting up their sign-in right now (a new code waits on their own page).',
        'setup_closed' => 'Their set-up closed on %s after too many wrong tries. If they did not make the tries, tell the owner. Make them a new sheet.',
        'password_open' => 'They can choose a new password with their set-up code until %s.',
        'code_waiting' => 'A new sign-in code waits: they sign in with it and their own password.',
        'new_sheet' => 'Make a new sign-up sheet',
        'new_sheet_text' => 'For someone who has not finished setting up: the sheet was lost, or their time ran out. The old sheet stops working.',
        'new_sheet_confirm' => 'Yes, the old sheet stops now',
        'code_blocked' => 'Not now: they can choose a new password with a set-up code until %s. You never hold both a set-up code and a sign-in code of anybody.',
        'password_blocked' => 'Not now: a new sign-in code waits for them. They sign in with it and their own password first.',
        'both_lost' => 'Lost both the phone and the password? That is done on the server: ask ' . self::ASK . '.',
        'reset_waiting' => 'Waiting for a reviewer\'s OK: %s. Asked by %s on %s.',
        'reset_ok' => 'A reviewer said OK to %s (%s, %s). Make it now: the OK works once.',
        // Refusals of the resets, by code (PeopleController::plain).
        'not_set_up' => 'They have not finished setting up yet. Nothing was changed. Make a new sign-up sheet instead.',
        'already_set_up' => 'They have set up their sign-in already. Nothing was changed. Make a new sign-in code, or let them choose a new password.',
        'code_refused_open' => 'Not done: they can still choose a new password with a set-up code. Nothing was changed. A new sign-in code waits until that time is over.',
        'code_reset_open' => 'Not done: a new sign-in code waits for them. Nothing was changed. They sign in with it and their own password first.',
        // The board of users (design v4): one group of people who can sign in, one of people switched off
        'board' => 'Staff accounts',
        'group_active' => 'Can sign in',
        'group_off' => 'Switched off',
        'count_one' => '1 user',
        'count_many' => '%s users',
        'add_row' => '+ New user',
    ];

    /** Notices after a click on a staff member's page (PeopleController::NOTICES). */
    public const STAFF_NOTICE = [
        'roles_saved' => 'Saved. The change works the next time the person opens or refreshes a page.',
        'roles_unchanged' => 'Nothing changed: the person already had exactly these jobs.',
        'deactivated' => 'Done: this person can no longer sign in. They were signed out everywhere.',
        'activated' => 'Done: this person can sign in again.',
        'requested' => 'Saved as a request: Admin and Reviewer need a reviewer\'s OK first. Nothing changes until a reviewer says OK.',
        'password_reset' => 'Done: their old password no longer works and they were signed out everywhere. Give them the set-up code: they choose a new password on the "Set up my sign-in" page.',
        'signed_out' => 'Done: they were signed out of that device.',
        'signed_out_all' => 'Done: they were signed out everywhere.',
        'not_signed_in' => 'Nothing changed: they were not signed in there any more.',
        'request_withdrawn' => 'Done: the request was withdrawn. Their jobs did not change.',
        'request_approved' => 'Done: you said OK. Their jobs changed.',
        'request_rejected' => 'Done: you said Not OK. Their jobs did not change.',
        'request_stale' => 'Nothing changed: their jobs had changed since the request was made, so the request was withdrawn.',
        'sheet_shown' => 'This form was sent already: its sheet was shown once and is not shown again. If it was lost, make a new one.',
        'reset_requested' => 'Saved as a request: a reviewer must say OK first. Nothing changed yet. Once they say OK, come back here and make it.',
        'reset_approved' => 'Done: you said OK. An admin can now make the reset, once.',
        'reset_rejected' => 'Done: you said Not OK. Their sign-in was not reset.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Matching pages (plan §6.9-6.12, 6.14-6.18): the lists, one website product, the second OK, the spot check, possible
    // duplicates, Find a product and the product page's matching and stock parts. Service messages are never edited: these
    // pages translate DecisionService's refusals by error code (MATCH_ERROR, DUP_ERROR).

    /** The identity fields of a product (DecisionService::CARD_FIELDS, Compare::LABELS). */
    public const FIELD = [
        'name' => 'Name',
        'brand' => 'Brand',
        'strength_mg' => 'Strength (mg)',
        'nic_type' => 'Nicotine type',
        'line' => 'Range',
        'form' => 'Kind of product',
        'flavour' => 'Flavour',
        'volume_ml' => 'Size (ml)',
        'puffs' => 'Puffs',
        'pack_units' => 'Pack size',
        'barcodes' => 'Barcodes',
    ];

    /** The kind of product (Matching\Form::ALL and the veto classes), as the matching reads it from a name. */
    public const FORM_VALUE = [
        'disposable' => 'Disposable',
        'prefilled_pod' => 'Prefilled pods',
        'pod_kit' => 'Pod kit',
        'refill_pod_cartridge' => 'Refill pods or cartridges',
        'e_liquid' => 'E-liquid',
        'nic_salt' => 'Nic salt e-liquid',
        'shortfill' => 'Shortfill',
        'nic_shot' => 'Nic shot',
        'coil' => 'Coil',
        'tank' => 'Tank',
        'kit' => 'Kit',
        'battery' => 'Battery',
        'accessory' => 'Accessory',
        'other' => 'Other',
        'pod_refill' => 'Refill pods',
        'device' => 'Device',
        'liquid' => 'E-liquid',
        'unknown' => 'not stated',
        'prefilled' => 'prefilled',
        'refillable' => 'refillable',
    ];

    /** The nicotine type (Matching\Normalizer). */
    public const NIC_TYPE = [
        'salt' => 'Nic salt',
        'nic_salt' => 'Nic salt',
        'freebase' => 'Freebase',
        'zero' => 'No nicotine',
        'shortfill' => 'Shortfill (no nicotine)',
        'nic_shot' => 'Nic shot',
    ];

    /** A field the AI compared (match_proposal evidence `fields_not_agree`), as a word in a sentence. */
    public const AI_FIELD = [
        'brand' => 'brand',
        'brand_line' => 'brand and range',
        'line' => 'range',
        'strength' => 'strength',
        'nic_type' => 'nicotine type',
        'flavour' => 'flavour',
        'form' => 'kind of product',
        'volume' => 'size (ml)',
        'pack' => 'pack size',
        'puffs' => 'number of puffs',
        'colour' => 'colour',
        'resistance' => 'coil (ohm)',
        'ohm' => 'coil (ohm)',
        'line_number' => 'model number',
        'model' => 'model number',
    ];

    /** How the AI's view of one field reads ("Watch out: the brand is written differently"). %s is AI_FIELD. */
    public const AI_FIELD_STATE = [
        'conflict' => 'the %s does not match',
        'alike' => 'the %s is written differently',
        'unknown' => 'the %s is not stated on both',
        'differs' => 'the %s does not match',
        'missing' => 'the %s is not stated on both',
        'none' => 'the %s needs a look',
    ];

    /** An action as something done ("matched by Aisha"), for histories (DecisionService::ACTIONS). */
    public const ACTION_DONE = [
        'link' => 'matched',
        'unlink' => 'match undone',
        'new_item' => 'created and matched',
        'ignore' => 'marked as ignored',
        'reject' => 'marked as the wrong product',
        'suggest' => 'suggested',
        'merge_skus' => 'joined from a duplicate',
        'split' => 'join undone',
        'unignore' => 'taken off the ignored list',
        'send_back' => 'sent back for matching',
        'seed' => 'made from this website product',
    ];

    /** How a stock change came about (stock_ledger.movement_type). */
    public const MOVEMENT = [
        'reserve' => 'Reserved in a checkout',
        'release' => 'Reservation freed',
        'expire' => 'Reservation ran out',
        'commit' => 'Order paid',
        'commit_release' => 'Order paid (reservation freed)',
        'adopt' => 'Product linked later: the order now uses warehouse stock',
        'uncancel' => 'Order brought back',
        'cancel' => 'Order cancelled',
        'ship' => 'Sent to the customer',
        'unship' => 'Sending undone',
        'return' => 'Customer return',
        'goods_in' => 'Goods in',
        'supplier_return' => 'Return to a supplier',
        'erp_sale' => 'Sale booked in ERPNext',
        'adjustment' => 'Stock correction',
        'count' => 'Shelf count',
        'write_off' => 'Write-off',
        'transfer_out' => 'Moved to another warehouse',
        'transfer_in' => 'Moved from another warehouse',
        'trade_sale' => 'Trade sale',
        'stock_out' => 'Stock out',
        'opening' => 'Opening stock',
        'merge_in' => 'Joined from a duplicate',
        'merge_out' => 'Moved to the product it was joined into',
        'split_in' => 'Join undone: came back',
        'split_out' => 'Join undone: moved back',
        // pack J (PJ1): a supplier invoice's units on their way, and the receipt that takes them in.
        'incoming' => 'On its way (supplier invoice final)',
        'arrive' => 'Arrived (received against its invoice)',
    ];

    /** How a warehouse product was made (sku.origin). */
    public const ORIGIN = [
        'vpg_mint' => 'Made from a Vape and Go product',
        'new_item' => 'Made from a website product ("create a new product")',
        'manual' => 'Made by hand',
    ];

    /** The lists of website products to match (/ui/review?queue=…, plan §6.15). */
    public const QUEUE = [
        'lists' => 'Lists',
        'renamed_note' => 'The two websites use different names for the same range (Electrofag "Crystal Pro Max" = Vape and Go "Hayati Pro Max"). '
            . 'Open each one and press "Use this product" on the matching flavour, or create a new product, or ignore it.',
        'lead_only' => 'Only a matching lead can decide these. You can look.',
        'website' => 'Website',
        'any_website' => 'All websites',
        'found_by' => 'Found by',
        'any' => 'Any',
        'min' => 'Sold in the last 30 days, at least',
        'text' => 'Name, brand, barcode or option number',
        'show' => 'Show',
        'total_one' => '1 to decide, best sellers first.',
        'total_many' => '%s to decide, best sellers first.',
        // The board (design v4)
        'board' => 'Matches to review',
        'board_note' => 'Grouped by how sure the match is · best sellers first',
        'strength' => 'Suggested matches by how sure: press one to open its list.',
        'in_all' => '%s in all · %s shown',
        'product' => 'Website product',
        'sold_365' => 'Sold (1 year)',
        'sold_30' => 'Sold (30 days)',
        'suggested' => 'Suggested product',
        'ai' => 'AI says',
        'watch' => 'Watch out',
        'open' => 'Open',
        'look' => 'Look',
        'new_product' => 'Create a new product',
        'none' => 'None',
        'option' => 'option %s',
        'sure' => '%s (%s%% sure)',
        'same_barcode' => 'Same barcode',
        'empty_filter' => 'No website product matches your filters.',
        'empty_filter_text' => 'Others may be waiting in this list.',
        'clear' => 'Clear filters',
        'empty' => 'This list is empty.',
        'empty_text' => 'Every website product in it has an answer.',
        'next_list' => 'Next: %s (%s)',
        'all_done' => 'Nothing is left to match in any list.',
        'previous' => 'Previous',
        'next' => 'Next',
        'page' => 'Page %s of %s',
        // The notice above a list whose page holds rows with no tick box (docs/decisions.md U141): "47 on this page are held back and 3
        // are in a spot check: they are decided one at a time and have no tick box. Show only the ones a bulk action can take (863)"
        'no_box_held_one' => '1 on this page is held back',
        'no_box_held_many' => '%s on this page are held back',
        'no_box_spot_one' => '1 on this page is in a spot check',
        'no_box_spot_many' => '%s on this page are in a spot check',
        'no_box_and_spot_one' => '1 is in a spot check',
        'no_box_and_spot_many' => '%s are in a spot check',
        // (U144) the rows found by the check on arrival that are decided one at a time: first in the sentence, or after another kind
        'no_box_arrival_one' => '1 on this page was found by the check on arrival',
        'no_box_arrival_many' => '%s on this page were found by the check on arrival',
        'no_box_and_arrival_one' => '1 was found by the check on arrival',
        'no_box_and_arrival_many' => '%s were found by the check on arrival',
        'no_box_both' => '%s and %s',
        'no_box_three' => '%s, %s and %s',
        'no_box_one' => '%s: it is decided on its own and has no tick box.',
        'no_box_many' => '%s: they are decided one at a time and have no tick box.',
        'show_ready' => 'Show only the ones a bulk action can take (%s)',
        'none_ready' => 'Nothing in this list is ready for a bulk action.',
    ];

    /**
     * What a match-strength list shows (Queries::SHOWS; docs/decisions.md U140, U144): the "Show" filter, each with how many it holds.
     * "Ready for a bulk action" is every row that is neither held back, nor in a spot check, nor one of the check on arrival's that
     * are decided one at a time ("Found on arrival").
     */
    public const QUEUE_SHOW = [
        'all' => 'All',
        'ready' => 'Ready for a bulk action',
        'held' => 'Held back',
        'spot' => 'In a spot check',
        'arrival' => 'Found on arrival',
    ];

    /**
     * A row no bulk action takes (CW\Mapping\OneAtATime; docs/decisions.md M63, U139), under its name on the lists whose rows are
     * variants (To review, Store Products, New Variants): what it is, and what to do. Held back: when and by whom it was set aside
     * (%s the day, %s the name), and that its own page says why and is where it is decided. The hold's note itself is NOT printed
     * here: the stored notes are long technical rule texts (a build decision of 12 Oct 2026, not yet put to the owner); the row's own page shows it in
     * full (LISTING `held`). A match of a spot check: the spot check's name. Found by the check on arrival (M64, U144): that no AI
     * has looked at it, and what to do. The same words on the three screens, so they name neither "product" nor "variant".
     */
    public const ONE_AT_A_TIME = [
        'held' => 'Held back on %s (set aside by %s). Open it to see why and to decide it.',
        'in_spot_check' => 'In spot check %s: it is answered in the spot check.',
        'on_arrival' => 'Found by the check on arrival: no AI has looked at it. Open it to check and decide.',
        'open_spot' => 'Open the spot check',
    ];

    /** One website product (/ui/review/listing/{id}, plan §6.16). */
    public const LISTING = [
        'back' => 'Back',
        'skip' => 'Skip to the next one',
        'no_title' => '(no name)',
        'on_website' => 'On the website',
        'website' => 'Website',
        'option' => 'Option no.',
        'brand' => 'Brand',
        'price' => 'Price',
        'sold' => 'Sold',
        'sold_line' => '%s in 30 days, %s in a year',
        'page' => 'Page',
        'open_page' => 'Open it on the website',
        'barcodes' => 'Barcodes',
        'none' => 'none',
        'matched_to' => 'Matched to',
        // The product on the right
        'suggested' => 'Suggested warehouse product',
        'picked' => 'Product you picked',
        'pending_target' => 'Product this decision matches',
        'new_by_decision' => 'New product this decision creates',
        'new_by_decision_text' => 'Approving creates this new product and matches the website product to it (%s).',
        'decision_does' => 'What this decision does',
        'stock_rule' => 'Stock rule',
        'protected' => 'a second OK is needed to match it',
        'pick_missing' => 'That warehouse product does not exist. The suggested one is shown again.',
        'pick_merged' => 'That warehouse product was joined into another one. Search for the one it was joined into.',
        'previously_rejected' => 'Someone said before that this website product is not this warehouse product (or one joined into it), so matching it needs a second OK.',
        'suggest_new' => 'Suggested: create a new product.',
        'suggest_new_text' => 'The details are filled in from this website product (see "Details of the new product" below). Correct them before you save.',
        'no_suggestion' => 'No product suggested. Pick one from "Other products the AI looked at", search for it, or create a new product.',
        'pick_other' => 'Pick a different product',
        'elsewhere' => 'This website product\'s barcode is also on',
        'elsewhere_item' => 'Warehouse product',
        'elsewhere_listing' => '%s website product',
        'elsewhere_matched' => 'matched to %s',
        'elsewhere_site' => 'on the website: %s',
        'elsewhere_note' => 'A new product for this one may be a duplicate of one of these: check them before you create it.',
        'dup_link' => 'Possible duplicate: see',
        'group' => 'group %s',
        // Why the computer suggests this
        'why' => 'Why the computer suggests this',
        'how_sure' => 'How sure',
        'barcode' => 'Barcode',
        'barcode_same' => 'the same on both',
        'barcode_differs' => 'different on the two',
        'barcode_unknown' => 'not on both, so it cannot help',
        'ai' => 'AI',
        'ai_sure' => '%s, %s%% sure',
        'here' => 'Why it is here',
        'watch' => 'Watch out',
        'cannot' => 'Cannot be matched to %s: %s',
        'cannot_ai' => 'Cannot be the AI\'s pick: %s',
        'renamed' => 'Renamed range',
        'renamed_text' => 'Pick the product with the same flavour below.',
        'partners' => 'Possible matches',
        'ai_pick' => 'AI picked',
        'ai_pick_same' => '(the product shown above)',
        'closest' => 'Closest product',
        'use' => 'Use this product',
        'ai_reason' => 'What the AI says',
        'ai_refs' => 'C1, C2 … are the rows of "Other products the AI looked at" below.',
        'not_in_cw' => 'not in the warehouse list',
        // The answers
        'question' => 'Is this the same product?',
        'instead' => 'What is it instead?',
        'yes' => 'Yes, same product: match to %s',
        'yes_none' => 'Yes, same product (pick a product first)',
        'new_item' => 'Not in the warehouse list: create a new product',
        'ignore' => 'Ignore: not a real product (gift card, bundle, placeholder). Say why below.',
        'reject' => 'No, wrong product (it stays in the list for another choice)',
        'reject_none' => 'No, wrong product (nothing is suggested)',
        'units' => 'How many does 1 sale of this website product use?',
        'units_hint' => '1 for a single, 10 for a 10-pack. If it is not 1, a matching lead must also give a second OK.',
        'protected_note' => 'The website already sells warehouse stock for this product, so a matching lead must give a second OK to match it.',
        'note' => 'Note',
        'note_hint' => '(needed for Ignore)',
        'new_details' => 'Details of the new product',
        'new_details_hint' => 'Only used when you create a new product. Filled in from the website product: correct what is wrong.',
        'not_stated' => 'not stated',
        'save_next' => 'Save and open the next one',
        'save' => 'Save',
        'quick' => 'Yes, same product: match and open next',
        'quick_last' => 'Yes, same product: match it',
        'quick_note' => 'Not sure? Check the details below.',
        'skip_answer' => 'Not sure: skip it',
        'skip_back' => 'Leave it and go back to %s',
        'does_yes' => 'It is matched to %s, and its sales are added to that warehouse product.',
        'does_yes_none' => 'Pick a warehouse product first: search for it, or use one the AI looked at.',
        'does_new' => 'A new warehouse product is made from "Details of the new product", and the website product is matched to it.',
        'does_ignore' => 'It is marked as not a real product and leaves the lists. Nothing is matched.',
        'does_reject' => 'Nothing is matched. The suggestion is marked wrong, and the website product stays in the list for another choice.',
        'does_skip' => 'Nothing is saved. It stays in the list, and you can come back to it.',
        'does_second' => 'If an answer needs a second OK (1 sale is not 1 product, the website already sells warehouse stock, or it was marked wrong before), it waits until a matching lead says yes.',
        'go_to_field' => 'Go to it',
        // Who cannot decide here, and why
        'look_only' => 'You can look but not decide. Matchers and Matching leads decide.',
        'lead_only' => 'Only a matching lead can decide this one (the clues disagree).',
        'wait_own' => 'You saved this. It waits for a matching lead\'s second OK. You can cancel it.',
        'wait_lead' => 'Check the details above, then approve it (it goes live) or cancel it (take it back).',
        'wait_other' => 'It waits for a matching lead\'s second OK.',
        // Behaviour item 13 (F184): an already matched website product has nothing to decide.
        'matched_nothing' => 'Matched to %s (%s). Nothing to decide here.',
        'matched_ask' => 'Wrong match? Ask a matching lead to change it.',
        'matched_lead' => 'Wrong match? You are a matching lead: open "Change this match" below.',
        'matched_heading' => 'Warehouse product it is matched to',
        'change_match' => 'Change this match',
        'unlink' => 'Undo the match: it goes back to its list (stock stops following this match). Say why below.',
        'change_match_text' => 'Only if the match is wrong. Pick the right product above ("Pick a different product"), create a new product, or ignore it. '
            . 'Changing a match that already sells warehouse stock, or where 1 sale is not 1 product, needs a second OK.',
        // Held back (set aside) from the bulk step
        'held' => 'Check this one by hand: "%s" (set aside by %s on %s). It will not be confirmed together with the rest.',
        'held_decided' => 'It was decided since; the note stays on record.',
        'held_newer' => 'The note is on this website product, so it covers its newer suggestion too.',
        // The comparison
        'compare' => 'What is the same and what differs',
        'compare_what' => 'What',
        'compare_website' => 'Website product',
        'compare_result' => 'Result',
        'new_compare' => 'What the website product says',
        // Other products the AI looked at
        'candidates' => 'Other products the AI looked at',
        'no' => 'No.',
        'product' => 'Product',
        'score' => 'Score',
        'problems' => 'Problems',
        'tag_ai' => 'AI picked',
        'tag_suggested' => 'Suggested',
        'cannot_be' => 'Cannot be this: %s',
        // Technical details (codes kept for the team)
        'tech' => 'Technical details',
        'tech_band' => 'List',
        'tech_run_band' => 'The computer check said',
        'tech_lane' => 'Found by',
        'tech_reasons' => 'Why it is here',
        'tech_flags' => 'Flags',
        'tech_warnings' => 'AI warnings',
        'tech_fields' => 'Fields the AI compared',
        'tech_run' => 'Computer check',
        'tech_cwp' => 'Number in the matching files',
        'tech_ids' => 'Record numbers',
        'tech_ids_text' => 'website product %s, suggestion %s',
        'tech_role' => 'Found as',
        // History
        'history' => 'History',
        'h_computer' => 'Computer',
        'h_suggest' => 'The computer suggested %s.',
        'h_suggest_none' => 'The computer made a suggestion.',
        'h_note' => 'Note: %s',
        // The waiting box
        'waiting' => 'Waiting for a second OK',
        'decided_by' => 'Decided by %s on %s.',
        'why_second' => 'Why a second OK',
        'stale_version' => 'This website product changed since this was decided (it was matched again, or the website changed its name, brand or barcodes).',
        'stale_proposal' => 'This website product has a newer suggestion since this was decided.',
        'stale_fix' => 'Cancel it and decide again.',
    ];

    /** Waiting for a second OK (/ui/review?queue=pending, plan §6.14) and the waiting box of a website product. */
    public const PENDING = [
        'none' => 'Nothing needs a second OK right now.',
        'none_text' => 'When a match needs a second matching lead, it shows here.',
        'product' => 'Website product',
        'does' => 'What it does',
        'why' => 'Why a second OK',
        'by' => 'Decided by',
        'approve' => 'Approve: it goes live',
        'cancel' => 'Cancel this decision',
        'cancel_named' => 'Cancel %s\'s decision',
        'cancel_does' => 'Cancel takes the decision back; the website product returns to its list.',
        'note' => 'Note (optional)',
        'note_label' => 'Note for the approval',
        'wait' => 'Waiting for a matching lead\'s second OK.',
        // What the decision does
        'match' => 'Match to',
        'create' => 'Create a new product',
        'create_named' => 'Create a new product "%s"',
        'details' => 'Details:',
        'join' => 'Join products:',
        'joins' => 'joins',
        'from_dups' => '(from Duplicates)',
        'undo_join' => 'Undo the join:',
        'back_to' => 'back to',
        'with_stock' => 'with the stock that came with it',
        'own_new' => 'it gets its own new product',
        'unlink' => 'Undo the match',
        'ignore' => 'Mark it as ignored',
        'reject' => 'No, wrong product:',
        'note_line' => 'Note: %s',
        'on' => '%s, %s',
    ];

    /** Notices after a decision on a website product (ReviewController::NOTICES). %s is the product's name in quotes. */
    public const MATCH_NOTICE = [
        'decided_link' => 'Done: %s is matched to %s.',
        'decided_link_plain' => 'Done: %s is matched.',
        'decided_new_item' => 'Done: a new product was created for %s and matched to it.',
        'decided_ignore' => 'Done: %s is marked as ignored.',
        'decided_reject' => 'Marked "not this product": %s. It stays in the list for another choice.',
        'pending_second' => 'Saved, not live yet: %s needs a matching lead\'s second OK.',
        'approved' => 'Approved: the decision on %s now takes effect.',
        'withdrawn' => 'Cancelled: the decision on %s. It is back in its list.',
        'queue_done' => 'This list is done.',
        // After the last row of a list that is narrowed (what it shows, found by, units sold, a search): the list itself may hold more.
        'shown_done' => 'That was the last one of what this list is showing.',
        'decided_unlink' => 'Done: the match of %s is undone. It is back in its list.',
        'next' => 'Here is the next one.',
        'the_product' => 'the website product',
    ];

    /**
     * A refusal on the matching pages, by error code (DecisionService's codes, whose messages are the API's, and the page's own).
     * `*_settle`: the same code when approving or cancelling a waiting decision.
     */
    public const MATCH_ERROR = [
        'map_version_conflict' => 'Not saved: this website product changed while you were looking (renamed on the website, or someone else decided it). '
            . 'The page now shows it as it is. Check it and save again.',
        'map_version_conflict_settle' => 'Not approved: the website product changed since this decision was made. Cancel the decision and decide again.',
        'proposal_changed' => 'Not saved: a newer suggestion arrived while you were looking. The page now shows it. Check it and save again.',
        'proposal_changed_settle' => 'Not approved: the website product has a newer suggestion since this decision was made. Cancel the decision and decide again.',
        'proposal_closed' => 'Not saved: this suggestion was replaced or decided meanwhile. Check the page and decide again.',
        'proposal_mismatch' => 'Not saved: the form does not fit this website product. Reload the page and try again.',
        'lead_required' => 'Only a matching lead can decide when the clues disagree. Nothing was saved.',
        'lead_required_approve' => 'Only a matching lead can give the second OK. Nothing was changed.',
        'lead_required_withdraw' => 'Only the person who decided it, or a matching lead, can cancel this decision. Nothing was changed.',
        'same_person' => 'You made this decision, so another matching lead must give the second OK. Nothing was changed.',
        'not_pending' => 'This decision is not waiting any more: someone approved or cancelled it meanwhile. Nothing was changed.',
        'pending_second_exists' => 'Not saved: a decision on this website product already waits for a second OK. Approve or cancel that one first.',
        'already_linked' => 'Not saved: this website product is already matched. To change it, ask a matching lead.',
        'no_change' => 'Nothing to change: it is already like that.',
        'name_required' => 'Not saved: the new product needs a name. Type one in "Details of the new product".',
        'bad_card' => 'Not saved: "%s" in "Details of the new product" is not right.',
        'bad_card_number' => 'Not saved: "%s" must be a number, like %s.',
        'unknown_sku' => 'Not saved: that warehouse product does not exist. Pick another one.',
        'sku_merged' => 'Not saved: that warehouse product was joined into another one. Search for the one it was joined into.',
        'reject_current_link' => 'Not saved: it is matched to this product now. To undo a match, ask a matching lead.',
        'no_open_proposal' => 'Not saved: nothing is suggested for this website product now.',
        'bad_units' => 'Not saved: "How many does 1 sale use?" must be a whole number from 1 to %s.',
        'not_allowed' => 'You can look at this, but only Matchers and Matching leads can match products. Nothing was changed.',
        'unknown_decision' => 'We cannot find this decision. It may be closed already. Nothing was changed.',
        'other' => '%s Nothing was saved.',
        // The page's own checks, before anything is sent
        'choose' => 'Choose an answer first. Nothing was saved.',
        'reason_long' => 'The note is at most %s characters. Nothing was saved.',
        'reason_text' => 'The note has characters this system cannot read. Type it again. Nothing was saved.',
        'pick_first' => 'Pick the warehouse product first: search for it, or use one the AI looked at. Nothing was saved.',
        'nothing_to_reject' => 'Nothing is suggested, so there is nothing to say no to. Nothing was saved.',
        'ignore_why' => 'Write a few words to say why you ignore it. Nothing was saved.',
        'unlink_why' => 'Write a few words to say why you undo this match. Nothing was saved.',
        'not_ignored' => 'Not saved: this website product is not on the ignored list.',
        'not_waiting' => 'Not saved: this website product is ignored. Take it off the ignored list first.',
        'units' => '"How many does 1 sale use?" must be a whole number from 1 to %s. Nothing was saved.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Store-wise review and bulk action on the matching screens (docs/decisions.md M46-M53, U106-U112)

    /** The store selector, the action bar and the batch page. */
    public const BULK = [
        'stores' => 'Store',
        'all_stores' => 'All stores',
        'store_count_review' => 'to review',
        'store_count_pending' => 'waiting for a second OK',
        'store_count_samples' => 'spot check matches to answer',
        'store_count_products' => 'website products',
        'bar' => 'Ticked products',
        'select_all' => 'Select all on this page',
        'pick_row' => 'Tick %s',
        'selected_none' => 'Tick products, then choose what to do with them.',
        'selected_one' => '1 selected',
        'selected_many' => '%s selected',
        'too_many' => '%s selected: at most %s at a time.',
        'clear' => 'Clear the ticks',
        'title' => 'Bulk action %s',
        'summary' => 'Done: %s, %s sent to Second approval, %s skipped.',
        'pending_text' => 'They wait for a second matching lead. Nothing changes until one says yes.',
        'skipped_text' => 'Skipped products were not changed. Each one says why: open it to decide it by hand.',
        'missing' => '%s of the %s ticked have no result: the action stopped early. Check them in the list.',
        'what' => 'What',
        'who' => 'Who',
        'where' => 'Where',
        'note' => 'Note',
        'ticked' => 'Products ticked',
        'skipped_title' => 'Skipped',
        'count' => '%s',
        'why' => 'Why',
        'all_rows' => 'Every product of this action',
        'result' => 'Result',
        'now' => 'Now',
        'no_undo' => 'There is no undo for the whole action: stock may have moved since. To undo one match, open it: a matching lead uses "Change this match".',
        'back_to' => 'Back to %s',
        'from_batch' => 'From bulk action %s',
        'state_unknown' => 'unknown',
        'an_item' => 'an item you already have',
    ];

    /** The bulk actions, as their buttons say them (a "…" means a second step follows). */
    public const BULK_ACTION = [
        'link' => 'Confirm match',
        'reject' => 'Not a match…',
        'new_item' => 'Create as new product',
        'ignore' => 'Ignore…',
        'unignore' => 'Take off the ignored list',
        'send_back' => 'Send back for matching…',
    ];

    /** The "done" part of a batch's summary, by action (%s = how many). */
    public const BULK_DONE = [
        'link' => '%s matched',
        'reject' => '%s marked not a match',
        'new_item' => '%s new products created and matched',
        'ignore' => '%s ignored',
        'unignore' => '%s back in their lists',
        'send_back' => '%s sent back for matching',
    ];

    /** A ticked product's result on the batch page. */
    public const BULK_RESULT = [
        'done' => 'Done',
        'pending_second' => 'Sent to Second approval',
        'skipped' => 'Skipped',
    ];

    /** Why a ticked product was skipped (CW\Mapping\BulkDecisions::SKIPS; %s filled from the record). Nothing was changed on it. */
    public const BULK_SKIP = [
        'unknown_listing' => 'This website product no longer exists.',
        'other_store' => 'It belongs to another store than the list you were on.',
        'changed' => 'It changed after you opened the page (someone decided it, or the website renamed it). It is now: %s.',
        'suggestion_changed' => 'Its suggestion changed after you opened the page. Open it to see the new one.',
        'pending_second' => 'A decision on it already waits for a second OK.',
        'held' => 'Held back to be checked one at a time: %s',
        'held_meanwhile' => 'It was held back to be checked one at a time while this ran.',
        'in_spot_check' => 'It is one of the matches of spot check %s: only that spot check\'s owner answers it, one at a time.',
        'on_arrival' => 'Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.',
        'spot_check_failed' => 'Spot check %s found a wrong match, so these strong matches are checked one at a time.',
        'bulk_undone' => 'A bulk match of it was undone before, so it is checked one at a time.',
        'band_not_allowed' => 'Its match strength (%s) is not one that may be confirmed together. Open it to decide it.',
        'vetoed' => 'A rule says it cannot be the suggested product: %s.',
        'flagged' => 'It needs a person\'s look: %s.',
        'no_product' => 'Nothing is suggested for it, so there is nothing to confirm or say no to.',
        'barcode_elsewhere' => 'Its barcode is already on another product. Open it to check before you create one.',
        'already_rejected' => 'It was already marked "not this product".',
        'not_ignored' => 'It is not on the ignored list (it is: %s).',
        'already_ignored' => 'It is already ignored.',
        'linked' => 'It is matched. Change it on its own page: that changes a stock link.',
        'not_waiting' => 'It is not waiting for a match (it is: %s).',
        'no_suggestion' => 'Nothing is suggested for it now.',
        'lead_only' => 'Only a matching lead decides it (the clues disagree).',
        'not_allowed' => 'Your jobs do not let you change it.',
        'product_joined' => 'The suggested product was joined into another one. Open it to pick the right one.',
        'no_name' => 'It has no name, so a new product cannot be made from it. Open it and type one.',
        'refused' => 'Not changed: %s',
        // By Item's confirm only (M59; BY_ITEM_SKIP says them in items and variants)
        'rejected_before' => 'It was marked "not this product" for this warehouse product before: open it and decide.',
        'item_on_store' => '%s already has a website product on %s: open it and decide.',
        'several_suggested' => '%s has more than one suggested website product on %s: open them and decide which is right.',
        // A bulk "create as new" (M61) takes only what the computer checked and found new, and of that not what is doubtful. The
        // others, each with why (the first three on New Variants only; the rest on every list that offers it):
        'suggested_match' => 'The computer suggests it is %s: open it and decide.',
        'not_checked' => 'Nothing is suggested for this variant. Open it and decide.',
        'not_new' => 'The computer could not say what it is (%s): open it and decide.',
        'page_heading' => 'It is a page heading on the website, not something that is sold. Open it and decide.',
        'not_published' => 'It is not published on the website (%s). Open it and decide.',
        // (M66, U146) nothing says whether it is on sale, or the last word on it is too old: one sentence for both
        'state_not_known' => 'The warehouse does not know if it is on sale on the website now. Open it and decide.',
        'made_before' => 'An item was already made from this variant (%s): open it and decide.',
        'changed_since_check' => 'It changed on the website after the computer checked it: open it and decide.',
        'name_elsewhere' => 'An item with the same name is already in the warehouse (%s): open it and decide.',
        'name_twice' => 'Another row you ticked has the same name, so the two may be one item: open each and decide.',
        'match_no_item' => 'The computer matched it to a variant of the main store that has no item yet: open it and decide.',
    ];

    /** A bulk request refused as a whole (nothing was saved). */
    public const BULK_ERROR = [
        'nothing_ticked' => 'Tick at least one product first. Nothing was saved.',
        'too_many_rows' => 'You ticked %s products. At most %s can be changed at a time (the Settings page says how many). Nothing was saved.',
        'band_not_allowed' => '%s cannot be confirmed together: open each one to decide it. The Settings page lists the strengths that can. Nothing was saved.',
        'action_not_here' => 'This list does not offer that action. Nothing was saved.',
        'reason_required' => 'Write a few words to say why you ignore them. Nothing was saved.',
        'reason_text' => 'The note has characters this system cannot read. Type it again. Nothing was saved.',
        'lead_required' => 'Only a matching lead decides products where the clues disagree. Nothing was saved.',
        'role_not_allowed' => 'You can look at these, but only Matchers and Matching leads can change them. Nothing was saved.',
        'staff_required' => 'Only a person can do this. Nothing was saved.',
        'bad_action' => 'This list does not offer that action. Nothing was saved.',
        'bad_request' => 'This form is incomplete. Nothing was saved. Reload the page and try again.',
        'unknown_batch' => 'We cannot find this bulk action. The link may be old.',
    ];

    /** The second step of a bulk action that says no (stating the count). */
    public const BULK_CONFIRM = [
        'reject_title' => 'Mark %s products as "not a match"?',
        'reject_text' => 'Each suggestion is marked wrong. Nothing is matched, and each product stays in its list for another choice. '
            . 'Matching one to that product later needs a second OK.',
        'reject_button' => 'Yes, these %s are not a match',
        'ignore_title' => 'Ignore %s products?',
        'ignore_text' => 'Each one is marked as not a real product (a gift card, a bundle, a placeholder) and leaves the lists. Nothing is matched.',
        'ignore_button' => 'Yes, ignore these %s',
        'send_back_title' => 'Send %s products back for matching?',
        'send_back_text' => 'Each suggestion is closed. The products wait for a person or for the next full computer check (the check on arrival '
            . 'leaves a product that was sent back).',
        'send_back_button' => 'Yes, send these %s back',
        'skips' => 'Products held back, in a spot check, or changed since you opened the page are skipped and listed afterwards.',
        'reason' => 'Why (for all of them)',
        'reason_hint' => 'A few words, for example "placeholder pages of the bundles".',
        'cancel' => 'No, go back',
        'rows' => 'The %s products',
    ];

    /** Mapping › To review: the "By store" overview. */
    public const BY_STORE = [
        'title' => 'By store',
        'note' => 'Each store\'s products by how sure the match is · the share already matched',
        'none' => 'No store has website products in this system yet.',
        'none_text' => 'They arrive with each store\'s first product list.',
        'waiting' => '%s to review',
        'all_products' => 'All its products',
        'strength' => 'How sure',
        'col_waiting' => 'Waiting for you',
        'col_linked' => 'Products matched',
        'col_units' => 'Units matched (30 days)',
        'of_products' => '%s of %s',
        'no_products' => 'no products',
        'review' => 'Review',
        'nothing' => 'Nothing waiting',
        'unchecked_help' => 'No suggestion yet; matched ones were matched by hand or when the store was loaded.',
        'computer_next' => 'Next computer check',
        'total' => 'All products of the store',
    ];

    /** Products › Store Products. */
    public const STORE_PRODUCTS = [
        'no_stores' => 'No store has website products in this system yet.',
        'no_stores_text' => 'They arrive with each store\'s first product list.',
        'states' => 'Show',
        'sort_by' => 'Best sellers first, by',
        'sort_sold_30' => 'Sold in the last 30 days',
        'sort_sold_365' => 'Sold in the last year',
        'board' => '%s: website products',
        'shown' => '%s in all · %s shown',
        'state' => 'Match',
        'warehouse' => 'Warehouse product or suggestion',
        'no_suggestion' => 'Nothing suggested yet',
        'pending' => 'Waiting for a second OK: %s',
        'empty' => 'This store has no website products.',
        'empty_text' => 'They arrive with the store\'s product list.',
        'empty_filter' => 'No website product fits your search or filter.',
        'empty_filter_text' => 'Other products of this store are hidden by it.',
        'unlink_note' => 'To undo a match, open the product: a matching lead uses "Change this match" and says why. It is one at a time because it changes a stock link.',
    ];

    /** A website product's state on Store Products (Queries::STORE_STATES). */
    public const STORE_STATE = [
        'all' => 'All',
        'waiting' => 'Waiting for a second OK',
        'quarantined' => 'On hold',
        'ignored' => 'Ignored',
        'linked' => 'Matched',
        'suggested' => 'Suggested',
        'not_matched' => 'Not matched',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Products › By Item (docs/decisions.md U113-U122): every warehouse item with the variant it is matched to on each store.
    // The owner's words (10 Oct 2026, U122): a store's sellable option is a VARIANT, the warehouse record it is matched to is an
    // ITEM, and a PRODUCT is the parent page that has many variants. Only this screen and its picker say so yet; the other
    // screens keep "website product" and "warehouse product" until they are renamed together. The keys keep `by_product`.

    /**
     * The list and its picker ("Match…"). The %s of `missing`, `map_named`, `pick_title`, `pick_crumb`, `pick_now` and `pick_search`
     * is a store's name, read from the channel table: no store is named here.
     */
    public const BY_PRODUCT = [
        'no_stores' => 'No store is set up in this system yet.',
        'no_stores_text' => 'Each store becomes a column here as soon as it is set up.',
        'search' => 'Item name, CW number or barcode',
        'download' => 'Download this list, one column for each store',
        'filters' => 'Show',
        'all' => 'All',
        'every' => 'On every store',
        'missing' => 'Missing on %s',
        'suggested' => 'With a suggestion waiting',
        'board' => 'Warehouse items and their match on each store',
        'shown' => '%s in all · %s shown · by CW number',
        'item' => 'Item',
        'parent' => 'Product: %s',
        'stores_matched' => 'Stores matched',
        'summary' => '%s of %s',
        'more_one' => '+1 more variant',
        'more_many' => '+%s more variants',
        'on_hold' => 'On hold: shows as out of stock',
        'also_waiting' => 'Also waiting for a second OK: %s',
        'also_suggested' => 'Also suggested: %s',
        'review' => 'Review',
        'map' => 'Match…',
        'map_more' => 'Match another…',
        'map_other' => 'Choose another…',
        'map_named' => 'Choose the variant of %s for %s',
        'back_here' => 'You came back from this item.',
        'pinned' => 'It no longer fits the filter, so it is shown here once.',
        'empty' => 'There are no warehouse items yet.',
        'empty_text' => 'They are made when a store\'s list is loaded, or when a variant is created as a new item.',
        'empty_filter' => 'No warehouse item fits your search or filter.',
        'empty_filter_text' => 'Other items are hidden by it.',
        'note' => 'One at a time: "Match…" and "Review" open the variant\'s own page, where you compare the two and say yes. '
            . 'Many at once: tick the items and use the bar under the list. It shows a preview before anything is saved.',
        'note_look' => '"Matched", "Suggested" and the other words say what each store has for the item. Open a variant to see it.',
        'rows' => 'Rows',
        // The picker: one warehouse item on one store
        'pick_title' => 'Match %s on %s',
        'pick_crumb' => 'By Item: %s',
        'pick_facts' => 'The warehouse item',
        'pick_none' => 'none',
        'pick_now' => 'On %s now',
        'pick_now_none' => 'Not on this store.',
        'pick_now_more' => 'You can still match one more variant to it: some stores list one item on two pages.',
        'pick_suggested' => 'Suggested for this item',
        'pick_suggested_none' => 'The computer suggests no variant of this store for it. Search below.',
        'pick_search' => 'Other variants of %s still waiting for a match',
        'pick_search_label' => 'Name, brand, option number or barcode',
        'pick_search_hint' => 'Filled in from the item\'s name: change the words if nothing fits. Best sellers first.',
        'pick_search_first' => 'The suggestions are above. Press Search to look for other variants with these words.',
        'pick_found_none' => 'No variant of this store that still waits for a match has all these words. Try fewer words.',
        'pick_found_no_more' => 'No other variant of this store that still waits for a match has all these words. Try fewer words.',
        'pick_variant' => 'Variant',
        'pick_price' => 'Price',
        'pick_state' => 'Match',
        'pick_barcode' => 'Barcode',
        'pick_barcode_same' => 'Same barcode',
        'pick_barcode_differs' => 'Different barcode',
        'pick_barcode_unknown' => 'No barcode to compare',
        'pick_choose' => 'Choose',
        'pick_choose_named' => 'Choose %s',
        'pick_page' => 'Page %s',
        'pick_next_step' => '"Choose" opens that variant next to this warehouse item. Nothing is matched until you say yes there.',
    ];

    /**
     * What a store has for a warehouse item: a cell of By Item and of its CSV. `rejected` is a suggested cell whose every suggestion
     * was marked "not a match" for the item before (M59): the suggestion is still open, and a person decides it on its page.
     */
    public const PRODUCT_STORE = [
        'matched' => 'Matched',
        'waiting' => 'Waiting for a second OK',
        'suggested' => 'Suggested',
        'rejected' => 'Marked not a match',
        'none' => 'Not on this store',
    ];

    /**
     * By Item's bulk action (docs/decisions.md M55-M59, U123-U130; the owner's "bulk preview and update option", 10 Oct 2026): the
     * bar under the ticked items, the preview of every variant it would change and its one button, the refusals in words, and the
     * result page's words for a batch started here. The owner's words (U122): items and variants. %s of `on_store`, `nothing_here_*`
     * and `too_many` is a store's name or "all stores", read from the channel table.
     */
    public const BY_ITEM_BULK = [
        // The bar (U124)
        'bar' => 'Ticked items',
        'selected_none' => 'Tick items, choose the store, then what to do. You see a preview first.',
        'selected_one' => '1 item ticked',
        'selected_many' => '%s items ticked',
        'too_many' => '%s items ticked: at most %s variants at a time.',
        'on' => 'On',
        'all_stores' => 'All stores',
        'all_stores_lower' => 'all stores',
        'link' => 'Confirm suggested matches',
        'reject' => 'Not a match…',
        'send_back' => 'Send back for matching…',
        'limit' => 'Each of these acts on the suggested variants of the ticked items, on the store you choose. You see a preview first: nothing is '
            . 'saved before you say yes there. At most %s variants at a time.',
        'undo_note' => 'Matched variants are not changed here. To undo a match, open the variant: a matching lead uses "Change this match" and says '
            . 'why, one at a time, because it changes a stock link.',
        'rows' => 'Items on a page',
        'select_all' => 'Tick every item with a suggestion on this page',
        'manual_note' => 'An item whose only suggestion was marked not a match before is not ticked by "Select all on this page": tick it yourself, '
            . 'for example to send that suggestion back for matching.',
        'row_too_many' => '%s suggested variants: more than one bulk action can take. Decide them one at a time.',
        // The preview (U125, U126)
        'title' => 'Preview: %s',
        'title_link' => 'confirm suggested matches',
        'title_reject' => 'not a match',
        'title_send_back' => 'send back for matching',
        'summary_link' => '%s will be matched',
        'summary_reject' => '%s will be marked not a match',
        'summary_send_back' => '%s will be sent back for matching',
        'summary_pending' => '%s will wait for a second OK',
        'summary_skipped' => '%s will be skipped',
        'scope' => 'On %s · %s · %s',
        'variants_one' => '1 suggested variant',
        'variants_many' => '%s suggested variants',
        'nothing_here_one' => '1 ticked item has no suggestion on %s: nothing happens to it.',
        'nothing_here_many' => '%s ticked items have no suggestion on %s: nothing happens to them.',
        'text_link' => 'Each variant is matched to the item it is suggested for, with the units per sale the computer suggests. The usual rules apply: '
            . 'a match that needs a second person waits for a matching lead. Left for you to decide one at a time: a variant marked not a match before, '
            . 'an item that already has a variant on that store, and an item with more than one suggested variant on a store.',
        'text_reject' => 'This is the second step of "Not a match": nothing is marked before you press the red button. Each suggestion is marked '
            . 'wrong; nothing is matched, and each variant stays in its list for another choice. Matching it to that item later needs a second OK.',
        'text_send_back' => 'Each suggestion is closed. The variants wait for a person or for the next full computer check (the check on arrival '
            . 'leaves a variant that was sent back). Nothing is matched.',
        'again' => 'Everything is checked again when you press the button. A variant that changed since you looked is skipped, never changed, and '
            . 'the skipped ones stay as they are. The next page says what was done.',
        'button_link' => 'Yes, do these %s',
        'button_reject' => 'Yes, these %s are not a match',
        'button_send_back' => 'Yes, send these %s back for matching',
        'back' => 'No, go back to By Item',
        'none_to_do' => 'Every variant would be skipped, so there is nothing to do. Each line says why: open a variant to decide it by hand.',
        'col_item' => 'Item',
        'col_store' => 'Store',
        'col_variant' => 'Variant',
        'col_outcome' => 'What will happen',
        'will_link' => 'Will be matched',
        'will_reject' => 'Will be marked not a match',
        'will_send_back' => 'Will be sent back for matching',
        'will_wait' => 'Will wait for a second OK',
        'will_skip' => 'Will be skipped',
        'to_item' => 'To %s',
        'wait_why' => 'A second OK, because: %s.',
        'option' => 'option %s',
        'lines' => 'Every suggested variant of the ticked items',
        // Refused as a whole: nothing was saved
        'nothing_ticked' => 'Tick at least one item first. Nothing was saved.',
        'nothing_on' => 'None of the ticked items has a suggestion on %s, so there is nothing to do. Nothing was saved.',
        'too_many_rows' => 'The ticked items have %s suggested variants on %s. At most %s can be changed at a time (the Settings page says how many): '
            . 'tick fewer items, or choose one store. Nothing was saved.',
        // The result page of a batch started here
        'result_where' => 'By Item',
        'result_ticked' => 'Variants in this action',
        'result_col' => 'Variant',
        'result_all_rows' => 'Every variant of this action',
        'result_skipped_text' => 'Skipped variants were not changed. Each one says why: open it to decide it by hand.',
    ];

    /** Why a variant of By Item's bulk action is (or will be) skipped: CW\Mapping\BulkDecisions::SKIPS in the owner's words (U122). */
    public const BY_ITEM_SKIP = [
        'unknown_listing' => 'This variant no longer exists.',
        'other_store' => 'It is on another store than the one you chose.',
        'changed' => 'It is not as it was when you looked: someone decided it, the store renamed it, or a rule changed. It is now: %s.',
        'suggestion_changed' => 'Its suggestion changed since you looked: it may suggest another item now. Open it to see.',
        'pending_second' => 'A decision on it already waits for a second OK.',
        'held' => 'Held back to be checked one at a time: %s',
        'held_meanwhile' => 'It was held back to be checked one at a time while this ran.',
        'in_spot_check' => 'It is one of the matches of spot check %s: only that spot check\'s owner answers it, one at a time.',
        'on_arrival' => 'Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.',
        'spot_check_failed' => 'Spot check %s found a wrong match, so these strong matches are checked one at a time.',
        'bulk_undone' => 'A bulk match of it was undone before, so it is checked one at a time.',
        'band_not_allowed' => 'Its match strength (%s) is not one that may be confirmed together. Open it to decide it.',
        'vetoed' => 'A rule says it cannot be the suggested item: %s.',
        'flagged' => 'It needs a person\'s look: %s.',
        'no_product' => 'No item is suggested for it, so there is nothing to confirm or say no to.',
        'barcode_elsewhere' => 'Its barcode is already on another item. Open it to check.',
        'already_rejected' => 'It was already marked "not this item".',
        'not_ignored' => 'It is not on the ignored list (it is: %s).',
        'already_ignored' => 'It is already ignored.',
        'linked' => 'It is matched. Change it on its own page: that changes a stock link.',
        'not_waiting' => 'It is not waiting for a match (it is: %s).',
        'no_suggestion' => 'Nothing is suggested for it now.',
        'lead_only' => 'Only a matching lead decides it (the clues disagree).',
        'not_allowed' => 'Your jobs do not let you change it.',
        'product_joined' => 'The suggested item was joined into another one. Open it to pick the right one.',
        'no_name' => 'It has no name, so a new item cannot be made from it. Open it and type one.',
        'refused' => 'Not changed: %s',
        // What a confirm from By Item leaves to a person, one at a time (M59)
        'rejected_before' => 'It was marked not a match for this item before: open it and decide.',
        'item_on_store' => '%s already has a variant on %s: open it and decide.',
        'several_suggested' => '%s has more than one suggested variant on %s: open them and decide which is right.',
        // A bulk "create as new" is not offered on By Item (ITEM_ACTIONS): the reasons it skips for are here so that every reason has its
        // words in both sets (the same sentences; "its store" where the lists' words say "the website").
        'suggested_match' => 'The computer suggests it is %s: open it and decide.',
        'not_checked' => 'Nothing is suggested for this variant. Open it and decide.',
        'not_new' => 'The computer could not say what it is (%s): open it and decide.',
        'page_heading' => 'It is a page heading on its store, not something that is sold. Open it and decide.',
        'not_published' => 'It is not published on its store (%s). Open it and decide.',
        'state_not_known' => 'The warehouse does not know if it is on sale on its store now. Open it and decide.',
        'made_before' => 'An item was already made from this variant (%s): open it and decide.',
        'changed_since_check' => 'It changed on its store after the computer checked it: open it and decide.',
        'name_elsewhere' => 'An item with the same name is already in the warehouse (%s): open it and decide.',
        'name_twice' => 'Another row you ticked has the same name, so the two may be one item: open each and decide.',
        'match_no_item' => 'The computer matched it to a variant of the main store that has no item yet: open it and decide.',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Products › New Variants (docs/decisions.md M60-M62, U131-U138): one store's variants first seen in a period, and the "seed"
    // (create them as new items) on every store. The owner's words (U122, U137): a VARIANT is what a store sells, an ITEM the
    // warehouse record it is matched to. The %s of `store_off`, `store_on` and `board` is a store's name, read from the channel
    // table: no store is named here.

    /** The page. `where_from`, `store_off` and `store_on` say where new variants come from (U135): nothing is made up. */
    public const NEW_VARIANTS = [
        'no_stores' => 'No store has variants in this system yet.',
        'no_stores_text' => 'A store\'s variants show here once the store has sent them to the warehouse system.',
        'store_count' => 'variants first seen in this period',
        'where_from' => 'New variants come from the stores: each store\'s connector sends the variants the store adds or changes to the warehouse '
            . 'system, and a variant shows here from the moment it first arrives (the first load of a store\'s list counts too). Nothing here is '
            . 'made up: a variant the store has not sent is not here.',
        'store_off' => '%s is Off in the warehouse system (Settings › Stores), so its connector sends nothing yet: its new variants arrive only '
            . 'when its list of variants is loaded on the server (ask ' . self::ASK . ').',
        'store_on' => '%s is in contact with the warehouse system: its connector sends its new and changed variants.',
        'store_quiet' => '%s is switched on in the warehouse system, but its website is not sending now (Settings › Stores shows why): its new '
            . 'variants arrive only when its list of variants is loaded on the server (ask ' . self::ASK . ').',
        'newest' => 'Its newest variant was first seen on %s.',
        // A store where no computer check has ever marked a variant New (Queries::everFoundNew): the seed can only skip there.
        'never_new' => 'The computer check has not marked any variant of %s as New so far (it may not cover this store yet), so "Create as '
            . 'new items" will skip every row here. To make an item from one of its variants, open the variant and create the item on its '
            . 'own page.',
        'search' => 'Variant name, brand, option number or barcode',
        'download' => 'Download this list as a spreadsheet',
        'periods' => 'First seen',
        'period_days' => 'Last %s days',
        'period_day' => 'Last day',
        'period_all' => 'Any time',
        'states' => 'Show',
        'board' => '%s: new variants',
        'shown' => '%s in all · %s shown · newest first',
        'variant' => 'Variant',
        'option' => 'Option number',
        'barcode' => 'Barcode',
        'more_barcodes' => '+%s more',
        'price' => 'Price',
        'first_seen' => 'First seen',
        'state' => 'Match',
        'item' => 'What the computer found',
        'is_new' => 'When the computer checked it, it found nothing like it.',
        'made_before' => 'An item was already made from this variant (%s): open it to decide.',
        'changed' => 'It changed on the website after the computer checked it: open it to decide.',
        'suggested_item' => '%s: %s',
        // A suggestion of the check on arrival (U144): its strength, the item when it names one, and why (the reason's words).
        'arrival_item' => '%s: %s (%s)',
        'arrival_closest' => '%s, closest %s (%s)',
        'arrival_none' => '%s (%s)',
        // Above the list: whether the check on arrival is on for this store (%s the store), how many of its variants wait for it, and
        // what its last run did there (%s the day, then how many it looked at, suggested and left).
        'arrival_on' => 'The check on arrival is on for %s: a new variant whose barcode, or whose web address copied from the main store, '
            . 'points to an item gets a suggestion. No AI looks at it.',
        'arrival_off' => 'The check on arrival is switched off for %s, so its new variants get no suggestion by themselves (it is switched '
            . 'on for a store on the Settings page).',
        'arrival_waiting_one' => '1 variant is waiting for the check.',
        'arrival_waiting_many' => '%s variants are waiting for the check.',
        'arrival_last' => 'Its last run on %s looked at %s here: %s suggested as a match, %s where the clues disagree, %s not sure, %s left '
            . 'with no suggestion.',
        // A suggested match that names no item (%s: its strength): the matched main-store variant has no item (BulkDecisions::matchNoItem).
        'match_no_item' => '%s, to a variant of the main store that has no item yet: open it to decide.',
        'site_heading' => 'A page heading on the website, not something that is sold.',
        'site_status' => 'Not published on the website (%s).',
        // (M66, U146) under a row marked New whose state on the website is not known, or known only from a state that is too old:
        // "Create as new items" will skip it for that (SiteState::doubt(), the question the seed asks last)
        'site_unknown' => 'The warehouse does not know if it is on sale on the website now: open it to decide.',
        'matched_to' => 'Matched to %s',
        'on_hold' => 'On hold: shows as out of stock',
        'empty' => 'Nothing new to show here.',
        'empty_text' => 'The variants this store adds show here, newest first, as soon as the store sends them to the warehouse system. '
            . 'Try a longer period, or All.',
        'empty_search' => 'No variant fits your search.',
        'empty_search_text' => 'Other variants of this period are hidden by it.',
        'seed_note' => '"Create as new items" takes only the rows marked New: when the computer checked them, it found nothing like them. '
            . 'It makes a warehouse item from each, with the variant\'s own name and details, and matches the two. Every other ticked row is '
            . 'skipped and listed afterwards with the reason: no suggestion, a suggested match (also one to a variant of the main store '
            . 'that has no item yet), one the computer could not decide, a page '
            . 'heading, not published on the website, one the warehouse does not know to be on sale on the website now, changed on the '
            . 'website after the check, an item already made from it, a name that an '
            . 'item already has or that two ticked rows share, or a barcode that is already on an item or on another variant. Open a '
            . 'skipped one to decide it.',
        'csv_more' => 'Only the first %s variants are in this file.',
    ];

    /** What a variant of New Variants is (Queries::NEW_KINDS): the chip on its row and the word in the CSV. */
    public const NEW_VARIANT_STATE = [
        'new' => 'New',
        'suggested' => 'Suggested match',
        'not_checked' => 'No suggestion',
        'linked' => 'Matched',
        'quarantined' => 'On hold',
        'waiting' => 'Waiting for a second OK',
        'ignored' => 'Ignored',
    ];

    /** New Variants' filter (Queries::NEW_FILTERS and `all`). "New" is what "Create as new items" takes. */
    public const NEW_VARIANT_FILTER = [
        'unmatched' => 'All not matched',
        'new' => 'New: the computer found nothing like it',
        'suggested' => 'Suggested match',
        'not_checked' => 'No suggestion',
        'all' => 'All',
    ];

    /**
     * Why a variant of New Variants has no suggestion (U144): the line under a "No suggestion" row, from what the check on arrival
     * left on its profile (listing_profile.arrival_state and arrival_outcome; NewVariantsController::left()). `off`: the check is
     * not switched on for the row's store and has never looked at it; `before`: it is on, and the variant was there before it;
     * `queued`: it is waiting; the others are the outcomes the check records when it suggests nothing (Matching\ArrivalRules:
     * NO_CLUE and REFUSALS); `other`: it suggested something once and that suggestion is closed.
     */
    public const ARRIVAL_LEFT = [
        'off' => 'The check on arrival is switched off for this store: open it to decide.',
        'before' => 'It was here before the check on arrival, which has not looked at it: open it to decide.',
        'queued' => 'Waiting for the check on arrival.',
        'no_clue' => 'The check on arrival found no barcode and no copied web address for it: open it to decide.',
        'has_history' => 'A person or an earlier computer check has dealt with it, so the check on arrival leaves it: open it to decide.',
        'not_open' => 'It was matched or ignored when the check on arrival looked, so the check left it: open it to decide.',
        'held' => 'It was held back when the check on arrival looked, so the check left it: open it to decide.',
        'in_spot_check' => 'It was in a spot check when the check on arrival looked, so the check left it: open it to decide.',
        'no_title' => 'It has no name, so the check on arrival could not look at it: open it to decide.',
        'other' => 'The check on arrival suggested something for it before, and that suggestion is closed: open it to decide.',
    ];

    /** New Variants' action bar, in the owner's words (a "…" means a second step follows). */
    public const NEW_VARIANT_ACTION = [
        'new_item' => 'Create as new items',
        'link' => 'Confirm match',
        'ignore' => 'Ignore…',
    ];

    /** The "done" part of a New Variants batch's summary, by action (%s = how many). */
    public const NEW_VARIANT_DONE = [
        'new_item' => '%s new items created and matched',
        'link' => '%s matched',
        'ignore' => '%s ignored',
    ];

    /** What waits for a second OK on a variant of New Variants, by the decision's action (`other` for any other). */
    public const NEW_VARIANT_PENDING = [
        'link' => 'A match waits for a matching lead\'s yes.',
        'new_item' => 'A new item waits for a matching lead\'s yes.',
        'ignore' => 'Ignoring it waits for a matching lead\'s yes.',
        'other' => 'A decision waits for a matching lead\'s yes.',
    ];

    /** The spot-check pages (/ui/review/samples and /{id}, plan §6.9, 6.10). The answers on a member's page are SPOT. */
    public const SAMPLE = [
        'none' => 'No spot check yet.',
        'none_text' => 'Ask ' . self::ASK . ' to start one.',
        'name' => 'Spot check',
        'title' => 'Spot check %s',
        'checked' => 'Checked',
        'of' => '%s of %s',
        'result' => 'Result',
        'picked_from' => 'Picked from (strong matches)',
        'started_by' => 'Started by',
        'together' => 'Confirmed together',
        'undone' => '(%s undone)',
        'unusable' => 'This spot check cannot be used (it is smaller than the spot check size on the Approval Rules page, or it changed after it was made). Ask ' . self::ASK . ' to start a new one.',
        'passed' => 'All %s are right. The other strong matches can now be confirmed together. Ask ' . self::ASK . ' to run it for you.',
        'failed_one' => 'This spot check failed: 1 of the %s was wrong or changed. The rest cannot be confirmed together. Check them one by one in Strong matches.',
        'failed_many' => 'This spot check failed: %s of the %s were wrong or changed. The rest cannot be confirmed together. Check them one by one in Strong matches.',
        'owner_note' => 'Only %s checks these. Open each one, then say yes if it is right. When all %s are yes, the rest can be confirmed together.',
        'no' => 'No.',
        'product' => 'Website product',
        'suggested' => 'Suggested product',
        'ai' => 'AI',
        'ai_sure' => '%s%% sure',
        'by_on' => '%s, %s',
        'held' => 'Set aside to check by hand',
        'held_one' => '1 still waiting. It is never confirmed with the rest.',
        'held_many' => '%s still waiting. These are never confirmed with the rest.',
        'held_decided_one' => '1 more was decided since.',
        'held_decided_many' => '%s more were decided since.',
        'held_none' => 'None set aside.',
        'why' => 'Why',
        'set_by' => 'Set aside by',
        'now' => 'Now',
        'held_waiting' => 'Waiting for a decision',
        'held_newer' => '(a newer suggestion: still set aside)',
        'held_done' => 'Decided since: %s',
        'held_bulk' => 'Confirmed with the rest by mistake: check it now. To undo, ask ' . self::ASK . '.',
        'held_batch' => 'Matched by a bulk step by mistake: check it now. To undo, ask ' . self::ASK . '.',
        'started' => 'Started by %s on %s, picked at random from %s strong matches.',
        'tech' => 'Technical details (for audit)',
        'seed' => 'Seed',
        'seed_text' => 'picked by the server when the spot check was stored',
        'method' => 'Method',
        'rules' => 'Rules version',
        'conf' => 'AI %s–%s%% sure',
        'override' => 'Override',
        'override_text' => 'may take website products of the failed spot check %s again (newer suggestions only): %s',
        'left_out' => 'Left out',
        'unfit' => 'Why it cannot be used',
        'bulk' => 'Confirmed together',
        'bulk_run' => '%s matched in step %s',
        'bulk_not' => 'not run yet',
    ];

    /** Why a spot check cannot unlock the bulk step (KeySample::fitness codes), for the technical details. */
    public const SAMPLE_FIT = [
        'sample_too_small' => 'fewer matches than the spot check size',
        'draw_not_reproducible' => 'its matches are not what its seed picks',
        'stratum_short' => 'too few matches from one level of how sure the AI was',
    ];

    /** Possible duplicates (/ui/review/duplicates and /{id}, plan §6.11, 6.12). */
    public const DUPS = [
        // The list
        'none' => 'No possible duplicates are waiting.',
        'none_text' => 'When the computer finds pages that may sell the same product, they show here.',
        'total_one' => '1 group to decide.',
        'total_many' => '%s groups to decide, the biggest sellers first.',
        'many_differ' => 'Many are really different products: "What the rules say" lists the reasons against joining them.',
        'start' => 'Start with the first group',
        'keeper' => 'Product (the one we keep)',
        'pages' => 'Pages',
        'sold_365' => 'Sold (1 year)',
        'sold_30' => 'Sold (30 days)',
        'rules' => 'What the rules say',
        'maybe' => 'Maybe different: %s',
        'no_reason' => 'No reason against found: check the live pages',
        'not_checked' => 'Not checked (a page has no details)',
        'waiting' => 'Waiting for a second OK',
        'partly' => 'Partly decided',
        'group' => 'Group %s',
        'recent' => 'Decided recently',
        'recent_text' => 'Open a group to see what was decided, or to undo a join that was wrong.',
        'recent_one' => '1 decided group.',
        'recent_many' => '%s decided groups.',
        'recent_page' => 'Page %s of %s.',
        'recent_line' => '%s pages, %s',
        'recent_items_one' => '1 warehouse product',
        'recent_items_many' => '%s warehouse products',
        'newer' => 'Newer',
        'older' => 'Older',
        'look' => 'You can look. Only a matching lead decides if two pages are the same product.',
        // One group
        'title' => 'Same product on %s pages?',
        'decided_same' => 'Decided: same product – the pages now share %s',
        'decided_apart' => 'Decided: different products',
        'decided_mixed' => 'Decided: some pages joined, some kept apart',
        'partly_decided' => 'Partly decided.',
        'kind_shared_gtin' => 'Suggested because the pages share a barcode.',
        'kind_sweep' => 'Suggested because the computer found no difference (same brand, kind of product, size, strength and flavour; the names may be worded differently).',
        'kind_names' => 'Suggested because the names match.',
        'joined_note' => 'Joined pages share one warehouse product. On the website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system.',
        'skip' => 'Skip to the next group',
        // What the rules say
        'rules_ok' => 'The rules found nothing against joining these pages (same brand, kind of product, strength, size, flavour and words; no different barcodes). '
            . 'They cannot see the box: open the live pages before you join them.',
        'rules_doubt' => 'The rules found reasons these may be different products.',
        'rules_doubt_text' => 'Keep them apart unless the live pages show the same product.',
        'compared' => '"%s" compared with the one we keep, "%s":',
        'not_checked_page' => 'not checked (a page has no details)',
        // The page cards
        'state_keeper' => 'Kept: the others join this one\'s product',
        'state_same' => 'Same warehouse product as the kept one',
        'state_separate' => 'Kept apart',
        'state_waiting' => 'Waiting for a second OK',
        'state_elsewhere' => 'Has an open suggestion in another group or list: decide it there first',
        'state_not_linked' => 'Not matched to a warehouse product',
        'state_doubt' => 'To decide: the rules found differences',
        'state_open' => 'To decide',
        'suggested_keeper' => '(suggested to keep)',
        'page_on' => '%s page (option %s)',
        'open_page' => 'Open its page here',
        'brand' => 'Brand',
        'price' => 'Price',
        'not_known' => 'not known',
        'sold' => 'Sold',
        'sold_line' => '%s in 30 days, %s in a year',
        'from_history' => '(sales data to %s)',
        'from_profile' => '(from the website)',
        'site_stock' => 'Website stock',
        'not_for_sale' => 'not for sale',
        'on' => 'on %s',
        'live_page' => 'Live page',
        'live' => 'Open the live page',
        'no_link' => 'no link',
        'barcodes' => 'Barcodes',
        'none_value' => 'none',
        'product' => 'Warehouse product',
        'counted' => 'stock counted',
        'not_counted' => 'stock not counted yet',
        'free' => '%s free to sell in the warehouse',
        'protected' => 'Website sells warehouse stock only: cannot be joined here',
        'needs_second' => 'Joining needs a second matching lead',
        'also_on' => 'Also on this product (moves with it):',
        'all_attrs' => 'All %s options and details',
        'keep_instead' => 'Keep this one instead',
        // The comparison
        'compare' => 'What the titles and options say',
        'compare_text' => 'Rows in colour differ between the pages; underlined words are not on every page. Check them on the live pages before you join them.',
        'what' => 'What',
        'kept' => '(kept)',
        'differs' => 'Different',
        'partial' => 'Not on every page',
        // Why the computer suggested them
        'sweep' => 'Why the computer thinks they match',
        'sweep_text' => 'Every hard check passed (strength, nicotine type, size, puffs, pack, coil, model numbers, kind of product, colour, flavour, VG/PG), '
            . 'the brand is the same, the prices are close and every word of each name is on the other page. It cannot see the box: check the live pages.',
        'sweep_score' => 'Score %s of 100.',
        'and' => 'and',
        'sweep_score_pair' => 'score %s',
        'sweep_same' => 'the same %s',
        'sweep_unknown' => 'neither page states %s',
        'sweep_prices' => 'prices %s and %s',
        'sweep_close' => 'prices close',
        'sweep_one_page' => 'two options of one product page',
        'sweep_option' => 'option %s',
        // The answers
        'decide' => 'Your answer',
        'keep_line' => 'We keep %s, the warehouse product of "%s".',
        'keep_not_suggested' => '(not the one suggested: you picked it, or nothing was left to decide against it)',
        'each_page' => 'Each page against the one we keep',
        'c_same' => 'Same product',
        'c_apart' => 'Different product',
        'c_later' => 'Not sure yet',
        'confirm' => 'I opened the live pages: the pages I mark as the same product are the same product, whatever the rules say.',
        'join' => 'Same product – join them (both use %s)',
        'join_all' => 'All the same product (all use %s)',
        'apart' => 'Different products – keep apart',
        'apart_all' => 'All different',
        'save' => 'Save my choices',
        'does_join' => 'The other pages move to %s. What the warehouse holds for them (%s) moves there too, and their sales are added there. '
            . 'Nothing changes on the website. A wrong join can be undone.',
        'does_join_tick' => 'The rules found doubt, so tick "I opened the live pages" first.',
        'does_apart' => 'Nothing changes. Each page keeps its own warehouse product and stock. We never suggest them together again.',
        'does_save' => 'Each page gets the answer you chose for it. Pages left on "Not sure yet" stay on the list.',
        'does_later' => 'Nothing is saved. The group stays on the list.',
        'later' => 'Not sure yet: skip it',
        'free_short' => '%s free to sell',
        // Undo a join
        'undo' => 'Joined by mistake?',
        'undo_text' => 'Undo the join for one page: it gets back its own warehouse product, or becomes a new product. '
            . 'Once the product\'s stock has been counted, a second matching lead must give the OK.',
        'undo_back' => 'Undo: give it back its own product %s (%s in stock)',
        'undo_with' => 'with the other pages the join moved:',
        'undo_new' => 'Make it a new product instead',
        'undo_new_units' => 'Make it a new product instead (%s in stock)',
        'undo_new_none' => 'Make it a new product instead (no stock moves: it came with other pages, so its own stock cannot be told apart)',
        'undo_only_new' => 'It becomes a new product (%s in stock): %s was joined into another product since.',
        'undo_only_new_none' => 'It becomes a new product. No stock moves: it came onto %s with other pages, so its own stock cannot be told apart (the next shelf count settles it).',
        'undo_only_new_chain' => 'It becomes a new product. No stock moves: it came onto %s through two joins, so its own stock cannot be told apart (the next shelf count settles it).',
        'undo_button' => 'Undo the join',
    ];

    /** Notices after a decision on possible duplicates (DuplicatesController::NOTICES). %s is the group's name in quotes. */
    public const DUP_NOTICE = [
        'merged' => 'Done: %s is joined.',
        'separate' => 'Done: %s is kept apart. We will not suggest these pages together again.',
        'mixed' => 'Saved: in %s the pages you marked the same are joined, the others are kept apart.',
        'pending' => 'Saved, not live yet: joining %s needs a second matching lead\'s OK (see Second approval).',
        'split' => 'Done: the join is undone. The page is back on its own warehouse product, with the stock that came with it.',
        'split_pending' => 'Saved, not live yet: undoing the join needs a second matching lead\'s OK (see Second approval).',
        'done' => 'No possible duplicates are left to decide.',
        'next' => 'Here is the next group.',
        'the_group' => 'the group',
    ];

    /** A refusal on the duplicates pages, by error code (DecisionService's and the page's own). */
    public const DUP_ERROR = [
        'map_version_conflict' => 'One of these pages changed since you opened this page (someone matched or joined it, or the website renamed it). '
            . 'Nothing was saved: the page shows them as they are now. Check and decide again.',
        'proposal_changed' => 'The suggestions for this group changed since you opened this page. Nothing was saved: check the page and decide again.',
        'pending_second_exists' => 'A decision on one of these pages waits for a second OK. Nothing was saved: approve or cancel it first (Second approval).',
        'rejected_pair' => 'These pages cannot be joined: one of them was marked as a different product before. Nothing was saved.',
        'protected' => 'One of the products is protected (the website sells warehouse stock only): it cannot be joined or split here. Nothing was saved.',
        'counted_meanwhile' => 'One of the products had its stock counted a moment ago, so this needs a second matching lead now. Nothing was saved: decide again.',
        'not_merged' => 'This page was not moved here by a join (or was matched again since): change it on its own page instead. Nothing was saved.',
        'former_merged_elsewhere' => 'The product this page had before was joined into another product since: make it a new product instead. Nothing was saved.',
        'lead_required' => 'Only a matching lead can do this. Nothing was saved.',
        'idempotency_key_reused' => 'This form was already sent with other choices: reload the page and decide again.',
        'split_chain' => 'This page came onto its product through two joins, so the system cannot tell which join was wrong: make it a new product instead '
            . '(no stock moves; the next shelf count settles it). Nothing was saved.',
        'keeper_changed' => 'The page you chose to keep changed since you opened this page (someone matched or joined it). Nothing was saved: '
            . 'the page shows it as it is now. Check it and decide again.',
        'form_incomplete' => 'This form is incomplete. Nothing was saved. Reload the page and try again.',
        'nothing_chosen' => 'Choose "Same product" or "Different product" for at least one page. Nothing was saved.',
        'nothing_left' => 'Nothing is left to decide in this group.',
        'elsewhere' => '%s has an open suggestion in another group (or list) since you opened this page: decide that one first. Nothing was saved.',
        'confirm_needed' => 'The rules found reasons that %s may be a different product (see "What the rules say"). Nothing was saved: open the live pages, '
            . 'and if they are the same product tick "I opened the live pages" and join them again.',
        'choose' => 'Choose an answer first. Nothing was saved.',
        'reason_long' => 'The note is at most %s characters. Nothing was saved.',
        'other' => '%s Nothing was saved.',
    ];

    /** Find a product (/ui/search, plan §6.17). */
    public const SEARCH = [
        'label' => 'Product name, barcode, or number (CW-000123 or the website option number)',
        'button' => 'Find',
        'hint' => 'Type a product name, scan a barcode, or type a number like CW-000123.',
        'short' => 'Type at least two characters.',
        'items' => 'Warehouse products',
        'items_hint' => 'One per product. It holds the stock.',
        'listings' => 'Website products',
        'listings_hint' => 'Each website\'s own product pages.',
        'no_items' => 'No warehouse product matches. Try fewer words or the barcode.',
        'no_listings' => 'No website product matches.',
        'on_item_page' => 'The website products matched to a warehouse product are on its page.',
        'product' => 'Product',
        'brand' => 'Brand',
        'rule' => 'Stock rule',
        'barcodes' => 'Barcodes',
        'website' => 'Website',
        'matched' => 'Matched?',
        'sold_30' => 'Sold (30 days)',
        'yes_to' => 'Yes, to',
        'not_yet' => 'Not yet',
    ];

    /** The product page's matching and stock parts (/ui/items/{id}, plan §6.18; the product card is IM3's). */
    public const ITEM = [
        'merged_into' => 'This product was joined into',
        'merged_into_text' => 'Its website products and stock moved there. What its own orders on the way still need stays here until they ship.',
        'joined_here' => 'Duplicate pages share this warehouse product:',
        'joined_here_text' => 'joined into it. On the website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system.',
        'dups' => 'This product was checked for duplicates: see',
        'group' => 'group %s',
        'matching' => 'What the matching knows (from the website)',
        'matching_hint' => 'Read from the website product names. The product card above is what matters for the law and for buying.',
        'rule' => 'Stock rule',
        'made_from' => 'Made from',
        'made_from_line' => '%s product "%s" (option %s)',
        'created' => 'Created',
        'last_counted' => 'Stock last counted',
        'never' => 'never',
        'listings' => 'Website products matched to it',
        'listings_none' => 'No website product is matched to it, and none ever was.',
        'coverage_link' => 'See where it is matched',
        'product' => 'Website product',
        'website' => 'Website',
        'status' => 'Status',
        'now_here' => 'Matched to it now',
        'now_elsewhere' => 'Matched to another product now',
        'now_none' => 'Not matched now',
        'uses' => '1 sale uses',
        'sold_30' => 'Sold (30 days)',
        'sold_365' => 'Sold (1 year)',
        'history' => 'History',
        'h_none' => 'none',
        'h_since' => 'Since %s: %s, %s by %s (%s)',
        'h_period' => '%s – %s: %s, %s by %s (%s)',
        'h_this' => 'this product',
        'stock' => 'Stock',
        'warehouse' => 'Warehouse',
        'not_sellable' => 'not for sale',
        'no_stock' => 'No stock has ever been recorded for this product.',
        'reserved_link' => 'See the orders that hold these units (Stock › Reservations)',
        'movements' => 'Recent stock changes',
        'no_movements' => 'No stock changes yet.',
        'when' => 'When',
        'which' => 'Stock',
        'change' => 'Change',
        'after' => 'After',
        'what' => 'What',
        'ref' => 'Reference',
        'who' => 'Who',
        'note' => 'Note',
        'by_cw' => 'set up by CW',
        'by_site' => 'the website',
        'minted_from' => 'the website product it was made from',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Buying: purchase orders, What to buy, suppliers, supplier's products, sales data (plan §6.19-6.30)

    /** The status filter of the purchase orders list (PurchaseOrdersController::STATE_FILTERS keys). */
    public const PO_FILTER = [
        'draft' => 'Drafts',
        'awaiting_approval' => 'Waiting for a reviewer\'s OK',
        'approved' => 'Confirmed, not sent',
        'sent' => 'Sent',
        'part_received' => 'Partly delivered',
        'received' => 'Delivered',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /** How an order was made (purchase_order.source). */
    public const PO_SOURCE = [
        'manual' => 'By hand',
        'reorder' => 'From Reorder',
        'copy' => 'Copied from another order',
        'amend' => 'A corrected copy of another order',
        'import_file' => 'From a lines file',
        'erp_seed' => 'Brought in from ERPNext',
    ];

    /** Purchase orders list (/ui/purchasing/orders, plan §6.21). */
    public const ORDERS = [
        'how' => 'How an order goes',
        'how_1' => 'A buyer makes a draft and confirms it: it gets its PO number.',
        'how_2' => 'The buyer sends the PDF to the supplier, then records it here.',
        'how_3' => 'A reviewer checks every confirmed order within 7 days.',
        'how_4' => 'Over %s (before VAT), a reviewer must OK the order before it gets a number.',
        'how_4_off' => 'No order needs a reviewer\'s OK before it gets a number: the owner switched that off on the Approval Rules page.',
        'how_fix' => 'A confirmed order cannot be changed: cancel it, or press Correct to make a corrected copy.',
        'new' => 'Start a new order',
        'new_text' => 'Choose the supplier. Then add products by scanning or searching.',
        'supplier' => 'Supplier',
        'choose_supplier' => 'Choose a supplier',
        'not_ready' => '%s – %s (you can prepare an order, but not confirm it yet)',
        'not_approved' => 'not approved yet',
        'waiting_ok' => 'waiting for a reviewer\'s OK',
        'no_supplier' => 'There is no supplier yet.',
        'no_supplier_text' => 'Add one first. A reviewer approves it before an order to it can be confirmed.',
        'add_supplier' => 'Add a supplier',
        'show' => 'Show',
        'all_orders' => 'All orders',
        'any_supplier' => 'Any supplier',
        'search' => 'Number or supplier\'s reference',
        'rejected' => 'Reviewer said not OK',
        'all' => 'Also show cancellation records',
        // Behaviour item 8 (F305): goods-in and the purchasing desk see no drafts unless they ask.
        'drafts' => 'Show drafts too (not ordered yet)',
        'drafts_hidden' => 'Drafts are not shown: they are not ordered yet, so no delivery is coming for them.',
        'drafts_show' => 'Show drafts too',
        'filter' => 'Show',
        'download' => 'Download for Excel (CSV)',
        'none' => 'No purchase orders yet.',
        'none_buyer' => 'Choose a supplier above and press "Start a new order".',
        'none_text' => 'When a buyer starts one, it shows here.',
        'none_filter' => 'No order matches these filters.',
        'none_filter_text' => 'Clear the filters to see every order.',
        'clear' => 'Show all orders',
        'total_one' => '1 order, newest first.',
        'total_many' => '%s orders, newest first.',
        'limit' => 'The newest %s are shown. Narrow the filters, or download the list for every order.',
        'order' => 'Order',
        'status' => 'Status and next step',
        'dates' => 'Dates',
        'size' => 'Size',
        'total' => 'Total before VAT',
        'check' => 'Reviewer check',
        'sent' => 'Sent',
        'open' => 'Open',
        'no_number' => 'No number yet (draft)',
        'no_number_yet' => 'No number yet',
        'cancellation' => 'Cancellation record',
        'cancels' => 'Cancels %s',
        'started' => 'Started %s',
        'dated' => 'Dated %s',
        'expected' => 'Delivery %s',
        'products_one' => '1 product',
        'products_many' => '%s products',
        'items' => '%s items',
        'sent_on' => 'Sent %s, %s',
        // The next step of each order (B's list cards)
        'next_draft' => 'Not confirmed yet. Add the products, then confirm it.',
        'next_draft_look' => 'Not ordered yet: do not expect a delivery.',
        'next_awaiting' => 'Over the approval limit: a reviewer must OK it before it gets its PO number.',
        'next_approved' => 'Send the PDF to the supplier, then press "I have sent it".',
        'next_approved_look' => 'Confirmed. The supplier does not have it yet.',
        'next_sent' => 'Waiting for the delivery.',
        'next_part' => 'Part of it came. Waiting for the rest.',
        'next_received' => 'Everything came.',
        'next_closed' => 'Closed: the rest is not expected.',
        'next_cancelled' => 'Cancelled on %s (cancellation record %s).',
        'next_cancelled_draft' => 'Cancelled before it was confirmed. Nothing was ordered.',
        'next_rejected' => 'A reviewer said it is not OK: cancel it or correct it.',
        'next_cancellation' => 'This record cancels %s.',
        // The board (design v4): one group per state, a total row per group
        'board' => 'Orders by status',
        'group_one' => '1 order',
        'group_many' => '%s orders',
        'group_total' => 'Total for %s',
        'col_status' => 'Status',
        'col_supplier' => 'Supplier',
        'col_value' => 'Value',
        'col_units' => 'Units',
        'col_expected' => 'Expected',
        'col_next' => 'Next step',
        'add_row' => '+ New purchase order',
        'search_hint' => 'Number or supplier\'s reference',
    ];

    /** One purchase order and the draft editor (/ui/purchasing/orders/{id}, plan §6.19, 6.20). */
    public const ORDER = [
        'title_draft' => 'New order for %s (draft, no PO number yet)',
        'title_awaiting' => 'Order for %s (no number yet)',
        'title' => '%s – %s',
        'some_supplier' => 'a supplier',
        // Facts
        'facts' => 'The order',
        'supplier' => 'Supplier',
        'status' => 'Status',
        'check' => 'Reviewer check',
        'not_confirmed' => 'Not confirmed yet',
        'order_date' => 'Order date',
        'expected' => 'Expected delivery',
        'ref' => 'Supplier\'s quote reference',
        'note' => 'Note to the supplier',
        'source' => 'How it was made',
        'corrects' => 'It corrects',
        'corrected_by' => 'Corrected by',
        'started' => 'Started',
        'asked' => 'OK asked for',
        'confirmed' => 'Confirmed',
        'sent' => 'Sent',
        'sent_line' => '%s by %s (%s)',
        'sent_to' => 'to %s',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'by' => '%s by %s',
        'with_reason' => '%s: %s',
        'cancel_record' => 'Cancellation record %s',
        'cancel_waiting' => 'A cancellation waits for a reviewer\'s OK',
        'cancel_pdf' => 'Cancellation PDF',
        'a_draft' => 'a draft',
        // Totals and downloads
        'totals' => 'Totals',
        'products' => 'Products',
        'products_line' => '%s products, %s items',
        'products_line_one' => '%s product, %s items',
        'delivered_line' => '%s delivered',
        'net' => 'Total before VAT',
        'vat_line' => 'VAT %s (%s)',
        'gross' => 'Total with VAT',
        'pdf' => 'PDF to send',
        // A confirmed order whose company details are not confirmed yet: its PDF says DO NOT SEND (not one "to send").
        'pdf_hold' => 'Order PDF (says DO NOT SEND for now)',
        'pdf_draft' => 'Draft PDF (to check, not to send)',
        'xlsx' => 'Lines for Excel',
        'csv' => 'Lines (CSV)',
        'record' => 'Record and check history',
        // Notes at the top
        'only_creator' => 'Only %s can change the products of this draft. You can still confirm it (it gets a PO number) or cancel it.',
        'only_creator_look' => 'Only %s can change this draft. It is not ordered yet.',
        'rejected' => 'A reviewer said this order is not right: "%s" (%s, %s). The order is still live.',
        'rejected_next' => 'Next: cancel it, or correct it to make a new copy.',
        'company_not_confirmed' => 'Do not send this order yet: our company details are not confirmed, so the PDF says DO NOT SEND. A reviewer must confirm them first.',
        'company_before' => 'This order was confirmed before our company details were: its PDF still says DO NOT SEND. Press Correct to make a copy with the confirmed details.',
        'company_rejected' => 'This order was confirmed with company details a reviewer said are wrong: its PDF says DO NOT SEND. Cancel it or correct it.',
        'company_see' => 'See the company details',
        'company_fix' => 'Fill in and confirm the company details',
        // The reviewer's box
        'approval_title' => 'Needs your OK: over the approval limit',
        'approval_text' => 'This order is %s before VAT (the limit is %s). Nothing is ordered until you say yes. Decide by %s.',
        'review_title' => 'Reviewer check',
        'review_text' => 'The buyer confirmed this order. Is it right? Check by %s.',
        'review_cancel_text' => 'This cancellation record (%s) is final. Is it right? Check by %s.',
        'late' => 'Late',
        'ok_approval' => 'Yes, give it a PO number',
        'ok_review' => 'Yes, it is OK',
        'not_ok' => 'No – say why',
        'does_ok_approval' => 'It gets its PO number now, in the buyer\'s name. Then the buyer sends it.',
        'does_not_ok_approval' => 'The order is cancelled. Nothing is ordered.',
        'does_ok_review' => 'The check is closed. Nothing else changes.',
        'does_not_ok_review' => 'Your "not OK" is recorded. It does not cancel the order (it may already be with the supplier): the buyer cancels or corrects it.',
        'does_not_ok_cancel' => 'Your "not OK" is recorded. A cancellation is never undone: if the order was right, its buyer copies it into a new order.',
        'note_optional' => 'Note (optional)',
        'why_not_ok' => 'Why it is not OK (required)',
        'waiting_reviewer' => 'Waiting for a reviewer.',
        // What you can do
        'next' => 'What you can do',
        'confirm_draft' => 'Confirm order – gives it a PO number',
        'confirm_does' => 'It gets its PO number and is locked. Then send it to the supplier.',
        'confirm_over_does' => 'It goes to a reviewer first. It gets its PO number when the reviewer says yes.',
        'withdraw' => 'Cancel my request',
        'withdraw_does' => 'The order becomes a draft again, so you can change it.',
        'send' => 'I have sent it…',
        'send_again_open' => 'Record another sending…',
        'send_title' => 'Record that you sent it',
        'send_to' => 'Sent to',
        'send_stop' => 'Stop: this PDF says DO NOT SEND.',
        'send_anyway' => 'I sent it anyway – record it',
        'send_better' => 'Better: confirm the company details, then press Correct to get a right PDF.',
        'cancel' => 'Cancel…',
        'cancel_title' => 'Cancel this order',
        'cancel_why' => 'Why cancel it?',
        // Behaviour item 6 (F278): no reason is chosen for the person; the lists start empty and a choice is required.
        'choose_reason' => '— choose a reason —',
        'cancel_note' => 'Note (needed for "Other")',
        'cancel_button' => 'Cancel the order',
        'cancel_does' => 'The order is cancelled: tell the supplier. A cancellation record is saved, and a reviewer checks it like an order.',
        'cancel_draft_does' => 'The draft is cancelled. Nothing was ordered.',
        'cancel_request_does' => 'Your request is cancelled and so is the order. Nothing was ordered.',
        'correct' => 'Correct…',
        'correct_title' => 'Correct this order',
        'correct_why' => 'Why correct it?',
        'correct_button' => 'Correct: cancel it and make a corrected copy',
        'correct_does' => 'The order is cancelled and copied into a new draft. Change the draft, confirm it and send it instead (its PDF says which order it corrects).',
        'copy' => 'Copy: start a new order with the same products',
        'copy_does' => 'Makes a new draft with the same products. This order does not change.',
        'close' => 'Close…',
        'close_title' => 'Close: stop waiting for the rest',
        'close_why' => 'Why the rest is not coming (required)',
        'close_button' => 'Close the order',
        'close_does' => 'What came stays as it is. The rest is no longer expected.',
        'rules' => 'A confirmed order cannot be edited.',
        'rules_cancel' => 'Cancel: the order is cancelled (tell the supplier).',
        'rules_correct' => 'Correct: cancel it and make a corrected copy to send instead.',
        'rules_copy' => 'Copy: start a new order with the same products.',
        'rules_close' => 'After a part delivery, use Close instead of Cancel.',
        // The products
        'lines' => 'Products',
        'no_lines' => 'No products yet.',
        'line' => 'Line %s',
        'product' => 'Product',
        'their_code' => 'Their code',
        'pack' => 'Pack',
        'packs' => 'Packs',
        'items' => 'Items',
        'delivered' => 'Delivered',
        'per_pack' => '£ per pack',
        'vat' => 'VAT',
        'total' => '£ total',
        'line_note' => 'Note to the supplier',
        'charge' => 'Charge',
        'in_stock' => 'In stock',
        'ordered' => 'Already ordered',
        'last_price' => 'Last price',
        'last_order' => 'on the last order %s',
        'replaced' => 'Joined into another product: replace the line',
        'switched_off' => 'No longer bought from this supplier',
        // Files and history
        'files' => 'Files',
        'no_files' => 'No copy saved yet. A copy of the PDF is saved when you record that you sent it.',
        'no_files_draft' => 'No files.',
        'file_sent' => 'Sent copy',
        'file_other' => 'File',
        'file_line' => '%s, %s',
        'history' => 'Checks and OKs',
        'no_history' => 'Nothing was asked for yet.',
        'about_cancel' => 'About the cancellation record %s.',
        // The draft editor
        'order' => 'The order',
        'supplier_fixed' => 'The supplier can change only while the order has no products.',
        'note_label' => 'Note to the supplier (printed on the order)',
        'add' => 'Add a product',
        'scan' => 'Scan or type a product, then press Enter',
        'scan_hint' => 'A barcode, their product code, a CW number or words of the name. Enter also saves the table. Scanning the same product again adds one more pack.',
        'packs_label' => 'Packs',
        'add_button' => 'Add',
        'choose' => 'Which product is "%s"?',
        'choice_set_up' => 'Set up with this supplier: their code %s, %s',
        'choice_no_code' => 'no code',
        'choice_new' => 'Not set up with this supplier yet',
        'packs_zero' => 'Packs 0 removes a line.',
        'no_si' => 'For a product not set up with this supplier, type how many single items one pack holds. Tick "Remember" to keep that pack for next time.',
        'items_per_pack' => 'Items per pack',
        'remember' => 'Remember this pack for this supplier next time',
        'too_many' => 'This order is too long to edit on screen (about %s lines fit). To change its products, download the lines for Excel, change them, '
            . 'and import them with "Replace every line". The order details, a scan and a charge still work here.',
        'charge_open' => 'Add a charge (delivery …)',
        'charge_what' => 'What it is',
        'charge_amount' => 'Amount in £, before VAT',
        'charge_vat' => 'VAT',
        'charge_vat_default' => 'The supplier\'s usual VAT',
        'save' => 'Save draft',
        'confirm_note' => 'Confirming saves your changes, gives the order its PO number and locks it. Next: download the PDF and send it to the supplier.',
        'confirm_note_limit' => 'If your changes take it over %s before VAT, it goes to a reviewer first instead.',
        // The reverse (R2 review): the button is drawn for the SAVED total; typed changes can bring it under the limit.
        'confirm_note_under' => 'If your changes bring it to %s or less before VAT, it gets its PO number at once instead (no reviewer first).',
        'limit_note' => 'Over %s (before VAT): a reviewer must OK it before it gets a PO number. Up to %s: it gets a number at once, and a reviewer checks it within 7 days.',
        'file_title' => 'Change many products with Excel',
        'file_easy' => 'Easiest: press "%s", change the packs or prices in Excel, then import the same file.',
        'file_all_or_nothing' => 'If any row has a mistake, nothing is changed and the rows to fix are listed.',
        'file_columns' => 'Keep the column names as they are:',
        'file_label' => 'Lines file (CSV or Excel, up to %s MB and %s lines)',
        'mode' => 'What to do with the file',
        'mode_append' => 'Add to the products (packs add up for the same product)',
        'mode_replace' => 'Replace every line',
        'import' => 'Import',
        'import_failed' => 'The file was not imported',
        'download_xlsx' => 'Download the lines (Excel)',
        'cancel_draft' => 'Cancel the draft…',
        'cancel_draft_title' => 'Cancel this draft',
        'cancel_draft_button' => 'Cancel the draft',
        // --- Pack PO (docs/decisions.md PO7-PO11): the review fixes of the order screens.
        // The orders' "OK first" switched off on the Approval Rules page (review finding 1).
        'limit_note_off' => 'No order needs a reviewer\'s OK before it gets its PO number: that is switched off on the Approval Rules page.',
        // A delivery charge has its own remove box (review finding 8).
        'charge_remove' => 'Remove this charge',
        // "Reopen order" on a closed order (review finding 2).
        'reopen' => 'Reopen…',
        'reopen_title' => 'Reopen: the rest is coming after all',
        'reopen_does' => 'The order waits for the rest again. What came stays as it is. You can close it again later.',
        'reopen_why' => 'Why the rest is expected again (required)',
        'reopen_button' => 'Reopen the order',
        // --- end of pack PO
    ];

    /** Notices after a click on a purchase order (PurchaseOrdersController::NOTICES). %s: the PO number or the limit. */
    public const PO_NOTICE = [
        'created' => 'Draft order started. Scan or search to add products, then press "Confirm order".',
        'saved' => 'Saved: your changes to the draft.',
        'added' => 'Added, and every change in the table saved.',
        'incremented' => 'One more pack added to the line that was there, and every change in the table saved.',
        'imported' => 'Done: the products were imported from the file.',
        'approved' => 'Order confirmed: %s. Next: 1) download the PDF, 2) e-mail it to the supplier, 3) press "I have sent it". A reviewer checks the order within 7 days.',
        'submitted' => 'This order is over %s, so it waits for a reviewer\'s OK. Nothing is ordered until then.',
        'sent' => 'Recorded: you sent it.',
        'sent_archived' => 'Recorded: you sent it. A copy of the PDF as sent is kept below.',
        'sent_unarchived' => 'Recorded: you sent it. No copy of the PDF was kept: this server has no file store.',
        'cancelled' => 'Cancelled. Nothing was ordered.',
        'cancelled_posted' => 'Cancelled. Tell the supplier. The cancellation is saved as record %s, and a reviewer checks it like an order.',
        'amended' => 'The order was cancelled and copied into this new draft. Change it, confirm it and send it instead (its PDF says which order it corrects).',
        'copied' => 'Done: this new draft is copied from the order. Check it, then confirm it.',
        'closed' => 'Closed: the rest is no longer expected.',
        'withdrawn' => 'Request cancelled: the order is a draft again.',
        'reopened' => 'Reopened: the rest of the order is expected again.', // pack PO (PO8)
        // Pack PO (PO1-PO6): select or add new.
        'supplier_added' => 'New supplier added and put on this order. You can add products now. The order can be confirmed once the supplier is approved: see the note below.',
        'item_added' => 'The new item was made and added to the order.',
        'approved_review' => 'Saved: you said OK.',
        'approved_posted' => 'Done: the order has its PO number now, in the buyer\'s name.',
        'rejected_recorded' => 'Saved: you said not OK. The buyer will cancel or correct the order.',
        'rejected_approval' => 'Done: the order is cancelled. Nothing was ordered.',
        'rejected_reversal' => 'Saved: your "not OK" is recorded. A cancellation is never undone: if the order was right, its buyer copies it into a new order.',
    ];

    /**
     * A refusal on the buying pages, by error code (the services' messages stay the API's). %s as each text says. A code
     * without words shows the service's message, then "Nothing was saved." (other).
     */
    /** What is wrong with one line of an order, by the field PurchaseOrders names (BUY_ERROR line_field). */
    public const LINE_FIELD = [
        'pack_price' => 'the price per pack must be an amount in £ like 12.50 (at most 4 decimals, and at most %s a pack).',
        'packs' => 'the number of packs must be a whole number, and not more items than one line may hold.',
        'units_per_pack' => 'the items per pack differ from how this supplier sells it. Check "Items per pack".',
        'vat_code' => 'choose a VAT code from the list.',
        'description' => 'say what the charge is for (delivery, for example), in fewer words if it is long.',
        'supplier_item_id' => 'this product is not set up with this supplier, or is switched off there. Remove the line, or set it up again.',
        'sku_id' => 'this product is not the one set up with this supplier. Remove the line and add it again.',
    ];

    public const BUY_ERROR = [
        'other' => '%s Nothing was saved.',
        'version_conflict' => 'Not saved: someone changed this while you had it open. Here it is as it is now: make your change again.',
        'version_conflict_settings' => 'Not saved: someone changed these settings while you had them open. Here they are now: make your change again.',
        'scan_pending' => 'The scan box still holds "%s": press Add, or clear it, then confirm again. Nothing was saved.',
        'not_found' => 'No product found for "%s". Try fewer words or the barcode. Your other changes are saved.',
        'import_refused_one' => 'Nothing was imported: the file has 1 problem. Correct it and import the whole file again.',
        'import_refused' => 'Nothing was imported: the file has %s problems. Correct them and import the whole file again.',
        'no_file' => 'Choose a file to import. Nothing was imported.',
        'no_file_upload' => 'Choose a file to upload. Nothing was saved.',
        'too_large' => 'The file is too big (over %s MB). Nothing was saved. Use a smaller file.',
        // An .xlsx file that is small but holds a sheet too big to open (XlsxReader's own too_large).
        'too_large_inside' => 'This spreadsheet holds too much to open, although the file is small. Nothing was saved. Copy only the order lines into a new file, '
            . 'or save it as CSV, and import that.',
        // A line of the order editor or its lines file refused, by field (PurchaseOrders: bad_line, detail.field).
        'line_field' => 'Line %s: %s Nothing was saved.',
        // What to buy: the form arrived with fewer lines than it sent (PHP drops fields past its limit).
        'reorder_truncated' => 'The list was too long to send in one go, so nothing was ordered. Narrow the list (choose a brand or a supplier) and try again.',
        'upload_failed' => 'The file did not arrive completely. Nothing was saved: try again.',
        'choose_supplier' => 'Choose the supplier first. Nothing was saved.',
        'choose_kind' => 'Choose what the file is proof of. Nothing was saved.',
        'supplier_not_active' => 'Not confirmed: the supplier is not approved yet. A reviewer must approve the supplier first. Your changes are not saved.',
        'import_route_not_approved' => 'Not confirmed: a reviewer must first approve how duty stamps are put on goods from this supplier. Your changes are not saved.',
        'supplier_inactive' => 'Not saved: we stopped ordering from this supplier. Choose another supplier, or ask a buyer to start ordering from it again.',
        'supplier_has_lines' => 'Not saved: the supplier can change only while the order has no products. Remove them first, or start a new order.',
        'item_blocked' => 'Not confirmed: a product on this order is blocked by its product card (see the warnings above). Remove it, or ask for its card to be corrected.',
        'merged_item' => 'Not saved: a product on this order was joined into another product. Replace the line with the product it was joined into.',
        'not_draft' => 'Nothing was changed: this order is not a draft any more (someone confirmed or cancelled it meanwhile).',
        'not_creator' => 'Only the person who started this draft can change its products. Nothing was changed.',
        'not_sendable' => 'Nothing was recorded: only a confirmed order is sent.',
        'send_warnings' => 'Nothing was recorded: this PDF says DO NOT SEND. Tick "I sent it anyway – record it" if you sent it all the same.',
        'bad_reason' => 'Choose why from the list. Nothing was changed.',
        'note_required' => 'Write why in the note. Nothing was changed.',
        'reason_required' => 'Write why the rest is not coming (3 to 500 characters). Nothing was changed.',
        'not_cancellable' => 'Nothing was changed: this order cannot be cancelled now (it is a cancellation itself, or it is cancelled already).',
        'not_amendable' => 'Nothing was changed: only a confirmed order is corrected. A draft is simply edited.',
        'not_closable' => 'Nothing was changed: only a partly delivered order is closed. Cancel one that has had nothing delivered.',
        'not_withdrawable' => 'Nothing was changed: this order does not wait for a reviewer\'s OK.',
        // Pack PO (PO8): "Reopen order".
        'not_reopenable' => 'Nothing was changed: only a closed order is reopened.',
        'reopen_reason_required' => 'Write why the rest is expected again (3 to 500 characters). Nothing was changed.',
        'awaiting_approval' => 'Nothing was changed: this order waits for a reviewer\'s OK. Only the person who asked can cancel the request.',
        'po_has_receipts' => 'Nothing was changed: some of this order was delivered already. Use Close instead.',
        'too_many_lines' => 'Not saved: an order has at most %s products. Split it into two orders.',
        'role_not_allowed' => 'Only Buyers and Purchasing managers can do this. Nothing was changed.',
        'admin_cannot_post' => 'Admin cannot make or change orders. Nothing was changed.',
        'nothing_picked' => 'Nothing was ordered: no line is ticked. Tick the products you want to buy (lines already on a draft are not ticked), then press "Create draft orders" again.',
        'nothing_ordered' => 'No draft order was made: %s.',
        'bad_pick' => 'Packs must be a whole number (0 leaves the line out). Nothing was ordered.',
        'rebuild_on_server' => 'Too many products to work this out here (%s matched products). It is worked out again each time new sales are loaded. If it looks old, ask '
            . self::ASK . '. The list uses the last result.',
        'build_running' => 'Sales are being worked out right now. Wait a minute, then reload the page.',
        'anomaly_ended' => 'Nothing was changed: these days are used again already.',
        'supplier_pending' => 'Nothing was changed: this supplier waits for a reviewer\'s OK. Cancel your request first to change it.',
        'supplier_incomplete' => 'Nothing was sent. Please fill in these first: %s. Press "Change the details" to add them.',
        'duplicate_code' => 'Not saved: another supplier has this short code. Choose another one, or leave it empty.',
        'duplicate_pack' => 'Not saved: this product is already set up with this supplier in that pack size. Change the one that is there instead.',
        'task_closed' => 'This was decided meanwhile. Nothing was changed.',
        'not_pending' => 'Nothing was changed: this supplier is not waiting for a reviewer\'s OK any more.',
        'not_requester' => 'Only the person who asked can cancel this request. Nothing was changed.',
        'file_store_unconfigured' => 'Files cannot be kept on this server yet. Nothing was saved. Tell ' . self::ASK . '.',
        'unknown_purchase_order' => 'We cannot find this purchase order. The link may be old or wrong.',
        'unknown_supplier' => 'We cannot find this supplier. The link may be old or wrong.',
        'unknown_supplier_item' => 'We cannot find this supplier\'s product. The link may be old or wrong.',
        'unknown_channel' => 'There is no sales data for that website.',
        'not_alone' => 'Nothing was changed: this supplier was not made usable by one person alone.',
        'alone_checked' => 'Nothing was changed: a reviewer checked this supplier already.',
        'own_supplier' => 'You made or changed this supplier, so another reviewer must decide. Nothing was changed.',
        'admin_cannot_review' => 'Admin cannot check or approve work. Nothing was changed.',
    ];

    /** The warnings on a draft and on the send box (PurchaseOrders::warnings / sendWarnings, translated by Ui\PoWarnings). */
    public const PO_WARN = [
        'supplier_not_approved' => 'You can prepare this order, but you cannot confirm it until a reviewer approves %s.',
        'supplier_stopped' => 'We stopped ordering from %s. This order cannot be confirmed.',
        'route' => 'You cannot confirm this order until a reviewer approves how duty stamps are put on goods from %s.',
        'route_change' => 'A change to how duty stamps are put on goods from %s waits for a reviewer. You cannot confirm this order until then.',
        'dd_due' => 'The background check of %s was due on %s. Check the supplier again (on its page).',
        'below_minimum' => 'The total before VAT, %s, is below the smallest order %s takes (%s).',
        'no_price' => 'Line %s (%s) has no price. Type the price per pack, or the PDF shows £0.00.',
        'merged' => 'Line %s (%s) was joined into %s. Replace the line: the order cannot be saved or confirmed with it.',
        'switched_off' => 'Line %s (%s): we no longer buy it from this supplier. Remove the line, or start buying it here again.',
        'blocked' => 'Line %s (%s) is blocked by its product card (%s). The order cannot be confirmed with it.',
        'card_warning' => 'Line %s (%s): its product card, not confirmed yet, says %s. Check it before you order (once confirmed, it blocks the product).',
        'card_warning_changed' => 'Line %s (%s): its product card, changed since it was confirmed, says %s. Check it before you order (once confirmed, it blocks the product).',
        'discontinued' => 'Line %s (%s) is marked "not sold any more" on its product card.',
        'send_rejected' => 'A reviewer said this order is not right: cancel it or correct it rather than send it.',
        'send_company' => 'Our company details were not confirmed when this order was confirmed, so its PDF says DO NOT SEND.',
        'send_company_rejected' => 'This order carries company details a reviewer said are wrong, so its PDF says DO NOT SEND: cancel it or correct it rather than send it.',
        // A row of a lines file (PoLinesFile): "Row 3 (packs): ..."
        'row' => 'Row %s (%s): %s',
        'row_no_product' => 'Row %s: no product given. Fill in the CW number (%s), their product code (%s) or the barcode (%s).',
        'row_plain' => 'Row %s: %s',
    ];

    /**
     * Pack PO (the owner, 15 Oct 2026: "In purchase order view: select or add new option"; docs/decisions.md PO1-PO6): wherever an order
     * chooses a supplier or a product, choose one that is there or add a new one without leaving the order.
     */
    public const PO_ADD = [
        // The supplier: choose one, or add a new one
        'filter' => 'Type to narrow the list',
        'new_supplier' => 'Supplier not in the list? Add a new supplier',
        'new_supplier_text' => 'Type its name. It is added as a new supplier and put on this order at once. Its other details are filled in on its own page later.',
        'new_supplier_name' => 'Supplier name (required)',
        'new_supplier_email' => 'E-mail (optional)',
        'new_supplier_phone' => 'Phone (optional)',
        'new_supplier_start' => 'Add the supplier and start the order',
        'new_supplier_set' => 'Add the supplier and put it on this order',
        'ask_supplier' => 'Supplier not in the list? %s can add a new one: ask them.',
        // What a supplier that is not approved yet still needs before the order can be confirmed
        'needs_title' => 'Supplier waiting for approval',
        'needs_new' => '%s is a new supplier. You can save this order now. Before it can be confirmed:',
        'needs_fill' => 'Fill in its details: %s.',
        'needs_ask' => 'Ask for its approval on its page.',
        'needs_reviewer' => 'A reviewer approves it. Then confirm this order.',
        'needs_pending' => '%s is waiting for a reviewer\'s approval. You can save this order now, and confirm it once the supplier is approved.',
        'needs_link' => 'Open the supplier',
        // The same while the second person for a supplier is switched off on the Approval Rules page (its buyer makes it usable).
        'needs_title_alone' => 'Supplier not usable yet',
        'needs_activate' => 'Make it usable on its page: no second person is needed (that is switched off on the Approval Rules page).',
        'needs_then' => 'Then confirm this order.',
        // The product of a line: scan or search, or add a new one
        'new_item' => 'Add a new item',
        'new_item_does' => 'For a product the system does not have yet. Your changes in the table are saved first.',
        'ask_item' => 'Product not found? %s can add a new item: ask them.',
        // The step page "Add a new item"
        'item_title' => 'Add a new item to the order for %s',
        'item_name' => 'Item name (required)',
        'item_brand' => 'Brand',
        'item_barcode' => 'Barcode on the item',
        'item_supply' => 'How this supplier sells it',
        'item_supply_text' => 'Give their product code, the pack or the price: it is then set up with this supplier for next time.',
        'item_code' => 'Their product code',
        'item_upp' => 'Items in one pack',
        'item_price' => '£ per pack, before VAT',
        'item_packs' => 'Packs to order',
        'item_button' => 'Make the item and add it to the order',
        'item_back' => 'Back to the order',
        'same_title' => 'An item with this name is in the system already',
        'same_text' => 'Add the one that is there, or tick the box below if yours is a different item.',
        'same_use' => 'Add this one to the order',
        'same_anyway' => 'It is a different item: make a new one',
        'barcode_other' => 'This barcode belongs to %s.',
        'barcode_use' => 'Add that item to the order instead',
        'bad_number' => 'Not saved: "%s" must be a whole number of at least 1. Nothing was saved.',
    ];

    /** What to buy (/ui/purchasing/reorder, plan §6.22). REORDER['demand'] is correction e. */
    public const REORDER = [
        // Correction e (7 Oct).
        'demand' => 'It is worked out again each time new sales are loaded. If it looks old, ask ' . self::ASK . '.',
        'sells_a_day' => 'Sells a day',
        'no_sales' => 'no sales',
        'look' => 'This is what the buyers are told to buy.',
        'data' => 'Sales data',
        'data_from' => 'Sales data from %s: %s – %s.',
        'data_old' => 'The last %s days are missing, so the list may be too low. Ask ' . self::ASK . ' to load the new sales.',
        'worked_out' => 'Worked out on %s.',
        'worked_out_old' => 'New sales were loaded since: press "Work out sales again".',
        'not_worked_out' => 'Not worked out yet.',
        'no_data' => 'No sales data yet, so nothing can be suggested.',
        'no_data_text' => 'Ask ' . self::ASK . ' to load the website sales.',
        'recalculate' => 'Work out sales again',
        'recalculate_hint' => 'Use this after new sales are loaded, or after you add days to leave out. It takes up to a minute.',
        'brands' => 'Brand settings',
        'anomalies' => 'Days to leave out of sales',
        'sales' => 'Sales data',
        // Filters
        'brand' => 'Brand',
        'any_brand' => 'Any brand',
        'supplier' => 'Main supplier',
        'any_supplier' => 'Any supplier',
        'product' => 'Product',
        'product_hint' => 'CW number or words of the name',
        'show' => 'Show',
        'show_need' => 'Products to buy',
        'show_all' => 'Every product',
        'stock' => 'Stock to use',
        'stock_cw' => 'Warehouse stock (normal)',
        'stock_site' => 'Vape and Go website stock – only until the warehouse goes live',
        'urgent' => 'Will run out only',
        'filter' => 'Show',
        'download' => 'Download for Excel (CSV, every line)',
        // The list
        'total_one' => '1 product to buy.',
        'total_many' => '%s products to buy.',
        'total_all_one' => '1 product.',
        'total_all_many' => '%s products.',
        'page' => 'Page %s of %s',
        'units' => 'Sales are in single items a day.',
        'none' => 'Nothing to buy right now.',
        'none_text' => 'Choose "Every product" to see them all.',
        'none_filter' => 'No product matches these filters.',
        'none_filter_text' => 'Clear the filters to see the whole list.',
        'clear' => 'Show the whole list',
        'buy' => 'Buy?',
        'buy_label' => 'Buy %s',
        'have' => 'Have + ordered',
        'have_line' => '%s + %s',
        'have_site' => 'Website stock + ordered',
        'days_left' => 'Days left: %s',
        'suggest' => 'Suggest (packs)',
        'packs_label' => 'Packs of %s',
        'pack_of' => 'packs of %s',
        'per_pack' => '%s a pack',
        'value' => '£',
        'value_label' => 'Cost',
        'no_supplier' => 'No main supplier',
        'why' => 'Why this amount?',
        'maths' => 'Show the maths',
        'open' => 'Open the details',
        'on_draft' => 'Already on a draft (%s)',
        'on_draft_covers' => 'The draft covers this.',
        'on_draft_more' => 'Buy %s more.',
        'open_draft' => 'Open the draft',
        'create' => 'Create draft orders',
        'create_text' => 'One draft per main supplier, at the last price. Lines already on a draft are not ticked: tick one only to buy more.',
        'previous' => 'Previous page',
        'next' => 'Next page',
        // After "Create draft orders" (F323)
        'made' => 'Draft orders made',
        'made_line' => '%s – %s, %s (open it to check and confirm it)',
        'made_none' => 'No draft order is shown (it may have been cancelled since).',
        'below_minimum' => 'Below the smallest order this supplier takes (%s)',
        'not_ordered' => 'Not ordered',
        'not_ordered_line' => '%s – %s',
        'and_more' => 'and %s more',
        'skip_no_supplier' => 'no main supplier (set one on its supplier\'s page)',
        'skip_supplier_stopped' => 'we stopped ordering from its main supplier, %s',
        'skip_blocked' => 'blocked by its product card (%s)',
        'skip_merged' => 'joined into another product',
        'skip_other' => 'left out',
        // Pack PO (PO11, review finding 4): what the list showed changed before "Create draft orders" was pressed.
        'skip_changed' => 'its main supplier or pack changed after the list was shown, so nothing was ordered for it: look at it again and tick it again',
        'board' => 'Suggested orders',
    ];

    /** The plain "Why" of a line of What to buy (Ui\ReorderWhy, plan F308); the formula stays in "Show the maths" (Reorder\Explain). */
    public const WHY = [
        'sells' => 'Sells about %s a day.',
        'sells_none' => 'No sales found, so nothing is needed for sales.',
        'left_out' => 'Left out: %s.',
        'promo' => 'promotion days',
        'oos' => 'sold-out days',
        'factor' => 'Expected to sell %s (%s setting): about %s a day.',
        'factor_more' => '%s%% more',
        'factor_less' => '%s%% fewer',
        'factor_item' => 'this product\'s',
        'factor_brand' => 'the brand\'s',
        'cover' => 'Delivery takes %s days and you order every %s days, plus %s spare days = %s days.',
        'target' => '%s × %s = %s to have.',
        'target_min' => 'The smallest stock you set is %s, so aim for %s.',
        'target_max' => 'The largest stock you set is %s, so aim for %s.',
        'have' => 'You have %s and %s on order.',
        'have_site' => 'The website shows %s, and %s are on order.',
        'site_unsure' => 'The website stock may be wrong (some options sell whatever the figure says).',
        'buy' => 'So buy %s → %s.',
        'buy_none' => 'So nothing to buy.',
        'never' => 'Never suggested: %s.',
        'never_merged' => 'it was joined into %s',
        'never_marked' => 'it is marked "never suggest"',
        'never_discontinued' => 'it is not sold any more (product card)',
        'never_blocked' => 'its product card blocks it',
        'packs' => '%s packs of %s = %s',
        'single' => '%s single items',
        'rounded_zero' => 'Rounded to whole packs of %s, that is 0 packs.',
        'smallest' => 'smallest order %s packs',
        'steps' => 'in steps of %s packs',
        'nearest' => 'rounded to the nearest pack',
        'urgent' => 'Urgent: you will run out before the next delivery.',
        'drafts' => '%s are on draft orders already (not included above).',
        // --- Pack G
        'safety_stock' => 'Safety stock: %s spare days × %s = %s.',
        'safety_stock_min' => 'Safety stock: %s (the smallest stock you set).',
        'below_safety' => 'You have %s now: below the safety stock.',
        // --- end of Pack G
    ];

    /** Why this amount (/ui/purchasing/reorder/items/{id}, plan §6.23). */
    public const REORDER_ITEM = [
        'title' => 'Reorder: %s (%s)',
        'product_page' => 'Product page',
        'no_brand' => 'No brand',
        'merged' => 'Joined into %s: never suggested.',
        'suggest' => 'What the list suggests',
        'not_listed' => 'This product is not on Reorder: no website sales were found for it.',
        'not_listed_text' => 'If it does sell, ask the matching team to match its website product.',
        'main_supplier' => 'Main supplier',
        'no_main' => 'None yet. Make one of its suppliers the main supplier.',
        'supplier_line' => '%s (%s), %s',
        'their_code' => 'their code %s',
        'moq' => 'smallest order %s packs, in steps of %s',
        'delivery' => 'Delivery takes',
        'every' => 'You order every',
        'spare' => 'Spare days',
        'n_days' => '%s days',
        'aim' => 'Aim to have',
        'urgent_below' => 'Urgent below',
        'in_stock' => 'In stock',
        'in_stock_line' => '%s, on order: %s, on drafts: %s',
        'suggest_line' => 'Suggest',
        'suggest_value' => '%s (%s)',
        'demand' => 'Average sales',
        'no_demand' => 'No sales were found the last time sales were worked out.',
        'rate' => 'Sells',
        'rate_line' => 'about %s a day (last 4 weeks: %s; last 3 months: %s)',
        'plain' => 'Simple average of the last 30 days',
        'plain_line' => '%s a day (nothing left out; what ERPNext used)',
        'year' => 'Sold in the last year',
        'year_line' => '%s (first sale %s, last sale %s)',
        'computed' => 'Worked out',
        'by_month' => 'Sold by month',
        'days' => 'Sales day by day',
        'days_error' => 'Sales day by day cannot be shown right now: %s',
        'no_days' => 'No matched website product of this product sold in the loaded sales.',
        'days_summary' => '%s product %s (1 sale = %s): about %s a day. %s of the last %s days were used (%s left out).',
        'days_capped' => '%s very high days were lowered to %s.',
        'day' => 'Day',
        'sold' => 'Sold',
        'used' => 'Used?',
        'yes' => 'Yes',
        'yes_lowered' => 'Yes, lowered to %s',
        'no' => 'No: %s',
        'left_out' => 'left out',
        'before_history' => 'before the sales data starts',
        'before_first' => 'before the first sale',
        'r_promo' => 'a promotion day',
        'r_oos' => 'sold out that day',
        'settings' => 'Settings of this product',
        'settings_note' => 'Leave a box empty to use the normal setting (spare days: %s; delivery days: from the supplier; packs: rounded up).',
        'settings_brand' => 'The brand\'s sales change is %s.',
        'factor' => 'Expect sales to change',
        'factor_hint' => '1 = no change, 0.85 = 15% fewer, 1.2 = 20% more (0 to 5)',
        'safety' => 'Spare days of stock',
        'safety_hint' => '0 to 90',
        'lead' => 'Delivery days for this product',
        'lead_hint' => 'Empty = the supplier\'s (0 to 120)',
        'min' => 'Smallest stock to keep',
        'max' => 'Largest stock to keep',
        'rounding' => 'Round packs',
        'rounding_default' => 'Normal (up)',
        'rounding_up' => 'Up to a whole pack',
        'rounding_nearest' => 'To the nearest pack',
        'never' => 'Never suggest this product',
        'note' => 'Note',
        'save' => 'Save the settings',
        'normal' => 'Normal settings are used for this product.',
        'set_factor' => 'Sales change: %s',
        'set_safety' => 'Spare days: %s',
        'set_lead' => 'Delivery days: %s',
        'set_min' => 'Smallest stock: %s',
        'set_max' => 'Largest stock: %s',
        'set_rounding' => 'Packs: rounded to the nearest',
        'set_never' => 'Never suggested',
        'set_note' => 'Note: %s',
        // --- Pack G
        'safety_stock' => 'Safety stock',
        'low' => 'Low-stock warning on the websites',
        'low_hint' => 'Single items. Empty = each website keeps its own figure.',
        'set_low' => 'Low-stock warning: %s',
        // --- end of Pack G
    ];

    /** Brand settings (/ui/purchasing/reorder/brands, plan §6.24). */
    public const BRANDS = [
        'intro' => 'Expect a brand to sell more or less? Set it here for all its products: 0.85 = 15%% fewer, 1.2 = 20%% more. Spare days replace the normal %s. '
            . 'A setting on a single product wins over the brand.',
        'how' => 'Press "Change" next to a brand to set it.',
        'title_brand' => 'Change %s',
        'factor' => 'Sales change',
        'factor_hint' => '1.00 = no change (0 to 5); empty = 1.00',
        'safety' => 'Spare days',
        'safety_hint' => '0 to 90; empty = the normal %s',
        'note' => 'Note',
        'save' => 'Save',
        'none' => 'No brand is on Reorder yet.',
        'none_text' => 'Brands show here once their products have sales.',
        'brand' => 'Brand',
        'products' => 'Products',
        'sells' => 'Sells a day',
        'change_col' => 'Sales change',
        'spare' => 'Spare days',
        'changed' => 'Changed',
        'changed_line' => '%s by %s',
        'normal' => 'normal',
        'change' => 'Change',
    ];

    /** Days to leave out of sales (/ui/purchasing/reorder/anomalies, plan §6.25). */
    public const ANOMALIES = [
        'intro' => 'Add days when sales were unusual (stockpiling before the duty, a one-off promotion). Those days are ignored when CW works out what to buy. '
            . 'Up to %s days at a time. After adding or ending, press "Work out sales again" on Reorder.',
        'add' => 'Add days to leave out',
        'from' => 'First day',
        'to' => 'Last day',
        'website' => 'Website',
        'every_website' => 'Every website',
        'brand' => 'Brand',
        'brand_hint' => 'Empty = every brand',
        'label' => 'What happened',
        'label_hint' => 'Short, for example "Pre-duty stockpiling". It is shown next to the suggestions.',
        'save' => 'Leave these days out',
        'none' => 'No days are left out. All sales are used.',
        'days' => 'Days',
        'days_line' => '%s – %s',
        'which' => 'Website and brand',
        'what' => 'What happened',
        'state' => 'Now',
        'added' => 'Added',
        'active' => 'Left out',
        'ended' => 'Used again since %s',
        'ended_by' => 'Used again since %s (%s)',
        'by' => '%s by %s',
        'set_up' => 'set up by CW',
        'end' => 'Use these days again',
        'end_hint' => 'Their sales are used again after you press "Work out sales again".',
        'all_brands' => 'every brand',
        'all_websites' => 'every website',
    ];

    /** Notices after a click on What to buy and its settings (ReorderController::NOTICES). */
    public const REORDER_NOTICE = [
        'recalculated' => 'Done: sales were worked out again from the loaded sales data.',
        'drafts' => 'Draft orders made from the ticked lines: open each one, check it and confirm it.',
        'item_saved' => 'Saved: Reorder uses these settings at once.',
        'brand_saved' => 'Saved: Reorder uses the brand\'s settings at once.',
        'anomaly_added' => 'Saved: these days are left out the next time sales are worked out ("Work out sales again" on Reorder).',
        'anomaly_ended' => 'Saved: these days are used again the next time sales are worked out.',
        // --- Pack G
        'recalculated_later' => 'Done: sales were worked out again. The list is long, so its figures follow within minutes.',
        'refreshed' => 'Done: the list was worked out again with the stock and orders of now.',
        'refreshed_page' => 'Done: the products of that page were worked out again. The rest follow within minutes.',
        // --- end of Pack G
    ];

    // --- Pack G: the reorder engine (docs/decisions.md PG1-PG16) --------------------------------------------------------------------

    /** Reorder's suggestions with the stored list (pack G): the figures' time, the safety-stock filter, the skipped lines. */
    public const REORDER_ENGINE = [
        'below' => 'Below safety stock only',
        'below_hint' => 'Every product whose stock now is below its safety stock, even when more is on order.',
        'below_chip' => 'Below safety stock (%s)',
        'safety_stock' => 'Safety stock %s',
        'figures_at' => 'Figures worked out on %s.',
        'figures_every' => 'They are worked out again every %s minutes, and at once after you make draft orders or change settings.',
        'figures_none' => 'The list has not been worked out yet.',
        'figures_failed' => 'The last time the list was worked out it went wrong. Ask ' . self::ASK . '.',
        'refresh' => 'Work out the list again now',
        'refresh_hint' => 'Use it after a delivery or an order, when you cannot wait. A long list: the products of this page.',
        'refresh_later' => 'The list has %s products: too many to work out now. It is worked out again within %s minutes.',
        'own_from' => '%s: sales from %s come from CW\'s own record of its orders.',
        'own_to' => '%s: sales from %s to %s come from CW\'s own record of its orders.',
        'skip_discontinued' => 'not sold any more (product card): add it to an order by hand if you mean it',
        'skip_never' => 'marked "never suggest": add it to an order by hand if you mean it',
        'skip_supplier_waiting' => 'its main supplier, %s, is not approved yet',
        'skip_no_price' => 'no price yet at its main supplier: record the price first',
    ];

    /** The pack's refusals in the page's words (ReorderController::plain, by error code). */
    public const REORDER_ENGINE_ERROR = [
        'refresh_running' => 'The list is being worked out right now. Nothing was changed. Try again in a minute.',
        'build_running' => 'Sales are being worked out right now. Nothing was changed. Try again in a minute.',
        'too_many_lines' => 'Too many products are ticked for one click. Nothing was changed. Tick fewer and try again.',
        'bad_action' => 'Choose one of the two buttons. Nothing was changed.',
        'unknown_supplier' => 'That supplier was not found. Nothing was saved.',
    ];

    /** Notices of the pack's pages (ReorderEngineController::NOTICES). */
    public const REORDER_ENGINE_NOTICE = [
        'supplier_saved' => 'Saved: Reorder uses the supplier\'s spare days at once.',
        'low_saved' => 'Done: the low-stock warnings of the ticked products were changed. Products left out are listed below.',
        'refreshed' => 'Done: the list was worked out again with the stock and orders of now.',
        'refreshed_page' => 'Done: the products of that page were worked out again. The rest follow within minutes.',
    ];

    /** Suppliers: spare days (/ui/purchasing/reorder/suppliers). */
    public const REORDER_SUPPLIERS = [
        'intro' => 'Spare days set here count for the products a supplier is the main supplier of. A product\'s or its brand\'s spare days win. '
            . 'Otherwise the normal %s days are used.',
        'lead_note' => 'Delivery days and how often you order are set on the supplier\'s own page.',
        'how' => 'Press "Change" next to a supplier to set its spare days.',
        'title_supplier' => 'Change %s',
        'spare' => 'Spare days',
        'spare_hint' => '0 to 90; empty = the normal %s',
        'note' => 'Note',
        'save' => 'Save',
        'none' => 'No supplier yet.',
        'none_text' => 'Suppliers show here once they are added on Purchasing › Suppliers.',
        'supplier' => 'Supplier',
        'status' => 'State',
        'products' => 'Main supplier of',
        'delivery' => 'Delivery days',
        'every' => 'Order every',
        'n_days' => '%s days',
        'normal_days' => 'normal (%s)',
        'changed' => 'Changed',
        'changed_line' => '%s by %s',
        'change' => 'Change',
        'open' => 'Supplier page',
    ];

    /** Low-stock warnings (/ui/purchasing/reorder/low-stock). */
    public const REORDER_LOW = [
        'intro' => 'Each website warns when a product\'s stock falls to its low-stock figure. ERPNext no longer sets it. CW keeps it here.',
        'how' => 'Empty means each website keeps the figure it has. A figure is in single items; a pack of 10 on a website gets a tenth of it.',
        'site_wins' => 'A figure set for one website on the product\'s page wins over this one.',
        'writers_none' => 'No website takes CW\'s figures yet. They are kept here and written when a website\'s stock link is switched on.',
        'writers_on' => 'Written on: %s.',
        'filter_low' => 'Low-stock warning',
        'low_any' => 'Any',
        'low_set' => 'Set by CW',
        'low_own' => 'Each website\'s own',
        'product' => 'Product',
        'sells' => 'Sells a day',
        'safety' => 'Safety stock',
        'safety_line' => '%s spare days × %s',
        'have' => 'In stock now',
        'figure' => 'Low-stock warning',
        'own' => 'website\'s own',
        'item_figure' => 'the product\'s figure',
        'tick' => 'Change?',
        'tick_label' => 'Change %s',
        'use_safety' => 'Use the safety stock as the warning',
        'keep_own' => 'Let each website keep its own figure',
        'buttons_hint' => 'At most %s products in one click. Products where it does not make sense are left out and listed.',
        'total_one' => '1 product.',
        'total_many' => '%s products.',
        'none' => 'No product is on Reorder yet.',
        'none_text' => 'Products show here once their sales are worked out.',
        'none_filter' => 'No product matches these filters.',
        'none_filter_text' => 'Clear the filters to see every product.',
        'clear' => 'Show every product',
        'done_changed' => 'Changed: %s products.',
        'left_out' => 'Left out',
        'and_more' => 'and %s more',
    ];

    /** Why a ticked product was left out of a low-stock click (LowStockLevels::SKIP). */
    public const REORDER_LOW_SKIP = [
        'not_listed' => 'not on Reorder',
        'merged' => 'joined into another product: change that one',
        'never' => 'never suggested, so no warning is set for it',
        'zero' => 'its safety stock is 0 (no sales, no smallest stock)',
        'too_big' => 'its safety stock is too high: set it by hand',
        'already' => 'it has that figure already',
    ];

    // --- end of Pack G -------------------------------------------------------------------------------------------------------------

    /** Sales data (/ui/purchasing/sales-history, plan §6.26). */
    public const SALES = [
        'none' => 'No sales loaded yet.',
        'none_text' => 'Ask ' . self::ASK . ' to load the website sales.',
        'loaded' => 'Sales loaded',
        'loaded_line' => '%s – %s',
        'stock_days' => 'Daily stock records',
        'stock_days_none' => 'none, so sold-out days cannot be left out (the suggestion may be a bit low)',
        'stock_days_line' => '%s days (%s – %s)',
        'latest' => 'Latest website stock',
        'latest_none' => 'none',
        'latest_line' => '%s products on %s',
        'last_load' => 'Last loaded',
        'last_load_line' => 'sales up to %s, loaded %s',
        'unmatched' => 'Selling on the website but not matched to a warehouse product (top %s, last %s days)',
        'unmatched_text' => 'Their sales do not help Reorder. Ask the matching team to match these.',
        'all_matched' => 'Every website product that sold is matched to a warehouse product.',
        'product' => 'Website product',
        'sold' => 'Sold',
        'last_sale' => 'Last sale',
        'problem' => 'Problem',
        'unknown' => 'Not known to CW',
        'not_matched' => 'Not matched yet',
        'on_hold' => 'Matched, but on hold',
        'ignored' => 'Marked to ignore',
        'option' => 'option %s',
        'download' => 'Download all of them for Excel (CSV)',
        'tech' => 'Technical details (for ' . self::ASK . ')',
        'batches' => 'Loads',
        'batch' => 'Load',
        'site' => 'Website',
        'days' => 'Days',
        'state' => 'State',
        'rows' => 'Rows',
        'units' => 'Units',
        'unknown_units' => 'Units not known to CW',
        'unlinked_units' => 'Units not matched',
        'stock_rows' => 'Stock days',
        'when' => 'Made / loaded',
        'by' => 'By',
    ];

    /** Suppliers list (/ui/purchasing/suppliers, plan §6.27). */
    public const SUPPLIERS = [
        'no_bank' => 'We never keep supplier bank details here.',
        'status' => 'Can we order?',
        'any_status' => 'Any',
        'search' => 'Search by name, code or VAT number',
        'due' => 'Background check due in the next %s days',
        'filter' => 'Show',
        'new' => 'New supplier',
        'download' => 'Download for Excel (CSV)',
        'none' => 'No suppliers yet.',
        'none_text' => 'Press "New supplier" to add the first one.',
        'none_look' => 'When a buyer adds one, it shows here.',
        'none_filter' => 'No supplier matches this search.',
        'none_filter_text' => 'Clear the search to see them all.',
        'clear' => 'Show all suppliers',
        'total_one' => '1 supplier.',
        'total_many' => '%s suppliers.',
        'limit' => 'The first %s are shown. Narrow the search, or download the list for every supplier.',
        'supplier' => 'Supplier',
        'next_check' => 'Next background check',
        'products' => 'Products',
        'approved' => 'Approved',
        'approved_line' => '%s by %s',
        'abroad' => 'Abroad',
        'overdue' => 'Overdue',
        'open' => 'Open',
        'not_yet' => 'not yet',
        // Made usable by one person while the second person was switched off, and not checked by a reviewer since (M2, Y49).
        'alone_filter' => 'Approved by one person, not checked yet',
        'alone_chip' => 'Approved alone',
    ];

    /** One supplier (/ui/purchasing/suppliers/{id}, plan §6.28). */
    public const SUPPLIER = [
        'title' => '%s (%s)',
        'look_note' => 'A reviewer decides this. You do not need to do anything.',
        'dd_overdue' => 'The background check was due on %s. Check the supplier again and save the new dates. Purchase orders show this warning.',
        'route_unapproved' => 'No orders yet: a reviewer must first approve how duty stamps are put on goods from this supplier.',
        'last_note' => 'Reviewer\'s note: %s',
        'last_note_by' => 'Reviewer\'s note (%s, %s): %s',
        // Can we order from it?
        'can_order' => 'Can we order from this supplier?',
        'yes_since' => 'Yes, since %s (approved by %s).',
        'stop' => 'Stop ordering from this supplier…',
        'stop_why' => 'Why we stop (required)',
        'stop_button' => 'Stop ordering from this supplier',
        'stop_does' => 'It gets no new orders. Orders already confirmed are not affected. To order from it again, a reviewer must approve it again.',
        'waiting' => 'Not yet: it waits for a reviewer\'s OK.',
        'waiting_line' => '%s asked on %s (%s). Check by %s.',
        'waiting_locked' => 'It cannot be changed while it waits.',
        'draft' => 'This supplier is a draft. You cannot order from it until a reviewer approves it.',
        'draft_todo' => 'Fill in the missing details, then press "Ask a reviewer to approve".',
        'stopped' => 'No: we stopped ordering from it on %s (%s: %s).',
        'stopped_plain' => 'No: we stopped ordering from it.',
        'stopped_again' => 'To order from it again, a reviewer must approve it again.',
        'missing' => 'Before you ask, fill in: %s.',
        'ask' => 'Ask a reviewer to approve',
        'ask_again' => 'Ask a reviewer to approve it again',
        'ask_does' => 'A reviewer checks the details and the background check. Nothing can be ordered until they say yes.',
        // The reviewer's boxes
        'task_activation' => 'New supplier – waiting for a reviewer\'s OK (no orders until then)',
        'task_reactivation' => 'Supplier to be used again – waiting for a reviewer\'s OK (no orders until then)',
        'task_route' => 'New duty-stamp arrangement – waiting for a reviewer\'s OK (no orders until then)',
        'task_review' => 'Details changed – a reviewer checks them (orders still allowed)',
        'route_not_abroad' => '%s marked this supplier as no longer bought from abroad on %s. Orders are refused until a reviewer confirms it needs no duty-stamp arrangement.',
        'route_changed' => '%s changed how duty stamps are put on the goods on %s. Orders are refused until a reviewer approves it.',
        'review_changed' => '%s changed details that matter for the approval on %s.',
        'asked_on' => '%s asked on %s.',
        'check_by' => 'Check by %s.',
        'late' => 'Late',
        'ok_activation' => 'Yes, we can order from it',
        'does_ok_activation' => 'The supplier can get orders from now on.',
        'ok_route' => 'Yes, the arrangement is right',
        'does_ok_route' => 'Orders to this supplier are allowed again.',
        'ok_review' => 'OK: the change is fine',
        'does_ok_review' => 'The check is closed. Nothing else changes.',
        'not_ok' => 'Not OK – say why',
        'does_not_ok_activation' => 'The request is turned down: the supplier goes back to draft, and nothing can be ordered from it.',
        'does_not_ok_reactivation' => 'The request is turned down: the supplier stays stopped.',
        'does_not_ok_route' => 'The arrangement stays not approved: no order to this supplier can be confirmed.',
        'does_not_ok' => 'The supplier is stopped (no new orders) until a buyer fixes it and asks again.',
        'note_optional' => 'Note (optional)',
        'why_not_ok' => 'Why it is not OK (required)',
        'withdraw' => 'Cancel my request',
        'withdraw_does' => 'The supplier goes back to draft, so you can change it.',
        'change' => 'Change the details',
        // Details
        'details' => 'Details',
        'not_filled' => 'Not filled in: %s.',
        'legal_name' => 'Legal name',
        'company_number' => 'Company number',
        'vat_number' => 'VAT number',
        'address' => 'Address',
        'contact' => 'Contact',
        'email' => 'E-mail (orders go here)',
        'phone' => 'Phone',
        'contacts_note' => 'Other contacts',
        'payment' => 'Payment terms',
        'payment_days' => '%s (%s days)',
        'lead' => 'Delivery days',
        'every' => 'Order every … days',
        'min_order' => 'Smallest order',
        'vat' => 'VAT code',
        'currency' => 'Currency',
        'erp' => 'ERPNext name',
        'notes' => 'Notes',
        'last_changed' => 'Last changed %s by %s',
        'created' => 'Added %s by %s',
        // Background check and duty stamps
        'dd' => 'Background check',
        'dd_on' => 'Date checked',
        'dd_by' => 'Checked by',
        'dd_what' => 'What was checked',
        'dd_file' => 'Proof document',
        'dd_next' => 'Check again on',
        'none_uploaded' => 'none uploaded',
        'route' => 'Duty stamps',
        'abroad' => 'Bought from abroad',
        'yes' => 'Yes',
        'no_uk' => 'No (a UK supplier)',
        'route_how' => 'How duty stamps are put on the goods',
        'route_file' => 'Proof',
        'route_ok' => 'Reviewer\'s OK',
        'route_ok_line' => '%s by %s',
        'not_yet' => 'not yet',
        'upload' => 'Add a proof document',
        'upload_of' => 'Proof of',
        'upload_dd' => 'Background check',
        'upload_route' => 'Duty-stamp arrangement',
        'upload_file' => 'File (PDF, photo, spreadsheet or text, up to %s MB)',
        'upload_button' => 'Upload',
        'file_line' => '%s (%s)',
        // Products and orders
        'products' => 'Products',
        'products_line' => '%s products from this supplier. For %s of them this is the main supplier (Reorder buys from here).',
        'products_no_price' => '%s have no price yet.',
        'products_open' => 'Open the products',
        'products_add' => 'Add a product',
        'orders' => 'Recent purchase orders',
        'no_orders' => 'No purchase order yet.',
        'all_orders' => 'All of this supplier\'s orders',
        'new_order' => 'Start a new order',
        'order' => 'Order',
        'dated' => 'Dated',
        'expected' => 'Expected',
        'status' => 'Status',
        'total' => 'Total before VAT',
        'check' => 'Reviewer check',
        // History
        'history' => 'Approvals and checks',
        'no_history' => 'Nothing was asked for yet.',
        'h_line' => '%s – %s asked for a reviewer\'s OK (%s).',
        'h_line_review' => '%s – %s changed the details (%s).',
        'h_decided' => '%s by %s on %s.',
        'h_decided_note' => '%s by %s on %s: "%s"',
        'h_open' => 'Waiting. Check by %s.',
        'h_withdrawn' => 'Cancelled by the person who asked.',
        // A supplier made usable by one person while the second person was switched off (M2, Y49).
        'alone_title' => 'Made usable by one person',
        'alone_text' => '%s made it usable alone on %s, while the second person was switched off.',
        'alone_route_text' => '%s approved alone how duty stamps are put on its goods, on %s, while the second person was switched off.',
        'alone_checked' => '%s checked it afterwards and gave the OK on %s.',
        'alone_done' => 'Checked',
        'alone_does' => 'Check the details and the proof documents, then give your OK: the "approved alone" mark goes. Nothing else changes.',
        'alone_ok' => 'OK: I checked this supplier',
        'alone_yours' => 'You made this supplier usable alone, so another reviewer checks it.',
        'alone_look' => 'A reviewer checks it and gives the OK.',
    ];

    /** A supplier's details by name (Suppliers::LABELS keys): what is missing, what a refusal is about. */
    public const SUPPLIER_FIELD = [
        'code' => 'short code',
        'name' => 'name',
        'legal_name' => 'legal name',
        'company_number' => 'company number',
        'vat_number' => 'VAT number',
        'address_line1' => 'address',
        'address_line2' => 'address line 2',
        'city' => 'town or city',
        'postcode' => 'postcode',
        'country' => 'country',
        'contact_name' => 'contact name',
        'email' => 'e-mail',
        'phone' => 'phone',
        'contacts_note' => 'other contacts',
        'payment_terms' => 'payment terms',
        'payment_terms_days' => 'payment days',
        'default_lead_days' => 'delivery days',
        'review_days' => 'how often you order',
        'min_order_value' => 'smallest order',
        'default_vat_code' => 'VAT code',
        'is_overseas' => 'bought from abroad',
        'import_route' => 'how duty stamps are put on the goods',
        'import_route_file_id' => 'duty-stamp proof',
        'dd_checked_on' => 'background check date',
        'dd_checked_by' => 'who did the background check',
        'dd_evidence' => 'what the background check found',
        'dd_evidence_file_id' => 'background check proof',
        'dd_next_review_on' => 'next background check date',
        'notes' => 'notes',
        'erp_name' => 'ERPNext name',
        'email_or_phone' => 'e-mail or phone',
    ];

    /** The supplier form (/ui/purchasing/suppliers/new, /{id}/edit, plan §6.29). */
    public const SUPPLIER_FORM = [
        'title_new' => 'New supplier',
        'title_edit' => 'Change %s',
        'back' => 'Back to %s',
        'invalid' => 'Nothing was saved: some details need correcting (marked below).',
        'active_1' => 'If you change the name, address, e-mail, company details or background check, a reviewer checks it afterwards (you can still order).',
        'active_2' => 'If you change how duty stamps are put on goods from abroad, orders stop until a reviewer approves it.',
        'supplier' => 'Supplier',
        'name' => 'Name (required)',
        'code' => 'Short code (optional)',
        'code_hint' => 'For example ELUX. Leave it empty and CW makes one.',
        'legal_name' => 'Legal name',
        'company_number' => 'Company number',
        'vat_number' => 'VAT number',
        'address' => 'Address',
        'address_line1' => 'Address line 1',
        'address_line2' => 'Address line 2',
        'city' => 'Town or city',
        'postcode' => 'Postcode',
        'country' => 'Country',
        'country_hint' => 'Use the 2-letter code, for example GB for the United Kingdom or CN for China.',
        'contact' => 'Contact',
        'contact_name' => 'Contact name',
        'email' => 'E-mail (orders go here)',
        'phone' => 'Phone',
        'contacts_note' => 'Other contacts',
        'terms' => 'Terms',
        'payment_terms' => 'Payment terms',
        'payment_terms_hint' => 'For example "30 days end of month".',
        'payment_days' => 'Payment days',
        'lead' => 'Delivery time (days from order to delivery)',
        'every' => 'How often you order from them (every … days)',
        'min_order' => 'Smallest order in £',
        'vat' => 'VAT code for their products',
        'abroad' => 'Bought from abroad',
        'abroad_tick' => 'We buy from abroad (tick for any country outside the UK)',
        'route' => 'How are UK duty stamps put on the goods, and where? (needed for suppliers abroad)',
        'dd' => 'Background check',
        'dd_on' => 'Date checked',
        'dd_by' => 'Checked by',
        'dd_by_hint' => 'Buyers and reviewers only.',
        'dd_nobody' => 'Not checked yet',
        'you' => '%s (you)',
        'dd_what' => 'What you checked (Companies House, VAT number, duty-stamp registration, references)',
        'dd_next' => 'Check again on',
        'dd_files' => 'Proof documents are added on the supplier\'s page once it is saved.',
        'notes' => 'Notes',
        'save_new' => 'Save as draft',
        'save_new_hint' => 'You can ask a reviewer to approve it next.',
        'save' => 'Save',
        'no_bank' => 'We never keep supplier bank details here: payments stay outside this system.',
        'e_country' => 'Choose a country by its 2-letter code, for example GB.',
        'e_abroad' => 'A supplier outside the UK is bought from abroad: tick "We buy from abroad" and say how duty stamps are put on the goods.',
        'e_field' => '%s: %s.',
        // Behaviour item 9 (F382): someone else saved the supplier while this form was open. What was typed stays.
        'changed_meanwhile' => '%s changed this supplier on %s, while you were editing. Nothing was saved. '
            . 'Your changes are kept below, and what they changed is marked. Check it, then press Save again.',
        'theirs_list' => 'What they changed (saving your form now replaces it):',
        'theirs_kept' => 'Changed meanwhile by %s to: %s. Your value is kept here: check which is right.',
        'theirs_taken' => 'Changed meanwhile by %s: their value is filled in here.',
        'theirs_line' => '%s – saved now: %s (%s, %s)',
        'someone' => 'Someone else',
        'ticked' => 'ticked',
        'not_ticked' => 'not ticked',
        'empty' => 'empty',
    ];

    /** Notices after a click on a supplier (SuppliersController::NOTICES). */
    public const SUPPLIER_NOTICE = [
        'arrivals_saved' => 'Saved: what happens to the goods of its invoices on their way.', // pack J
        'arrivals_refused' => 'Not saved: choose how its invoices come in.', // pack J
        'created' => 'Supplier saved as a draft. Add the background check, then press "Ask a reviewer to approve".',
        'saved' => 'Saved.',
        'saved_review' => 'Saved. You can still order from it: a reviewer checks the change within 7 days.',
        'saved_route' => 'Saved. How duty stamps are put on the goods changed: no orders to this supplier until a reviewer approves it.',
        'unchanged' => 'Nothing changed.',
        'requested' => 'Sent: a reviewer must approve the supplier before you can order from it.',
        'withdrawn' => 'Request cancelled: the supplier is a draft again.',
        'approved' => 'Done: you said OK.',
        'rejected' => 'Done: you said not OK. Your note is kept on the supplier.',
        'deactivated' => 'Stopped: no new order can be confirmed for this supplier.',
        'evidence' => 'Done: the proof document is kept on the supplier.',
        'alone_checked' => 'Done: you gave your OK. The supplier is no longer marked "approved alone".',
    ];

    /** A supplier's products (/ui/purchasing/suppliers/{id}/items, supplier-items/{id}, plan §6.30). */
    public const SUPPLIER_ITEMS = [
        'title' => 'Products from %s',
        'intro' => 'Prices are per pack, in £, without VAT. "Box of 24" means one box holds 24 single items. "Main supplier" means Reorder buys this product from here.',
        'add' => 'Add a product',
        'download' => 'Download for Excel (CSV)',
        'back' => 'Back to %s',
        'none' => 'No products from this supplier yet.',
        'none_text' => 'Press "Add a product" to add the first one.',
        'none_look' => 'When a buyer adds one, it shows here.',
        'product' => 'Product',
        'their_code' => 'Their code',
        'pack' => 'Pack',
        'moq' => 'Smallest order (packs)',
        'steps' => 'Order in steps of',
        'lead' => 'Delivery days',
        'main' => 'Main supplier',
        'per_pack' => 'Price per pack',
        'per_item' => 'Price per item',
        'last_order' => 'Price on last order',
        'merged' => 'Joined into %s',
        'not_used' => 'No longer bought here',
        'yes' => 'Yes',
        'each' => 'each',
        'pack_of' => '%s of %s',
        'supplier_days' => 'the supplier\'s',
        // One supplier's product
        'one_title' => '%s from %s',
        'merged_note' => 'This product was joined into %s. Set the supplier up for that product instead.',
        'supplier_not_active' => 'The supplier is "%s": no order to it can be confirmed until a reviewer approves it.',
        'how' => 'How this supplier sells it',
        'supplier' => 'Supplier',
        'description' => 'Their description',
        'smallest' => 'Smallest order',
        'smallest_line' => '%s packs, in steps of %s',
        'main_yes' => 'Yes (Reorder buys from here)',
        'main_no' => 'No (a backup supplier)',
        'in_use' => 'Still bought here',
        'no' => 'No',
        'price_now' => 'Price now',
        'price_line' => '%s a pack (%s an item), %s on %s',
        'none_yet' => 'none yet',
        'po_price' => 'Price on the last order',
        'po_line' => '%s on %s',
        'make_main' => 'Make this the main supplier for this product',
        'make_main_does' => 'Reorder will buy this product from here. Any other supplier stops being its main supplier.',
        'stop_main' => 'Stop using as main supplier',
        'stop_main_does' => 'Reorder will not know where to order this product until you choose another main supplier.',
        'others' => 'Other suppliers of this product',
        'others_none' => 'None.',
        'price' => 'Record a new price',
        'price_label' => 'Price per pack in £, before VAT',
        'price_on' => 'From (empty = today)',
        'price_note' => 'Note',
        'price_button' => 'Record the price',
        'price_hint' => 'A price older than the price now goes into the history only.',
        'change' => 'Change how this supplier sells it…',
        'code' => 'Their product code',
        'unit' => 'Pack name (box, case, each)',
        'upp' => 'How many single items in one pack',
        'moq_label' => 'Smallest order (packs)',
        'steps_label' => 'Order in steps of (packs)',
        'lead_label' => 'Delivery days (empty = the supplier\'s)',
        'main_tick' => 'Main supplier for this product (Reorder buys from here)',
        'in_use_tick' => 'We still buy this from this supplier (untick to stop)',
        'save' => 'Save',
        'history' => 'Price history',
        'no_history' => 'No price recorded yet.',
        'from' => 'From',
        'items_in_pack' => 'Items in a pack',
        'source' => 'Where it came from',
        'source_po' => 'From order %s',
        'source_manual' => 'Typed in',
        'source_import' => 'From a price list',
        'source_invoice' => 'From a supplier invoice',
        'note' => 'Note',
        'recorded' => 'Recorded',
        'recorded_line' => '%s by %s',
        // Adding one
        'add_title' => 'Add a product to %s',
        'find' => 'Find our product: CW number, barcode or words of its name',
        'find_button' => 'Find',
        'find_first' => 'Find the product first: a CW number (CW-000123), a barcode, or words of its name.',
        'find_none' => 'No product matches "%s". Try fewer words or the barcode.',
        'which' => 'Which product?',
        'already' => 'Already set up with this supplier (%s). Add it again only for a different pack size.',
        'joined' => 'Joined into another product',
        'sells' => 'How the supplier sells it',
        'main_choice' => 'Is this the main supplier for this product?',
        'main_auto' => 'Yes, if it has none yet',
        'main_replace' => 'Yes, replace the current main supplier',
        'main_backup' => 'No, a backup supplier',
        'price_optional' => 'Price (optional)',
        'add_button' => 'Add the product',
        'choose_first' => 'Choose the product first (find it above). Nothing was saved.',
    ];

    /** Notices after a click on a supplier's product (SupplierItemsController::NOTICES). */
    public const SI_NOTICE = [
        'created' => 'Done: the product is set up with this supplier.',
        'saved' => 'Saved.',
        'unchanged' => 'Nothing changed.',
        'preferred' => 'Done: this is now the main supplier for this product. Reorder buys it from here.',
        'not_preferred' => 'Done: this is no longer the main supplier for this product.',
        'price' => 'Done: the price is recorded.',
        'pack_changed' => 'Saved. The pack size changed, so the price now is the newest one recorded for the new pack (none yet: record it below).',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Products: the product list, the product card, Barcodes to check, change many product cards (IM3)

    /** The product card's fields (ItemCards::FIELDS keys). */
    public const CARD_FIELD = [
        'product_type' => 'Kind of product',
        'liquid_ml' => 'Liquid (ml)',
        'nicotine_mg' => 'Nicotine (mg/ml)',
        'duty_liable' => 'Vaping duty applies',
        'single_use' => 'Single-use vape',
        'ecid' => 'ECID / GB-ID',
        'manufacturer' => 'Manufacturer',
        'brand' => 'Brand',
        'flavour' => 'Flavour',
        'discontinued' => 'Not sold any more',
    ];

    /** The product list (/ui/items/cards). */
    public const CARDS = [
        'rules' => 'A card that breaks a rule is a warning until a person confirms its details. After that it blocks the product until a person confirms the card again. '
            . 'A block stops Reorder, the confirming of orders and the booking in of deliveries. It does not stop website sales: take a blocked product '
            . 'off sale on the website by hand. A website whose stock link is on shows it as Out-Of-Stock by itself.',
        'summary_products' => '%s products, %s holding stock',
        'summary_cards' => '%s with a product card, %s confirmed (%s of those holding stock)',
        'summary_states' => '%s with a warning, %s blocked, %s not sold any more',
        'search' => 'CW number, name, brand or flavour',
        'card' => 'Product card',
        'kind' => 'Kind of product',
        'any' => 'Any',
        'not_set' => 'Not set',
        'filter_unconfirmed' => 'Not confirmed (no card, or not confirmed yet)',
        'stock' => 'Holds stock',
        'warnings' => 'Warning or blocked',
        'blocked' => 'Blocked',
        'blocked_hint' => '(take these off sale on the website by hand)',
        'discontinued' => 'Not sold any more',
        'filter' => 'Show',
        'download' => 'Download these for Excel (CSV)',
        'import' => 'Change many product cards',
        'none' => 'No product matches these filters.',
        'none_text' => 'Clear the filters to see every product.',
        'none_all' => 'No warehouse products yet.',
        'clear' => 'Show every product',
        'total_one' => '1 product.',
        'total_many' => '%s products.',
        'page' => 'Page %s of %s',
        'product' => 'Product',
        'held' => 'Stock held',
        'ml' => 'ml',
        'mg' => 'mg/ml',
        'duty' => 'Duty',
        'single_use' => 'Single-use',
        'flavour' => 'Flavour',
        'from_file' => 'from a file, not confirmed',
        'state' => 'Card',
        'blocked_line' => 'Blocked: %s',
        'warning_line' => 'Warning: %s',
        'previous' => 'Previous',
        'next' => 'Next',
    ];

    /** A product card's state (ItemCardList::STATES keys and the card section). */
    public const CARD_STATE = [
        'all' => 'Any',
        'none' => 'No card yet',
        'unconfirmed' => 'Not confirmed',
        'changed' => 'Changed since it was confirmed',
        'confirmed' => 'Confirmed',
        'warned' => 'Warning',
        'blocked' => 'Blocked',
    ];

    /** The product card section of a product page and its form (/ui/items/{id}, /ui/items/{id}/card). */
    public const CARD = [
        'title' => 'Product card',
        'hint' => 'The legal and buying details.',
        'none' => 'Nothing has been filled in for this product yet.',
        'confirmed' => 'Confirmed by %s on %s.',
        'changed' => 'Last changed by %s on %s: confirm it again. A rule the last confirmation blocked keeps blocking the product until then, whatever '
            . 'the details say now. A rule broken since is a warning until then.',
        'unconfirmed' => 'Last changed by %s on %s. Until a person confirms the details, a rule they break is a warning only.',
        'blocked' => 'Blocked: Reorder never suggests it, an order with it cannot be confirmed, and a delivery of it cannot be booked in. It is still on sale '
            . 'on the website: take it off by hand. A website whose stock link is on shows it as Out-Of-Stock by itself.',
        'still_blocked' => 'the details no longer say so, but the block stays until someone confirms the card again',
        'warning' => 'Warning: if these details are right, confirming them blocks the product.',
        'not_known' => 'not known',
        'from_file' => 'from a file, not confirmed',
        'change' => 'Change the product card',
        'fill_in' => 'Fill in the product card',
        'missing' => 'Before the card can be confirmed, fill in: %s.',
        'acknowledge' => 'I checked the packaging: these details are right, and the product breaks the rules above, so it will be blocked.',
        'confirm' => 'Confirm the card: these details are right',
        'file_flavour' => 'The flavour "%s" came from a file.',
        'confirm_flavour' => 'Confirm this flavour',
        'suggestions' => 'Suggestions',
        'no_suggestions' => 'Nothing to suggest: the matching and the matched website products say nothing the card does not already have.',
        'suggestions_text' => 'From what the matching knows and the matched website products. Nothing is used until you press "Use this".',
        'disagree' => 'The sources disagree on %s: check the box.',
        'field' => 'Detail',
        'suggestion' => 'Suggestion',
        'says_so' => 'Says so',
        'use' => 'Use this',
        'src_identity' => 'what the matching read from the website names',
        'src_listing' => 'website product "%s" (%s, option %s)',
        'src_listing_untitled' => 'website product (%s, option %s)',
        'disposable' => 'Called a "disposable" by: %s. Whether it is SINGLE-USE is for a person to answer from the box (many "disposable-style" devices sold since '
            . 'June 2025 are rechargeable and refillable): it is never filled in from this.',
        'history' => 'History of the card (%s)',
        'h_line' => '%s · %s: %s',
        'h_confirmed' => 'confirmed',
        'h_confirmed_blocked' => 'confirmed (blocked: %s)',
        'h_confirmed_lifted' => 'confirmed (block lifted: %s)',
        'h_used' => 'used a suggestion: %s',
        'h_imported' => 'imported from a file: %s',
        'h_empty' => '(empty)',
        'flavour_status' => 'flavour checked',
        // The form
        'form_title' => 'Product card of %s',
        'back' => 'Back to %s',
        'form_intro' => 'Fill in what the box or the supplier says. Leave a field empty when nobody knows yet. Nothing here is filled in by itself: '
            . 'suggestions are on the product page, each with its own button.',
        'invalid' => 'Nothing was saved: some details need correcting (marked below).',
        'changed_meanwhile' => 'What changed meanwhile (your form still shows what you typed; saving it now replaces these):',
        'changed_line' => '%s: %s',
        'is_confirmed' => 'This card is confirmed. Changing a legal detail makes it "not confirmed" until someone confirms it again. A rule the confirmation blocked '
            . 'keeps blocking the product until then, even when you correct the detail. A rule your change breaks is a warning until someone confirms the card.',
        'was_confirmed' => 'This card was confirmed before. A rule that confirmation blocked keeps blocking the product until someone confirms the card again.',
        'what' => 'What it is',
        'kind_unknown' => 'Not known',
        'ml_hint' => 'A bottle\'s contents, or what a tank, pod or device holds; to 0.1 ml, for example 10 or 2. For a device or kit sold without a tank or pod: 0.',
        'mg_hint' => '0 for nicotine-free. A percentage is fine: 2% = 20 mg/ml.',
        'duty' => 'Vaping duty applies',
        'duty_yes' => 'Yes',
        'duty_yes_hint' => 'All vaping liquids from 1 Oct 2026, nicotine-free included.',
        'no' => 'No',
        'unknown' => 'Not known yet',
        'single' => 'Single-use vape?',
        'single_text' => 'A person answers this from the box: it is never assumed from the word "disposable". Single-use vapes may not be sold in the UK, so "yes" '
            . 'blocks the product once the card is confirmed.',
        'single_yes' => 'Yes, single-use',
        'single_no' => 'No (rechargeable and refillable, or not a device)',
        'maker' => 'Who makes it',
        'ecid_hint' => 'The notification number on the box or on the MHRA list, for example 12345-16-12345.',
        'brand_hint' => 'As on the box.',
        'flavour_hint' => 'A flavour you type here is taken as checked.',
        'flavour_file' => 'From a file, not confirmed. Saving this form leaves it so: confirm it on the product page, or type the right one.',
        'buying' => 'Buying',
        'discontinued' => 'Not sold any more: never suggest it on Reorder',
        'discontinued_hint' => 'A buyer can still order it on purpose.',
        'save' => 'Save the product card',
        'cancel' => 'Cancel',
        'saved_twice' => 'You already saved this form once. What you typed is kept below: check it and press Save again.',
    ];

    /** A refusal on the product card, its import and the barcodes, by error code (the catalogue services' messages stay the API's). */
    public const CARD_ERROR = [
        'card_changed' => 'Not saved: someone changed this product card while you had it open. Your form still shows what you typed: check what changed below, then save again.',
        'card_changed_page' => 'Nothing was changed: someone changed this product card meanwhile. Look at it again.',
        'no_card' => 'Not confirmed: nothing is filled in for this product yet. Fill in the product card first.',
        'card_incomplete' => 'Not confirmed: fill in %s first.',
        'card_breaches' => 'Not confirmed: these details break the law (%s). Confirming them BLOCKS the product. Tick "I checked the packaging" to confirm all the same, '
            . 'or correct the details.',
        'admin_cannot_edit' => 'Admin cannot change product cards or barcodes. Nothing was changed.',
        'merged_item' => 'Nothing was changed: this product was joined into another one. Change that product instead.',
        'proposal_gone' => 'Nothing was changed: that suggestion is no longer offered (the card or its website products changed). Look again.',
        'review_closed' => 'Nothing was changed: this was decided already. Reload the page.',
        'form_already_saved' => 'You already saved this form once. What you typed is kept below: check it and press Save again.',
        'bad_units' => 'Items per scan is a whole number from 1 (one item) to %s (for example 10 for an outer case of 10). Nothing was saved.',
        // The barcode services' refusals, in the page's words (their messages stay the API's).
        'bad_decision' => 'Choose one of the answers listed for this barcode. Nothing was saved.',
        'barcode_exists' => 'This product has this barcode already. Nothing was changed.',
        'barcode_on_other_item' => 'This barcode belongs to %s already, and a barcode belongs to one product. If it belongs here, remove it from %s first. '
            . 'If both products\' website products carry it, decide it in Barcodes instead. Nothing was changed.',
        // "Change many product cards": a file with columns the import does not know.
        'unknown_columns' => 'Nothing was imported: the file has columns the product cards do not have: %s. Use the columns of the downloaded product list.',
    ];

    /**
     * The selling mode on the websites, on a product page (IM10, SellingModeController): what each website shows for a product whose
     * stock is not counted yet. The mode names (In-Stock, From-Warehouse, Out-Of-Stock) are the websites' own labels, kept as they are.
     */
    public const SELLING = [
        'title' => 'Selling mode on the websites',
        'counted' => 'This product\'s stock is counted and protected (%s), so every website sells it by that rule.',
        'legacy' => 'Until this product\'s stock is counted, each website can have its own selling mode. A website takes it from here only once its stock '
            . 'link is on; until then this is what it will get.',
        'blocked' => 'Blocked by its product card: every website whose stock link is on shows it as Out-Of-Stock, whatever the mode below.',
        'website' => 'Website',
        'link' => 'Stock link',
        'listings' => 'Website products',
        'mode' => 'Selling mode it gets',
        'threshold' => 'Low-stock warning at',
        'set_by' => 'Set by',
        'receipts' => 'deliveries set it',
        'on' => 'on',
        'off' => 'off',
        'none_linked' => 'none matched',
        'unlinked' => 'nothing (no website product matched)',
        'own' => 'the website keeps its own',
        'backorders' => '+ back-orders',
        'before' => 'before: %s',
        'site_own' => 'the website\'s own',
        'never_set' => 'never set here',
        'by_receipt' => 'a delivery',
        'by_switch' => 'the switch',
        'change' => 'Change the selling mode',
        'on_sites' => 'On these websites',
        'all_sites' => 'All websites',
        'optional' => '(optional: leave it empty to keep it)',
        'why' => 'Why',
        'save' => 'Save the selling mode',
        // The plain-words pass of the section (U89).
        'help_label' => 'selling mode',
        'link_on' => 'Stock link on',
        'link_off' => 'Stock link off',
        'link_waiting' => 'Stock link switched on: starts when the website connection is live',
        'option' => 'option %s',
        'option_sale' => 'option %s (%s)',
        'mode_meaning' => '%s: %s',
        'why_hint' => '3 to 500 characters, for example: back in stock from the next delivery.',
        'save_does' => 'Each ticked website whose stock link is on takes it the next time it reads the warehouse stock.',
        'set_line' => '%s, %s, %s',
    ];

    /** The selling-mode switch's refusals in words, by the service's error code (SiteModes::set; ItemCardsController::plain). */
    public const SELLING_ERROR = [
        'bad_mode' => 'Choose In-Stock, From-Warehouse or Out-Of-Stock. Nothing was changed.',
        'bad_reason' => 'Say why the selling mode changes, in 3 to 500 characters. Nothing was changed.',
        'bad_threshold' => 'The low-stock warning is a whole number from 0 to %s, or empty to keep it. Nothing was changed.',
        'no_sites' => 'Tick the websites the selling mode is for, or "All websites". Nothing was changed.',
        'unknown_site' => 'One of the ticked websites does not exist any more. Reload the page. Nothing was changed.',
        'protected_item' => 'Nothing was changed: this product\'s stock is counted and protected, so every website sells it by its stock rule. Change the stock rule instead.',
        'selling_mode_changed' => 'Nothing was changed: someone changed this product\'s selling mode a moment ago. Look at it again.',
    ];

    /** Notices after the selling-mode form (SellingModeController::NOTICES). */
    public const SELLING_NOTICE = [
        'selling_mode_set' => 'Selling mode saved. Each website whose stock link is on takes it the next time it reads the warehouse stock.',
        'selling_mode_unchanged' => 'Nothing changed: the ticked websites already had this selling mode.',
    ];

    /**
     * channel.mode => how far a website's connection to the warehouse is (the selling-mode table). Not the stock link: that is CW's
     * site writer switch, and it writes only while the connection is live (SellingModeController::vars).
     */
    public const SITE_SYNC = [
        'off' => 'website connection not started',
        'shadow' => 'website connection on trial',
        'live' => 'website connection live',
    ];

    /** Notices after a click on a product page (ItemController::NOTICES). */
    public const CARD_NOTICE = [
        'card_saved' => 'Saved: the product card.',
        'card_saved_unconfirmed' => 'Saved. A legal detail changed, so the card is no longer confirmed: check it and confirm it again.',
        'card_unchanged' => 'Nothing changed.',
        'accepted' => 'Done: the product card now has this suggestion.',
        'confirmed' => 'Confirmed: the product card. If a person confirms details that break a rule, the product is blocked instead of warned about.',
        'confirmed_blocked' => 'Confirmed. The product breaks a rule, so it is now BLOCKED: Reorder never suggests it, an order with it cannot be confirmed, '
            . 'and a delivery of it cannot be booked in. It is still on sale on the website: take it off by hand. A website whose stock link is on shows it as '
            . 'Out-Of-Stock by itself. It stays blocked until someone corrects the card and confirms it again.',
        'confirmed_lifted' => 'Confirmed. The details no longer break the rules the last confirmation blocked, so the product is no longer blocked.',
        'already_confirmed' => 'The product card was already confirmed.',
        'barcode_added' => 'Done: the barcode is added.',
        'barcode_added_unusable' => 'Added, but it cannot be used yet: it waits in Barcodes.',
        'barcode_removed' => 'Done: the barcode is removed. The barcode sync will not add it back to this product.',
        'units_saved' => 'Saved: items per scan.',
        'units_unchanged' => 'Nothing changed.',
        'decided' => 'Done. The barcode sync will not ask about this barcode and product again unless something changes.',
    ];

    /** Change many product cards (/ui/items/cards/import). */
    public const CARD_IMPORT = [
        'back' => 'Back to the product list',
        'how' => 'Download the product list for Excel, fill in the columns, save it as CSV and import it here. Check it first: nothing is saved until you choose '
            . '"Save the changes", and a file with any problem changes nothing at all.',
        'checked' => 'Checked (nothing was saved): %s',
        'imported' => 'Imported: %s',
        'not_imported' => 'Nothing was imported: %s',
        'counts' => '%s rows: %s %s, %s unchanged, %s problems.',
        'would_change' => 'cards would change',
        'changed' => 'cards changed',
        'ignored' => 'Read past (the download\'s own columns, never imported): %s.',
        'run' => 'Import number %s.',
        'fix' => 'Correct these and import the whole file again:',
        'fix_first' => 'Correct these and import the whole file again (the first %s of %s are shown):',
        'row' => 'Row %s: %s',
        'row_code' => 'Row %s (%s): %s',
        'row_column' => 'Row %s (%s), %s: %s',
        'what_changed' => 'What changed (%s)',
        'what_would' => 'What would change (%s)',
        'change_line' => 'Row %s: %s · %s',
        'unconfirmed' => 'no longer confirmed',
        'file' => 'The file',
        'file_label' => 'CSV file',
        'file_hint' => 'Up to %s MB, and up to %s products changed in one import. Unchanged rows cost nothing, so a whole download is fine. For more changes, import it in parts.',
        'check' => 'Check only: show what would change, save nothing',
        'apply' => 'Save the changes',
        'button' => 'Import',
        'columns' => 'The columns',
        'col_code' => 'The CW number (CW-000123). Required.',
        'col_version' => 'From the download. When it is filled in, a row whose card someone changed since the download is refused, not overwritten.',
        'col_kind' => 'One of: %s.',
        'col_numbers' => 'Liquid to 0.1 ml; nicotine in mg/ml, or a percentage (2% = 20 mg/ml); yes or no for duty, single-use and not sold any more.',
        'col_rest' => 'An empty cell changes nothing (clear a value on the product page). A flavour from a file is "not confirmed" until someone confirms it '
            . 'on the product page. A file never confirms a card.',
        'no_file' => 'Choose the CSV file to import. Nothing was imported.',
        'too_large' => 'The file is too big (over %s MB). Nothing was imported. Import it in parts (filter the download by brand or by stock).',
        'upload_failed' => 'The file did not arrive completely. Nothing was imported: try again.',
    ];

    /** Barcodes on a product page and Barcodes to check (/ui/items/barcodes). */
    public const BARCODE = [
        'title' => 'Barcodes',
        'none' => 'No barcode is known for this product.',
        'barcode' => 'Barcode',
        'units' => 'Items per scan',
        'units_of' => 'Items per scan of %s',
        'source' => 'Where it came from',
        'usable' => 'Can be used',
        'yes' => 'Yes',
        'no' => 'No',
        'outer' => 'outer case',
        'save' => 'Save',
        'remove' => 'Remove…',
        'remove_text' => 'Removes %s from %s. The barcode sync will not add it back to this product (adding it by hand stays possible).',
        'remove_why' => 'Why (optional)',
        'remove_button' => 'Remove this barcode',
        'open_reviews' => 'Waiting in Barcodes:',
        'add' => 'Add a barcode',
        'add_hint' => 'Scan or type it',
        'add_units' => 'Items per scan',
        'add_button' => 'Add barcode',
        'add_text' => 'An outer case has its own barcode with the items it holds ("this barcode = 10 items"). A barcode belongs to one product.',
        'open_other' => 'Open %s',
        'that_product' => 'that product',
        // Barcodes to check
        'open' => 'Waiting (%s)',
        'decided' => 'Decided',
        'find' => 'Barcode',
        'find_button' => 'Find',
        'nothing' => 'Nothing to decide.',
        'nothing_text' => 'When the barcode sync cannot place a barcode, it shows here.',
        'nothing_decided' => 'Nothing decided yet.',
        'on_product' => 'On product',
        'in_stock' => '(%s in stock)',
        'joined' => 'joined into %s',
        'joined_note' => 'This product was joined into the website product\'s product, and the barcode came with it: moving it there is the usual answer (chosen below).',
        'removed_from' => 'Removed from',
        'carried_by' => 'On a website product of',
        'website_product' => 'Website product',
        'listing_line' => '%s, %s (1 sale = %s; now: %s)',
        'per_scan' => '%s per scan',
        'now' => 'Now',
        'now_none' => 'No product has it',
        'now_line' => 'On %s, %s per scan, %s',
        'can_use' => 'can be used',
        'cannot_use' => 'cannot be used',
        'found' => 'Found',
        'found_line' => '%s by %s',
        'found_cw' => 'Set up by CW on %s',
        'decision' => 'Decided',
        'decision_line' => '%s · %s · %s',
        'decide' => 'Decide',
        'units_label' => 'Items per scan',
        'units_hint_listing' => 'When it is added or moved. Empty = the website product\'s %s.',
        'units_hint_now' => 'When it is added or moved. Empty = as it is now.',
        'note' => 'Note (optional)',
        'save_decision' => 'Save the decision',
        'limit' => 'The first %s are shown.',
        'set_up' => 'set up by CW',
    ];

    /** Where a barcode came from (sku_barcode.source). */
    public const BARCODE_SOURCE = [
        'manual' => 'Added by a person',
        'listing_sync' => 'From a website product',
        'origin_listing' => 'From the website product it was made from',
        'review' => 'From Barcodes',
        'reband' => 'From the computer check',
        'in_review' => 'waits in Barcodes',
    ];

    /** Why a barcode waits in Barcodes (BarcodeReviews::REASONS keys). */
    public const BARCODE_REASON = [
        'on_another_item' => 'On another product',
        'multipack_listing' => 'Pack or single item?',
        'removed' => 'Removed by a person',
    ];

    /** The answers in Barcodes (BarcodeReviews::DECISIONS and RECORDED keys). */
    public const BARCODE_DECISION = [
        'keep_holder' => 'It belongs to the product that has it (the website product carries a wrong barcode)',
        'move' => 'It belongs to the website product\'s product: move it there',
        'unusable' => 'Shared by both: keep it, but do not use it',
        'add' => 'Add it to the website product\'s product',
        'dismiss' => 'Do not add it',
        'removed' => 'Removed from the product',
        'moved_away' => 'Moved to another product in Barcodes',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Deliveries (IM6 Receive + invoice, the goods-in bench, incidents; docs/decisions.md I125-I179, U85-U90). A receipt is a
    // "delivery" (one supplier invoice), posting it is "booking it in", units are "items", MAIN is "into stock", VERIFY is
    // "set aside to check", UNSTAMPED is "the unstamped quarantine". The services' messages stay theirs (Ui\ReceiptWords says
    // the plan's problems and warnings again in these words; a sentence it does not know is shown as the service wrote it).

    /** A delivery's state (document.status of a GRN). */
    public const RECEIPT_STATE = [
        'draft' => 'Not booked in yet',
        'awaiting_approval' => 'Waiting for OK',
        'posted' => 'Booked in',
        'reversed' => 'Taken back',
        'cancelled' => 'Cancelled before booking in',
    ];

    /** How far the goods-in bench got with a delivery that is not booked in yet (bench list, editor, receipts list). */
    public const BENCH_STATE = [
        'todo' => 'Waiting for the bench',
        'part' => 'Bench check not finished',
        'done' => 'Checked at the bench',
        'refused' => 'Bench: paperwork not right',
    ];

    /** Where an incident's items went (Incidents::DISPOSITIONS keys). */
    public const INCIDENT_WHERE = [
        'verify' => 'Set aside to check',
        'quarantine' => 'In the unstamped quarantine',
        'refused' => 'Refused at the door',
        'not_received' => 'Not delivered',
    ];

    /** The kind of problem an incident is (Incidents::KINDS keys). */
    public const INCIDENT_KIND = [
        'unstamped' => 'No duty stamp',
        'damaged' => 'Damaged',
        'wrong_item' => 'Wrong product',
        'short' => 'Short (not delivered)',
        'over' => 'Extra (more than the paperwork)',
    ];

    /** incident.status. */
    public const INCIDENT_STATE = [
        'open' => 'Open',
        'resolved' => 'Dealt with',
        'dismissed' => 'Nothing needed',
    ];

    /** What a file attached to a delivery is (GoodsReceipts::FILE_ROLES keys). */
    public const RECEIPT_FILE = [
        'supplier_invoice' => 'Supplier invoice (PDF or photo)',
        'delivery_note' => 'Delivery note',
        'photo' => 'Photo',
        'evidence' => 'Duty proof (made or imported before 1 Oct 2026)',
    ];

    /** The kind of UK duty stamp (ReceiptPlan::STAMP_TYPES keys). */
    public const STAMP_TYPE = [
        'digital' => 'Digital stamp',
        'transitional' => 'Transitional stamp',
    ];

    /** What happens to the unstamped items of a line (GoodsReceipts::UNSTAMPED_ACTIONS). */
    public const UNSTAMPED_ACTION = [
        'quarantine' => 'Keep them apart in the unstamped quarantine (they go back to the supplier)',
        'refuse' => 'Refuse them at the door (not taken in)',
        'accept_pre_october' => 'Take them in on the supplier\'s proof they were made or imported before 1 Oct 2026 (sell, send back or destroy them by 31 Mar 2027)',
    ];

    /** Why a delivery gives a product its selling mode (the mode's source: SellingModes, ReceivingController). */
    public const MODE_SOURCE = [
        'last' => 'its last mode',
        'previous' => 'its mode before it went Out-Of-Stock',
        'fallback' => 'no earlier mode known',
        'chosen' => 'chosen on this delivery',
        'kept' => 'kept: none of it went into stock',
    ];

    /** The websites' three selling modes, what each means (their names are the websites' own labels and stay). */
    public const MODE_MEANING = [
        'In-Stock' => 'sells whatever the stock figure says',
        'From-Warehouse' => 'sells while there is stock',
        'Out-Of-Stock' => 'does not sell',
    ];

    /** Receive + invoice: the list of deliveries and "Start a new delivery" (receipts.php). */
    public const RECEIVING = [
        'how' => 'How a delivery goes',
        'how_1' => 'The purchasing desk starts a delivery for each supplier invoice: copy the purchase order, import the supplier\'s sheet, or scan the products.',
        'how_2' => 'The goods-in bench checks the goods against the paperwork: the UK duty stamp, and anything short, extra, damaged or wrong.',
        'how_3' => 'The desk attaches the invoice (a PDF or a photo) and books the delivery in. The stock is added at once.',
        'how_4' => 'A reviewer checks it within 3 days. Each problem the bench found becomes an incident to close.',
        'posting_label' => 'book in',
        'new' => 'Start a new delivery',
        'new_text' => 'One delivery for each supplier invoice. Choose the purchase order it is against, or only the supplier.',
        'no_supplier' => 'No supplier to receive from yet',
        'no_supplier_text' => 'A buyer adds the supplier, and a reviewer approves it, before anything can be received from it.',
        'po' => 'Against a purchase order (optional)',
        'no_po' => 'No purchase order',
        'po_option' => '%s · %s · %s items still to come (%s)',
        'supplier' => 'Supplier (not needed with a purchase order)',
        'po_supplier' => 'The order\'s supplier',
        'not_ready' => '%s (%s)',
        'invoice' => 'Supplier invoice number (you can add it later)',
        'copy' => 'Copy the order\'s lines still to come (then change what arrived differently)',
        'start' => 'Start the delivery',
        'show' => 'Show',
        'all' => 'All deliveries',
        'any_supplier' => 'Any supplier',
        'search' => 'Number, invoice or order',
        'filter' => 'Filter',
        'sample' => 'A sample supplier sheet (CSV)',
        'total_one' => '1 delivery, newest first.',
        'total_many' => '%s deliveries, newest first.',
        'state_checked' => 'Checked at the bench, not booked in yet',
        'state_refused' => 'Refused at the bench, not cancelled yet',
        'none' => 'No deliveries yet',
        'none_desk' => 'Start the first one above when goods arrive.',
        'none_text' => 'Deliveries appear here once the purchasing desk starts them.',
        'none_filter' => 'No delivery matches',
        'none_filter_text' => 'Change the filter, or clear it to see every delivery.',
        'clear' => 'Show all deliveries',
        'delivery' => 'Delivery',
        'status' => 'Status',
        'arrived' => 'Arrived',
        'size' => 'Size',
        'bench' => 'Goods-in bench',
        'check' => 'Reviewer check',
        'problems' => 'Problems',
        'open' => 'Open',
        'no_number' => 'No number yet (not booked in)',
        'invoice_no' => 'invoice %s',
        'no_invoice' => 'no invoice number yet',
        'order_no' => 'order %s',
        'paper' => 'Keyed from a paper sheet',
        'size_one' => '1 line, %s items',
        'size_many' => '%s lines, %s items',
        'open_incidents_one' => '1 incident open',
        'open_incidents' => '%s incidents open',
        'limit' => 'Only the newest %s are shown: narrow the filter.',
    ];

    /** One delivery: the read-only page (receipt.php), the editor (receipt_edit.php) and the files (receipt_files.php). */
    public const RECEIPT = [
        'title' => '%s – %s',
        'title_draft' => 'Delivery from %s (not booked in yet)',
        'draft_ref' => 'delivery #%s (not booked in yet)',
        'title_plain' => 'Delivery from %s',
        'to_bench' => 'Check it at the goods-in bench',
        'only_keyer' => 'Only %s, who keyed this delivery, changes its products. Anyone who receives goods can check it at the goods-in bench, set its supplier invoice and book it in.',
        'only_keyer_look' => 'Only %s, who keyed this delivery, changes its products.',
        'reversed_by' => 'Taken back by %s: its stock, what it received against the order and its incidents were taken back.',
        'open_reversal' => 'Open its record',
        'rejected' => 'A reviewer said this delivery is not OK.',
        // The reviewer's box (the review of a booked-in delivery, or of its reversal).
        'check_title' => 'Check this delivery',
        'check_reversal_title' => 'Check this reversal',
        'check_text' => 'Booked in on %s by %s. Check it by %s.',
        'check_text_reversal' => 'Taken back on %s by %s. Check it by %s.',
        'late' => 'Late',
        'ok' => 'OK: it is right',
        'ok_does' => 'The check is closed. Nothing else changes.',
        'not_ok' => 'Not OK: take the delivery back',
        'not_ok_does' => 'The delivery is taken back: its stock, what it received against the order and its incidents. The desk keys it again if the goods are here.',
        'not_ok_reversal' => 'Not OK (recorded only)',
        'not_ok_reversal_does' => 'Your answer is recorded. Nothing changes: a reversal is never undone. The desk keys the delivery again if needed.',
        'note_optional' => 'Note (optional)',
        'why_not_ok' => 'Why it is not OK (needed)',
        // Pack PO (PO8): a delivery of an order closed afterwards is taken back like any other.
        'po_closed' => '%s was closed after this delivery. "Not OK" takes the delivery back; the order stays closed while some of it is still delivered, '
            . 'and waits for the goods again when none is.',
        // Pack PO (PO10, review finding 6): one supplier invoice that covers more than one delivery of the same order.
        'split_title' => 'One invoice for more than one delivery?',
        'split_offer' => 'This invoice number is already on another delivery of %s. If the supplier sent one invoice for several deliveries of that order, '
            . 'say that this is a later delivery on it.',
        'split_button' => 'Yes: this is a later delivery on the same invoice',
        'split_hint' => 'If it is the same delivery keyed twice, do not press it: cancel one of the two deliveries instead.',
        'split_part' => '(delivery %s on this invoice)',
        // The facts.
        'about' => 'About this delivery',
        'invoice' => 'Supplier invoice',
        'invoice_dated' => '%s, dated %s',
        'no_invoice' => 'Not typed in yet',
        'delivery_note' => 'Delivery note',
        'po' => 'Purchase order',
        'no_po' => 'None',
        'arrived' => 'The goods arrived',
        'paper' => 'Keyed from a paper receiving sheet',
        'bench' => 'Goods-in bench check',
        'bench_not_yet' => 'Not done yet',
        'bench_ok' => 'Supplier and paperwork look right',
        'bench_not_ok' => 'Supplier or paperwork NOT right',
        'bench_when' => '%s, %s',
        'keyed_by' => 'Keyed by',
        'booked' => 'Booked in',
        'booked_when' => '%s by %s',
        'check' => 'Reviewer check',
        'cancelled' => 'Cancelled',
        'cancelled_when' => '%s by %s: %s',
        'note' => 'Note',
        // Where the items went.
        'where' => 'Where the items went',
        'where_draft' => 'Where the items will go when it is booked in',
        'where_label' => 'where the items go',
        'into_stock' => 'Into stock',
        'set_aside' => 'Set aside to check',
        'quarantine' => 'Unstamped quarantine',
        'refused' => 'Refused at the door',
        'short' => 'Short (not delivered)',
        'value' => 'Value (no VAT)',
        'duty' => 'Expected duty',
        'duty_note' => '%s (for information only: 22p a ml, rounded down for each item)',
        'size' => 'On the paperwork',
        'size_one' => '1 line, %s items',
        'size_many' => '%s lines, %s items',
        // The lines.
        'lines' => 'Products on this delivery',
        'line' => 'Line %s',
        'product' => 'Product',
        'their_code' => 'their code %s',
        'pack' => 'Pack',
        'packs' => 'Packs',
        'items' => 'Items',
        'per_pack' => 'Price a pack',
        'per_pack_edit' => 'Price a pack (£, for now)',
        'line_value' => 'Value',
        'order_line' => 'Order line',
        'order_line_no' => 'line %s',
        'not_against' => 'not against the order',
        'went' => 'Where they went',
        'will_go' => 'Where they will go',
        'went_stock' => '%s into stock',
        'went_aside' => '%s set aside to check',
        'went_quarantine' => '%s to the unstamped quarantine',
        'went_refused' => '%s refused at the door',
        'went_short' => '%s short (not delivered)',
        'duty_stamp' => 'Duty stamp',
        'selling' => 'Selling mode',
        'selling_edit' => 'Selling mode when booked in',
        'reaches' => 'The selling mode chosen here reaches %s when the delivery is booked in.',
        'reaches_off' => '%s (once its stock link is on)',
        'reaches_none' => 'The selling mode chosen here reaches no website yet.',
        'stock_now' => 'In stock now',
        'stock_line' => '%s (%s free to sell)',
        'bench_col' => 'Goods-in bench',
        'note_col' => 'Note',
        'stamp_needed' => 'Duty stamp needed',
        'stamp_not_needed' => 'No stamp needed',
        'duty_unknown' => 'Duty question not answered',
        'stamp_not_checked' => 'Stamp not checked yet',
        'stamp_on' => 'Stamp on the pack',
        'stamp_off' => 'No stamp on the pack',
        'duty_about' => 'duty about %s',
        'mode_kept' => 'unchanged',
        'mode_with' => '%s (%s)',
        'mode_now' => 'now: %s',
        'mode_none' => 'no mode known yet',
        'mode_default' => 'Same as now: %s (%s)',
        'mode_choice' => '%s (%s)',
        'waiting_bench' => 'Waiting for the bench',
        'checked' => 'Checked',
        'finding' => '%s %s',
        'finding_short' => 'short',
        'finding_over' => 'extra',
        'finding_damaged' => 'damaged',
        'finding_wrong' => 'wrong product',
        'finding_unstamped' => 'no stamp',
        // Incidents and the reviewer checks of this delivery.
        'incidents' => 'Problems found',
        'incident_line' => 'Line %s, %s: %s items %s',
        'incident_where' => '%s',
        'close_them' => 'Close them in Issues',
        'checks' => 'Reviewer checks',
        'check_line' => '%s: opened %s by %s, check by %s.',
        'check_decided' => '%s by %s.',
        'check_decided_note' => '%s by %s: %s',
        'of_delivery' => 'Check of the delivery',
        'of_reversal' => 'Check of its reversal',
        // Take it back.
        'reverse' => 'Take this delivery back (reverse it)…',
        'reverse_title' => 'Take this delivery back',
        'reverse_does' => 'A correction: the stock this delivery added, what it received against the order and its open incidents are taken back, and its '
            . 'invoice number can be keyed again. A reviewer checks the reversal.',
        'reverse_why' => 'Why',
        'choose_reason' => '— choose a reason —',
        'reverse_note' => 'Note',
        'reverse_button' => 'Take it back',
        // Before it can be booked in (the checklist), and booking in.
        'ready_title' => 'Before it can be booked in',
        'ready' => 'Ready to book in.',
        'ready_tag' => 'Ready to book in',
        'to_settle_one' => '1 thing to settle before booking in',
        'to_settle' => '%s things to settle before booking in',
        'refused_see' => 'What stops it is listed under "Before it can be booked in".',
        'book_in' => 'Book this delivery in',
        'book_in_does' => 'The stock is added at once. A reviewer checks it within 3 days.',
        'book_in_rule' => 'Only the person who keyed a delivery changes its products. Anyone who receives goods can check it at the goods-in bench, set its supplier invoice and book it in.',
        // The supplier invoice, set by someone who did not key the delivery.
        'set_invoice' => 'The supplier invoice',
        'invoice_number' => 'Supplier invoice number (needed before booking in)',
        'invoice_date' => 'Invoice date',
        'delivery_note_no' => 'Delivery note number',
        'set_invoice_button' => 'Save the supplier invoice',
        'set_invoice_hint' => 'Anyone who receives goods can set the supplier invoice of a delivery someone else started (at the bench, from the delivery note). '
            . 'Attach the invoice copy under Files. The products stay with the person who keyed them. Whoever sets the invoice of a delivery they did not key '
            . 'does not check it as a reviewer.',
        // The editor.
        'delivery' => 'The delivery',
        'supplier' => 'Supplier',
        'supplier_fixed' => 'It changes only while the delivery has no products.',
        'against' => 'Against the order',
        'po_fixed' => 'It changes only while no line is against the order.',
        'po_none' => 'None',
        'po_option' => '%s (%s items still to come)',
        'po_line_option' => 'line %s: %s, %s items still to come',
        'arrived_at' => 'The goods arrived (UK time)',
        'paper_sheet' => 'Keyed from a paper receiving sheet (CW was down)',
        'backdate' => 'Why it is keyed late (only when the goods arrived before the day this delivery was started)',
        'note_label' => 'Note',
        'add' => 'Add a product',
        'scan' => 'Scan a barcode, or type this supplier\'s code, a CW number or words of the name',
        'scan_packs' => 'Packs',
        'scan_price' => 'Price of one pack (£, optional)',
        'add_button' => 'Add',
        'scan_hint' => 'Enter adds the product and saves every change below. A barcode adds the items it stands for: a single item\'s barcode 1, a case barcode its '
            . 'case. Scanning the same product again adds to its line.',
        'choose' => 'Which product is "%s"?',
        'choice_set_up' => 'Set up with this supplier: their code %s, %s',
        'choice_set_up_no_code' => 'Set up with this supplier: %s',
        'choice_single' => 'Single items (packs of 1, not one of this supplier\'s packs)',
        'too_many' => 'This delivery has too many lines for one form (about %s fit), so they are shown here without boxes to type in. Change them with the sheet '
            . 'import ("replace every line"). The delivery details and a scan still work here.',
        'no_lines' => 'No products yet. Copy the order, import the supplier\'s sheet, or scan the first one.',
        'items_per_pack' => 'Items per pack',
        'lines_hint' => 'Packs 0 removes a line. Items = packs × items per pack. The price is for now: it is settled when the supplier invoice is matched. '
            . '"Same as now": the product keeps its last selling mode; one that is Out-Of-Stock goes back to the mode it had before.',
        'save' => 'Save',
        'save_book' => 'Save and book in',
        'save_book_does' => 'Saves this form, then books in exactly what it shows.',
        'copy_title' => 'Copy the order',
        'copy_button' => 'Copy the lines of %s still to come',
        'copy_hint' => 'The order\'s lines that still expect items and are not on this delivery yet. Then change what arrived differently.',
        'sheet' => 'Import the supplier\'s invoice or packing list (a spreadsheet)…',
        'sheet_title' => 'Import the supplier\'s sheet',
        'sheet_file' => 'Sheet (CSV or Excel XLSX, at most %s MB)',
        'sheet_mode' => 'What to do with it',
        'sheet_append' => 'Add to the lines (packs add up on the same product)',
        'sheet_replace' => 'Replace every line (the bench\'s findings go with them)',
        'sheet_button' => 'Import',
        'sheet_hint' => 'The header row may sit below the supplier\'s letterhead. Columns read: a code (code, item code, SKU …), a barcode (EAN) or a CW number; the '
            . 'number of packs (qty, quantity); and if there, the pack size, the items, the price of a pack and a description. Rows without a code (totals, '
            . 'carriage) are skipped and listed; any other problem imports nothing.',
        'import_failed' => 'The sheet was not imported',
        'skipped' => 'Rows of the sheet without a product code (%s): skipped',
        'not_approved_supplier' => '%s is not approved yet. You can key the delivery, but it is booked in only once a reviewer has approved the supplier.',
        'cancel' => 'Cancel this delivery…',
        'cancel_title' => 'Cancel this delivery',
        'cancel_does' => 'Nothing is booked by a delivery that is not booked in. Cancelling it frees its invoice number. A delivery refused at the door is cancelled, '
            . 'not booked in.',
        'cancel_why' => 'Why',
        'cancel_button' => 'Cancel the delivery',
        // Files (receipt_files.php).
        'files' => 'Files',
        'files_none' => 'No file yet. The supplier\'s invoice (a PDF, or a photo of a paper invoice) must be attached before the delivery is booked in.',
        'file_line' => '%s, %s, %s',
        'file_what' => 'What it is',
        'file_label' => 'File (PDF, JPEG or PNG, at most %s MB: a camera photo is made smaller first)',
        'file_note' => 'Note (optional)',
        'attach' => 'Attach',
        'files_kept' => 'Files are kept for at least 7 years and never removed.',
        'kind_pdf' => 'PDF',
        'kind_jpeg' => 'Photo (JPEG)',
        'kind_png' => 'Picture (PNG)',
        'kind_other' => 'File',
        // The browser's own prompts (app.js, through data- attributes).
        'unsaved' => 'You have changes on this page that are not saved. They are lost if you go on. Press Cancel, then save them first. (OK goes on without them.)',
        'busy' => 'The photo is still being made smaller: wait a moment, then press the button again.',
        'too_big' => 'This file is too big: at most %s MB. Choose a smaller one.',
        // Refused by its creator's rule: who keyed it, booked it in.
        'refusal_posted' => 'You booked this delivery in, so another reviewer must check it.',
        'refusal_created' => 'You keyed this delivery, so another reviewer must check it.',
        'refusal_bench' => 'You checked this delivery at the goods-in bench, so another reviewer must check it.',
        'refusal_invoice' => 'You saved this delivery\'s supplier invoice, so another reviewer must check it.',
    ];

    /** The goods-in bench: the list of deliveries to check (bench_list.php) and the check of one (receipt_bench.php). */
    public const BENCH = [
        'none' => 'Nothing to check',
        'none_text' => 'No delivery is waiting for the bench. A delivery shows here once the purchasing desk starts it.',
        'delivery' => 'Delivery',
        'status' => 'Status',
        'arrived' => 'Arrived',
        'size' => 'Size',
        'progress' => 'Checked so far',
        'keyed_by' => 'Keyed by',
        'open' => 'Check this delivery',
        'invoice' => 'Invoice %s',
        'no_invoice' => 'No invoice number yet',
        'order' => 'order %s',
        'lines_checked' => '%s of %s lines checked',
        'paperwork_ok' => 'paperwork looks right (%s)',
        'paperwork_not_ok' => 'paperwork NOT right (%s)',
        // One delivery at the bench.
        'title' => 'Bench check: %s',
        'eyebrow_invoice' => 'Invoice %s',
        'eyebrow_no_invoice' => 'No invoice number yet',
        'eyebrow_lines_one' => '1 line',
        'eyebrow_lines' => '%s lines',
        'eyebrow_page' => 'shown %s at a time',
        'last_by' => 'Last checked by %s.',
        'open_delivery' => 'Open the delivery',
        'arrived_rule' => 'For the duty-stamp rule, this delivery arrived on %s.',
        'rule_label' => 'unstamped',
        'rule_date_unset' => 'the refusal date',
        'rule_now' => 'It arrived on or after %s, so every unstamped duty item is refused at the door or kept in the unstamped quarantine. It is never taken '
            . 'into stock.',
        'rule_before' => 'Until %s, unstamped duty items are taken in only on the supplier\'s proof that they were made or imported before 1 Oct 2026 (and they must '
            . 'be sold, sent back or destroyed by 31 Mar 2027). Anything made or imported later that arrives unstamped is refused or kept apart. From %s every '
            . 'unstamped duty item is refused or kept apart.',
        'delivery_box' => 'The delivery',
        'paperwork' => 'Do the supplier and the paperwork look right?',
        'paperwork_hint' => 'The invoice and the delivery note match the goods and the supplier.',
        'not_checked' => 'Not checked yet',
        'paperwork_yes' => 'Yes, they look right',
        'paperwork_no' => 'No: do not book it in (refuse the delivery)',
        'note' => 'Note (optional)',
        'arrived_now' => 'The goods arrived now, not on %s (the desk keyed this delivery before they came)',
        'find' => 'Find a line',
        'find_label' => 'Scan a barcode, or type this supplier\'s code or a CW number',
        'not_here' => 'Not on this page: %s',
        'fill_stamps' => 'Every duty line on this page: stamp on the pack, digital',
        'tick_all' => 'Tick every line on this page as checked',
        'tools_hint' => 'These two buttons only fill in the form. Nothing is saved until you press "Save the check". A line is checked once it is ticked or one '
            . 'of its answers changes.',
        'line' => 'Line %s: %s',
        'expect' => '%s = %s items',
        'on_paperwork' => 'on the paperwork',
        'their_code' => 'their code %s',
        'barcodes' => 'barcode %s',
        'barcode_case' => '%s (a case of %s)',
        'stamp_needed' => 'Duty stamp needed',
        'stamp_not_needed' => 'No duty stamp needed',
        'checked' => 'Checked',
        'not_yet' => 'Not checked yet',
        'counted' => 'Checked: %s items as on the paperwork, or the differences below',
        'stamp' => 'UK duty stamp',
        'stamp_label' => 'duty stamp',
        'stamp_on' => 'Is the stamp on the outer retail pack, sealing it?',
        'stamp_yes' => 'Yes (write any unstamped items below)',
        'stamp_no' => 'No: none of them has a stamp',
        'stamp_type' => 'Kind of stamp',
        'choose' => 'Choose',
        'stamp_code' => 'Stamp code (optional: scan it)',
        'differ' => 'What arrived differently (items)',
        'short' => 'Short (did not arrive)',
        'over' => 'Extra (more than the paperwork)',
        'over_ok' => 'The extra number is right (more than the whole paperwork)',
        'damaged' => 'Damaged',
        'wrong' => 'Wrong product',
        'wrong_hint' => 'Say what came instead in the note at the top, and take a photo.',
        'unstamped' => 'No duty stamp',
        'unstamped_what' => 'What happens to the unstamped items',
        'unstamped_choose' => 'Choose (when some have no stamp)',
        'evidence' => 'The supplier\'s proof they were made or imported before 1 Oct 2026 (needed to take unstamped items in)',
        'extras_hint' => 'If unstamped items are refused or kept apart (or the pack has no stamp), this line\'s damaged and extra items go with them: they are '
            . 'treated as unstamped too.',
        'save' => 'Save the check',
        'save_next' => 'Save and show the next %s lines',
        'earlier' => 'Earlier lines',
        'next' => 'Next lines',
        'photos' => 'Photos and proof',
        'photos_none' => 'None yet. Take a photo of damage, a missing or misplaced stamp, a wrong product, and the supplier\'s certificate for stock made '
            . 'before 1 Oct 2026. Save the check first: attaching a photo leaves this page.',
        'photo_what' => 'What it is',
        'photo_file' => 'Photo or file (JPEG, PNG or PDF, at most %s MB: a camera photo is made smaller first)',
        'photo_note' => 'Note (optional)',
        'attach' => 'Attach',
    ];

    /** The incident register (incidents.php). */
    public const INCIDENTS = [
        'show' => 'Show',
        'all' => 'All',
        'kind' => 'Kind of problem',
        'any_kind' => 'Any kind',
        'filter' => 'Filter',
        'none_open' => 'No open incidents',
        'none_open_text' => 'Every problem the goods-in bench found is dealt with.',
        'none' => 'No incident matches',
        'none_text' => 'Change the filter to see others.',
        'show_open' => 'Show the open incidents',
        'total_one' => '1 incident.',
        'total_many' => '%s incidents.',
        'title' => '%s: %s items of %s',
        'from' => '%s line %s · invoice %s · %s',
        'from_no_invoice' => '%s line %s · no invoice number · %s',
        'where' => 'Where the items are: %s',
        'opened' => 'Found on %s by %s.',
        'closed' => '%s on %s by %s: %s',
        'close' => 'Close this incident',
        'close_how' => 'How it ended',
        'resolved' => 'Dealt with (say what was done)',
        'dismissed' => 'Nothing needed (say why)',
        'close_note' => 'What was done, or why nothing is needed',
        'close_button' => 'Close the incident',
        'close_hint' => 'Closing moves no stock. Items set aside or kept apart leave there with a stock correction (coming later).',
        'where_label' => 'set aside',
    ];

    /** Notices after a click on a delivery (ReceivingController::NOTICES, IncidentsController::NOTICES). */
    public const RECEIPT_NOTICE = [
        'from_invoice' => 'Started from the supplier invoice: everything still on its way. Attach the invoice if it is not here, check the goods at the bench, then book them in.', // pack J
        'created' => 'Delivery started. Now add its products (copy the order, import the supplier\'s sheet or scan them) and attach the invoice. The goods-in bench then checks it.',
        'saved' => 'Saved.',
        'added' => 'Added (and every change in the table saved).',
        'incremented' => 'One more pack on the line that was there already (and every change in the table saved).',
        'copied' => 'The order\'s lines still to come were copied. Change what arrived differently.',
        'imported' => 'Lines imported from the supplier\'s sheet.',
        'attached' => 'File attached.',
        'checked' => 'Bench check saved.',
        'invoice_set' => 'The supplier invoice is saved on this delivery.',
        'posted' => 'Booked in: the stock is added. A reviewer checks this delivery within 3 days.',
        'cancelled' => 'Delivery cancelled: nothing was booked in, and its invoice number can be used again.',
        'reversed' => 'Taken back: its stock, what it received against the order and its incidents were taken back.',
        'approved_review' => 'Saved: OK. The check is closed.',
        'approved_posted' => 'Saved: OK.',
        'rejected_review' => 'Saved: not OK. The delivery was taken back (its stock and what it received against the order). Key it again if the goods are here.',
        'rejected_reversal' => 'Saved: not OK. Nothing changed: a reversal is never undone. Key the delivery again if needed.',
        'rejected_recorded' => 'Saved: not OK. Your answer is recorded.',
        'rejected_approval' => 'Saved: not OK. The request was cancelled.',
        'resolved' => 'Incident closed.',
    ];

    /** The parts of a notice that names a line (ReceivingController::addedNotice, the bench checks a save cleared). */
    public const RECEIPT_ADDED = [
        'added' => 'Added line %s: %s, %s.',
        'incremented' => 'Line %s, %s: +%s items (now %s).',
        'packs_items' => '%s = %s items',
        'at_price' => '%s at %s a pack',
        'no_price' => '%s (no price yet)',
        'case' => 'A case barcode that is not this supplier\'s pack: the line is in packs of %s. Check its price.',
        'all_saved' => 'Every change in the table was saved too.',
        'cleared_one' => 'The bench check of %s was cleared (its quantity or product changed): the bench checks it again.',
        'cleared_many' => 'The bench check of %s was cleared (their quantity or product changed): the bench checks them again.',
        'skipped_one' => '1 row without a product code was skipped (listed below).',
        'skipped_many' => '%s rows without a product code were skipped (listed below).',
        'unit_one' => '%s item',
        'unit_many' => '%s items',
        'pack_of' => '%s %s of %s',
    ];

    /** The deliveries' refusals in words, by the service's error code (ReceivingController::plain, IncidentsController). */
    public const RECEIPT_ERROR = [
        'scan_pending' => 'The scan box still holds "%s": press Add, or clear it, then book in again. Nothing was saved.',
        'not_found' => 'Nothing matches "%s" (a barcode, this supplier\'s code, a CW number or words of the name). Your other changes are saved.',
        'import_refused_one' => 'Nothing was imported: the sheet has 1 problem. Correct it and import the whole sheet again.',
        'import_refused' => 'Nothing was imported: the sheet has %s problems. Correct them and import the whole sheet again.',
        'version_conflict' => 'Not saved: this delivery was changed since you opened it (perhaps by you in another tab). Here it is as it is now, with what you '
            . 'typed: check the lines and save again.',
        'version_conflict_choice' => 'Nothing was changed: this delivery was changed since you opened it. Here it is as it is now: make your choice again.',
        'version_conflict_bench' => 'Not saved: someone saved this delivery since you opened this page (another bench check, or the desk changed %s). What you '
            . 'typed is still here%s. Check it and save again.',
        'bench_the_delivery' => 'the delivery',
        'bench_except' => ', except on %s, whose product or quantity changed',
        'form_truncated' => 'The form arrived incomplete, so nothing was saved. Reload the page and try again.',
        'form_truncated_lines' => 'The form arrived incomplete (%s lines sent, %s arrived), so nothing was saved. A delivery of more than about %s lines is changed '
            . 'with the sheet import.',
        'not_ready_one' => 'Not booked in: 1 thing must be settled first. It is listed under "Before it can be booked in".',
        'not_ready' => 'Not booked in: %s things must be settled first. They are listed under "Before it can be booked in".',
        'not_creator' => 'Only the person who keyed this delivery changes its products. The goods-in bench writes what it finds on the bench check page. Nothing '
            . 'was changed.',
        'not_draft' => 'Nothing was changed: this delivery is not being keyed any more (it was booked in or cancelled meanwhile).',
        'receipt_has_lines' => 'Not saved: the supplier changes only while the delivery has no products, and the purchase order only while no line is against it. '
            . 'Change the lines first, or start a new delivery.',
        'supplier_inactive' => 'Not saved: we stopped buying from this supplier, so nothing is received from it.',
        'no_purchase_order' => 'Nothing was copied: this delivery is not against a purchase order. Choose the order first.',
        'nothing_outstanding' => 'Nothing was copied: every line of the order still to come is on this delivery already.',
        'bad_file_role' => 'Choose what the file is. Nothing was saved.',
        'type_not_allowed' => 'The supplier invoice must be a PDF or a photo (JPEG or PNG). Nothing was saved.',
        'document_cancelled' => 'Nothing was saved: this delivery is cancelled.',
        'incident_closed' => 'Nothing was changed: someone closed this incident meanwhile.',
        'reason_required' => 'Choose why from the list. Nothing was changed.',
        'too_large' => 'The file is too big (over %s MB). Nothing was saved. Use a smaller file, or take the photo at a lower size.',
        'no_file' => 'Choose a file. Nothing was saved.',
        'upload_failed' => 'The file did not arrive completely. Nothing was saved: try again.',
        'choose_supplier' => 'Choose the supplier, or the purchase order the delivery is against. Nothing was saved.',
        'empty_file' => 'The file is empty. Nothing was imported.',
        'too_many_rows' => 'The sheet has more than %s rows. Nothing was imported: split it into smaller sheets.',
        'unknown_sku' => 'There is no such product. Nothing was saved.',
        'merged_item' => 'Not saved: a product on this delivery was joined into another product. Replace the line with the product it was joined into.',
        'duplicate_invoice' => 'Not saved: this supplier\'s invoice %s is already on %s. A supplier invoice is booked in once only: cancel the other delivery first if '
            . 'it was keyed by mistake.',
        'invoice_copy_elsewhere' => 'Not saved: this file is already the supplier invoice of %s. A supplier invoice is booked in once only: attach the right invoice, or '
            . 'cancel the other delivery if it was keyed by mistake.',
        // Pack PO (PO10).
        'invoice_shared' => 'Not saved: this delivery shares its supplier invoice with other deliveries of its order. Change its invoice number first, then its order.',
        'admin_cannot_post' => 'Admin cannot key or book in deliveries. Nothing was changed.',
        'role_not_allowed' => 'Your jobs do not allow this. Nothing was changed.',
        'bad_status' => 'Choose how the incident ended: dealt with, or nothing needed. Nothing was changed.',
        'note_length' => 'Say what was done, or why nothing is needed, in 3 to 500 characters. Nothing was changed.',
        'unknown_receipt' => 'We cannot find this delivery. The link may be old or wrong.',
        'unknown_incident' => 'We cannot find this incident. The link may be old or wrong.',
    ];

    /**
     * The goods-in plan's problems and warnings in the screens' words (Ui\ReceiptWords recognises the sentences of
     * Receiving\ReceiptPlan and says them again with these).
     */
    public const RECEIPT_PLAN = [
        'no_lines' => 'No products yet.',
        'invoice_number_required' => 'Type the supplier\'s invoice number. A supplier invoice is booked in once only.',
        'invoice_file_required' => 'Attach the supplier\'s invoice (a PDF, or a photo of a paper invoice).',
        'invoice_copy_elsewhere' => 'The invoice file %s is also the invoice of %s. A supplier invoice is booked in once only: attach the right invoice, or cancel '
            . 'one of the two deliveries.',
        // Pack PO (PO10): one invoice shared by deliveries of different orders.
        'invoice_shared' => 'This supplier invoice is also on a delivery of another order. One invoice is shared only by deliveries of the same order: correct '
            . 'the invoice number of one of them.',
        'supplier_not_active' => '%s is not approved yet (or we stopped buying from it). A delivery is booked in only from an approved supplier.',
        'import_route' => '%s is abroad, and how UK duty stamps are put on its goods is not approved yet. A reviewer approves it first.',
        'import_route_change' => 'A change of where %s\'s goods get their UK duty stamps waits for a reviewer\'s OK.',
        'po_other_supplier' => '%s is not an order of this supplier. Choose the right purchase order.',
        'po_not_receivable' => '%s is %s, so nothing can be received against it. Set the lines to "not against the order", or ask the buyer.',
        'po_reference' => '%s is %s: no line is received against it.',
        'received_in_future' => 'The arrival time is in the future. Correct "The goods arrived".',
        'received_too_early' => 'The goods arrived on %s, more than %s days before this delivery was started. A delivery goes back at most %s days. Ask the '
            . 'purchasing manager.',
        'backdate_reason_required' => 'The goods arrived on %s, before this delivery was started (%s). Say why it is keyed late (for example: it arrived yesterday, '
            . 'or a paper receiving sheet while CW was down).',
        'bench_check_required' => 'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork look right.',
        'bench_check_required_lines' => 'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork look right, nor checked %s.',
        'paperwork_not_credible' => 'The goods-in bench found the supplier or the paperwork not right: do not book it in. Refuse the delivery and cancel it, or ask '
            . 'the purchasing manager.',
        'po_line_unknown' => 'Line %s: %s has no product line %s. Choose its order line again.',
        'po_line_item' => 'Line %s: line %s of %s is another product than %s. Choose its order line again.',
        'item_blocked' => 'Line %s: %s is blocked by its product card (%s), so it cannot be booked in. If the card is wrong, correct it and confirm it again. '
            . 'Otherwise refuse the goods.',
        'relay_route' => 'Line %s: %s gets its deliveries through ERPNext for %s. Book this delivery in ERPNext.',
        'stamp_check_required' => 'Line %s (%s): the goods-in bench has not said yet whether the duty stamp is on the pack.',
        'stamp_not_on_pack' => 'Line %s (%s): the stamp is not on the outer pack, so all %s items that arrived are unstamped. Write them as unstamped.',
        'stamp_type_required' => 'Line %s (%s): say which kind of stamp it has (digital or transitional).',
        'unstamped_not_duty' => 'Line %s: %s needs no duty stamp. Write no unstamped items for it.',
        'unstamped_refused_now' => 'Line %s (%s): from %s unstamped duty items are refused at the door or kept in the unstamped quarantine. Choose one of those two.',
        'evidence_required' => 'Line %s (%s): taking unstamped items in needs the supplier\'s proof that they were made or imported before 1 Oct 2026. Say what it '
            . 'is, in at least %s characters.',
        'line_not_checked' => 'Waiting for the goods-in bench: %s not checked yet (each line is checked, with its duty stamp, after it was keyed or last changed).',
        'checked_before_arrival' => 'The goods-in bench checked this delivery on %s, before it arrived (%s). Correct "The goods arrived", or have the bench check '
            . 'it again.',
        'mode_conflict' => '%s: lines %s and %s give it different selling modes (%s and %s). Choose one.',
        'over_tolerance' => '%s: %s items of %s line %s would be received, but %s were ordered (%s received before). That is more than the %s%% extra allowed. '
            . 'Ask the buyer, or put the extra on a line not against the order.',
        // Warnings.
        'not_against' => 'Line %s (%s) is not against %s.',
        'card_warning' => 'Line %s (%s): its product card breaks a rule (%s). A warning until someone confirms the card.',
        'discontinued' => 'Line %s (%s): its product card says it is not sold any more.',
        'no_card' => 'Line %s (%s): it has no product card yet, so it is treated as needing a duty stamp (the bench checks its stamp).',
        'duty_unknown' => 'Line %s (%s): its product card does not say whether duty is paid on it, so it is treated as needing a duty stamp (the bench checks its stamp).',
        'pre_october' => 'Line %s (%s): %s unstamped items taken in on the supplier\'s proof they were made or imported before 1 Oct 2026. They must be sold, sent back or '
            . 'destroyed by 31 Mar 2027.',
        'extras_refused' => 'Line %s (%s): its %s arrived unstamped too, so they are refused at the door, not set aside to check.',
        'extras_quarantined' => 'Line %s (%s): its %s arrived unstamped too, so they go to the unstamped quarantine, not set aside to check.',
        'over_confirmed' => 'Line %s (%s): %s extra items on a line of %s (the bench confirmed the number). They are set aside to check, at the line\'s price, '
            . 'until the supplier invoices or collects them.',
        'findings' => 'Line %s (%s): %s. Each opens an incident when the delivery is booked in.',
        'free' => 'Line %s (%s) has no price (£0). A free product? The price is for now, until the supplier invoice is matched.',
        'mode_kept' => '%s: none of it goes into stock, so its selling mode stays %s (a delivery sets the selling mode only of what goes into stock).',
        'mode_kept_none' => '%s: none of it goes into stock, so its selling mode stays as it is (a delivery sets the selling mode only of what goes into stock).',
        'within_tolerance' => '%s line %s: %s items received against %s ordered (within the %s%% extra allowed).',
        'mode_change' => '%s: selling mode %s → %s (%s).',
        'one_unit' => 'That barcode is one single item of %s, but this supplier sells it in %s: add one of its packs, or single items?',
        'mode_unknown' => 'not known',
        'and' => 'and',
        'damaged_n' => '%s damaged',
        'over_n' => '%s extra',
        'short_n' => '%s short',
        'wrong_n' => '%s wrong product',
        'unstamped_n' => '%s without a duty stamp',
        'items_word' => 'items',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // The set-it-yourself pack (8 Oct 2026, the owner's rule: no hard-coding; docs/decisions.md Y1-Y40): settings, approval
    // rules, reasons, warehouses and places changed on the screens with a reason and a history; the websites' status; the safety
    // checks; the audit log; who can do what; staff set up by QR code. Service messages are never edited: the pages translate
    // them by error code (CONFIG_ERROR, STAFF).

    /** Words every change page shares: the reason, the history, look-only. */
    public const CONFIG = [
        'reason' => 'Why are you changing this?',
        'reason_hint' => 'At least 3 characters. It is kept with the change, for everyone to see.',
        'history' => 'Changes',
        'history_none' => 'No change since the system was set up.',
        'when' => 'When',
        'who' => 'Who',
        'what' => 'What changed',
        'why' => 'Why',
        'set_up' => 'set up by CW',
        'server' => 'on the server',
        'look_only' => 'Admins and Reviewers',
        'first' => 'As set up',
        'added' => 'Added',
        'yes' => 'Yes',
        'no' => 'No',
        'on' => 'On',
        'off' => 'Off',
        'not_set' => 'not set',
        'technical' => 'Technical details (for ' . self::ASK . ')',
        'change' => 'Change',
    ];

    /** What a change was (config_change.action) in the history lists. */
    public const CONFIG_ACTION = [
        'baseline' => 'As the system was set up',
        'add' => 'Added',
        'change' => 'Changed',
        'agree' => 'Marked as agreed',
        'unagree' => 'Marked as not agreed yet',
        'rename' => 'Renamed',
        'switch_off' => 'Switched off',
        'switch_on' => 'Switched on again',
        'uses' => 'Where it is used changed',
        'sellable' => 'Selling from it changed',
        'owner' => 'Whose stock changed',
        'rules' => 'Its stock-out rules changed',
    ];

    /** The change pages' refusals, by the service's error code (the service messages stay the API's). */
    public const CONFIG_ERROR = [
        'bad_reason' => 'Say why in 3 to 500 characters. Nothing was saved.',
        'changed_meanwhile' => 'Someone else changed this while you had the page open (%s, %s). Nothing was saved. What is in use now is shown: check it, then save again.',
        'role_not_allowed' => 'Only Admins and Reviewers change settings, rules and lists. Nothing was saved.',
        'staff_not_allowed' => 'Your account cannot change this any more. Nothing was saved.',
        'bad_value' => 'This is not a value this setting takes. Nothing was saved.',
        'bad_limit' => 'A limit is a whole number from 0 to 2,000,000,000. Nothing was saved.',
        'bad_days' => 'The days to check are a whole number from 1 to 120. Nothing was saved.',
        'bad_rule' => 'Choose how a reviewer checks from the list. Nothing was saved.',
        'limit_required' => 'This rule needs its limit. Nothing was saved. Type the limit, then save again.',
        'no_approval_rule' => 'This kind of record has no OK first. Nothing was saved.',
        'no_size_rule' => 'This kind of record has no OK first for a big record. Nothing was saved.',
        'size_required' => 'The OK first for a big record needs a number of units, a value in £, or both. Nothing was saved.',
        'bad_reject_action' => 'Choose what Not OK does from the list. Nothing was saved.',
        'nothing_to_change' => 'Nothing to save: change something first.',
        'bad_code_reason' => 'The code is 2 to 32 small letters, digits or _, starting with a letter. Nothing was saved.',
        'bad_code_warehouse' => 'The short code is 2 to 32 capital letters, digits or _, starting with a letter. Nothing was saved.',
        'bad_code_place' => 'The short code is 1 to 31 capital letters, digits, _ or -. Nothing was saved.',
        'bad_label' => 'The name is 2 to 100 characters. Nothing was saved.',
        'bad_name' => 'The name is 2 to 100 characters. Nothing was saved.',
        'bad_note' => 'A note is at most 255 characters. Nothing was saved.',
        'bad_owner' => 'Say whose stock it is, and for another account its name (2 to 64 characters). Nothing was saved.',
        'bad_uses' => 'Tick where the reason is used. Nothing was saved.',
        'bad_direction' => 'Choose which way stock goes. Nothing was saved.',
        'reason_exists' => 'There is a reason with this code already. Nothing was saved. Rename it or switch it on again instead.',
        'reason_locked' => 'CW sets this reason itself, so it is never changed. Nothing was saved.',
        'warehouse_exists' => 'There is a warehouse with this short code already. Nothing was saved.',
        'place_exists' => 'This warehouse has a place with this short code already. Nothing was saved.',
        'system_warehouse' => 'The system works with this warehouse, so this never changes. Nothing was saved.',
        'warehouse_off' => 'This warehouse is switched off: switch it on first. Nothing was saved.',
        'other_not_sellable' => 'Another account\'s stock is never sold from: it moves into our stock with a release invoice first. Nothing was saved.',
        'confirm_needed' => 'Tick the box to confirm first: this changes what the websites may sell. Nothing was saved.',
        'warehouse_in_use' => 'Websites use this warehouse (%s). Nothing was saved. Ask ' . self::ASK . ' to move them to another warehouse first.',
        'sellable_warehouse' => 'Websites may sell from this warehouse: stop that first. Nothing was saved.',
        'warehouse_not_empty' => 'This warehouse is not empty: %s. Nothing was saved. It is switched off only when it is empty.',
        'place_in_use' => 'A record that is not final yet names this place. Nothing was saved.',
        'unconfirmed' => 'Tick the box to confirm first. Nothing was saved.',
        'unknown_place' => 'We cannot find this place in this warehouse. Nothing was changed.',
        'unknown_reason' => 'We cannot find this reason. Nothing was changed.',
        'unknown_warehouse' => 'We cannot find this warehouse. Nothing was changed.',
        'unknown_type' => 'We cannot find this kind of record. Nothing was changed.',
        'unknown_setting' => 'We cannot find this setting. Nothing was changed.',
        'owner_not_empty' => 'This warehouse is not empty: %s. Nothing was saved. Whose stock it holds changes only while it is empty.',
        'loosen_needs_reviewer' => 'Only a Reviewer switches an approval rule off or makes it looser. Nothing was saved. Ask a Reviewer, or make the rule stricter instead.',
        'reject_record_only' => 'Not OK on a purchase order or a stock count only records it: an order with deliveries cannot be cancelled, and a count '
            . 'is put right by counting again. Nothing was saved.',
    ];

    /** The page of one setting (/ui/reference/settings/setting). */
    public const SETTING_EDIT = [
        'now' => 'Value now',
        'what' => 'What it does',
        'status' => 'Agreed by the owner',
        'last' => 'Last changed',
        'value' => 'New value',
        'agreed' => 'The owner has agreed this value',
        'save' => 'Save the setting',
        'look_only' => 'You can look; Admins and Reviewers change settings.',
        'type_int' => 'A whole number.',
        'type_decimal' => 'A number like 0.50 (a point, no comma).',
        'type_date' => 'A date.',
        'type_string' => 'One line of text.',
        'type_text' => 'Several lines are fine.',
        'type_bool' => 'Yes or no.',
        'range' => 'From %s to %s.',
        'vat' => 'A VAT code in use: S, R, Z, E, RC or OS.',
        'one_of' => 'One of: %s.',
        'sites' => 'Website short codes, separated by commas, for example vapeandgo,electrofag. Leave it empty for none.',
        'empty' => 'Leave it empty for "not set".',
        'approvals' => 'This is an approval rule: change it on the Approval Rules page.',
        'company' => 'The company details have their own page.',
        'address' => 'An address starting with https://, without anything after the name, for example https://warehouse.example.com.',
        'list' => 'Tick the ones that apply. None ticked is allowed.',
        'list_none' => 'None',
    ];

    /** Notices after saving a setting (ReferenceController). */
    public const SETTING_NOTICE = [
        'saved' => 'Saved. The new value works from now on.',
        'agreed' => 'Saved: the value is marked as agreed by the owner.',
        'unagreed' => 'Saved: the value is marked as not agreed yet.',
        'unchanged' => 'Nothing changed: the setting already had this value.',
    ];

    /** The Approval Rules page (/ui/reference/approvals). */
    public const APPROVALS = [
        'records' => 'Records: who checks, and what needs an OK first',
        'count_one' => '1 rule',
        'count_many' => '%s rules',
        'records_text' => 'One rule for each kind of record. Kinds not in use yet keep their rule for when they come.',
        'suppliers' => 'Suppliers',
        'matching' => 'Matching products',
        'company' => 'Company details',
        'staff' => 'Staff access',
        'in_use' => 'In use',
        'later' => 'Not in use yet',
        'review' => 'Reviewer check after it is final',
        'review_all' => 'Every one',
        'review_over' => 'Only those over a limit',
        'review_none' => 'No check',
        'review_limit' => 'The limit for "only those over a limit" (items)',
        'days' => 'Days a reviewer has to check it (1 to 120)',
        'ok_first' => 'A reviewer\'s OK first',
        'ok_limit_PO' => 'Above this net value in £, no VAT (whole pounds)',
        'ok_limit_ADJ' => 'Above this many items put back',
        'ok_limit_SIN' => 'Above this many items put back',
        // The OK first for a big record (pack A1).
        'size' => 'A reviewer\'s OK first for a big record',
        'size_units' => 'More than this many units (empty: units are not checked)',
        'size_value' => 'Or worth more than this in £, whole pounds (empty: not checked)',
        'size_on' => 'More than %s needs a reviewer\'s OK first.',
        'size_off' => 'No OK first for a big record.',
        'size_units_part' => '%s units',
        'size_value_part' => '£%s',
        'size_or' => 'or',
        'reject' => 'What "Not OK" does',
        'reject_reverse' => 'Cancel it with a cancellation record',
        'reject_record' => 'Only record it (the person who made it fixes it)',
        'change_rule' => 'Change this rule',
        'save_rule' => 'Save the rule',
        'save' => 'Save',
        'number' => 'New number',
        'history' => 'Changes to this rule',
        'no_ok' => 'No OK needed first.',
        'units_cancel' => 'Putting back more than %s items without a supplier document needs a reviewer\'s OK first, also when a record is cancelled.',
        'always' => 'These always need a second OK and are not switched here: changing a match once the website sells warehouse stock, matching to a product '
            . 'someone said is wrong, and a join a Matcher asks for.',
        'others' => 'Other things could wait for a second person too, for example settings changes or warehouse changes. '
            . 'They are not built yet. Tell ' . self::ASK . ' if you want one.',
        'requests' => 'Changes of staff access waiting for a reviewer\'s OK: %s.',
        'requests_link' => 'See them',
        'nobody_can' => 'Nobody else can say OK now: no other person has a Reviewer job that works. Changes would wait until there is one.',
        'saved' => 'Saved. The rule works like this from now on.',
        'unchanged' => 'Nothing changed: the rule was already like that.',
        'look_only' => 'You can look; Admins and Reviewers change the rules.',
        // Review findings I1, M5, M7 and the nit of 8 Oct 2026.
        'loosen_rule' => 'Switching a rule off, or making it looser, needs a Reviewer. Making it stricter needs an Admin or a Reviewer.',
        'not_release' => 'Switching a rule off does not let through work already waiting for an OK: that still waits for its OK.',
        'loosen_admin' => 'You can make a rule stricter here. To switch one off or make it looser, ask a Reviewer.',
        'reject_record_only' => 'Not OK on a purchase order only records it: the buyer then cancels or corrects the order. An order with deliveries cannot be cancelled.',
        'reject_record_only_cnt' => 'Not OK on a stock count only records it: a count is put right by counting the item again, or by answering its differences.',
        'agreed' => 'The owner has agreed this rule',
        'items' => 'Items', // Pack B
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoices' => 'Supplier invoices',
        'ok_limit_SINV' => 'Above this many pounds charged over the order and the delivery (whole pounds)',
        'rule_difference' => 'An invoice that does not match by more than £%s waits for a reviewer\'s OK before it is final.',
    ];

    /** One switchable or numbered approval rule (Admin\ApprovalRules): its title and what it does on, off, or with its number. */
    public const RULE = [
        'approvals.supplier_activation' => [
            'title' => 'New suppliers',
            'on' => 'A new supplier, a supplier used again, and an overseas supplier\'s duty-stamp arrangement wait for a reviewer\'s OK before anyone orders from them.',
            'off' => 'Off: a buyer\'s request makes the supplier usable at once. The supplier page says it was approved alone.',
        ],
        'suppliers.change_review' => [
            'title' => 'Changed supplier details',
            'on' => 'When the details of a supplier we order from change, a reviewer checks them afterwards. Orders are not stopped.',
            'off' => 'Off: changed supplier details are not checked by a reviewer.',
        ],
        'suppliers.approval_due_days' => [
            'title' => 'Days to decide a supplier',
            'now' => 'A reviewer should decide a supplier approval within %s days.',
            'label' => 'Days (1 to 120)',
        ],
        'approvals.match_multiple' => [
            'title' => 'Matches where 1 sale is not 1 product',
            'on' => 'A match where 1 sale uses more or less than 1 warehouse product waits for a second matching lead.',
            'off' => 'Off: one person makes such a match alone.',
        ],
        'approvals.match_counted' => [
            'title' => 'Joining products whose stock was counted',
            'on' => 'Joining products whose stock was counted in the warehouse, or undoing such a join, waits for a second matching lead.',
            'off' => 'Off: one matching lead does it alone. The stock is still counted again afterwards.',
        ],
        'approvals.spot_check_size' => [
            'title' => 'Spot check size',
            'now' => 'A spot check holds %s strong matches. A smaller one never confirms the rest together.',
            'label' => 'Matches (5 to 200)',
            'note' => 'A change works for new spot checks only. A spot check already drawn keeps the size it was drawn with.',
        ],
        'approvals.company_own_change' => [
            'title' => 'Your own change of the company details',
            'on' => 'A reviewer who changes and confirms our name, numbers, purchasing e-mail or delivery address themselves gets another reviewer\'s check afterwards.',
            'off' => 'Off: such a change is not checked by a second reviewer.',
        ],
        'approvals.mapping_bulk_second_ok' => [
            'title' => 'Matches confirmed from a ticked list',
            'on' => 'Matches confirmed, and new products created, from a ticked list wait for a second matching lead (Second approval).',
            'off' => 'Off: they work at once. Each ticked product is still checked on its own, and the other rules still apply.',
        ],
        'approvals.staff_grant' => [
            'title' => 'Giving someone Admin or Reviewer',
            'on' => 'Giving someone the Admin or Reviewer job waits for a reviewer\'s OK. Nothing changes until then. '
                . 'So does a job that was given one of their powers on the Roles and permissions page.',
            'off' => 'Off: the admin\'s change works at once.',
        ],
        'approvals.staff_reset' => [
            'title' => 'Resetting the sign-in of an Admin or a Reviewer',
            'on' => 'A new sign-in code, a new password or a new sign-up sheet for someone with Admin or Reviewer waits for a reviewer\'s OK. '
                . 'So does one for someone whose job was given one of their powers on the Roles and permissions page. '
                . 'The admin then makes it, once. With only one Reviewer, a reset of that Reviewer is done on the server.',
            'off' => 'Off: the admin makes it at once. The admin still never holds both keys of anybody.',
        ],
        // Pack B
        'approvals.item_by_hand_check' => [
            'title' => 'Items made by hand',
            'on' => 'A second person checks each item made by hand, afterwards, on Products › Add Item. The item can be used at once.',
            'off' => 'Off: nobody else checks an item made by hand.',
        ],
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'approvals.invoice_accept_other' => [
            'title' => 'Accepting the differences of an invoice',
            'on' => 'The differences of an invoice that does not match are accepted by someone other than the person who made it final.',
            'off' => 'Off: the person who made the invoice final may accept its differences, with a reason. Every acceptance is recorded.',
        ],
    ];

    /** Reasons for stock changes: adding, renaming, switching off (/ui/reference/reasons, /reason). */
    public const REASONS_EDIT = [
        'add_title' => 'Add a reason',
        'code' => 'Short code (for files and spreadsheets)',
        'code_hint' => 'Small letters and digits, for example seal or damp. It never changes.',
        'name' => 'Name people see',
        'uses' => 'Used for',
        'direction' => 'Stock goes',
        'needs_note' => 'Needs a note',
        'gift' => 'It is a free gift',
        'add_button' => 'Add the reason',
        'rename' => 'Rename it',
        'rename_button' => 'Save the name',
        'switch' => 'Switch it off or on',
        'switch_off_text' => 'A switched-off reason is no longer offered, and a new record using it cannot be made final. Records that used it keep it, '
            . 'and a record already waiting for a reviewer\'s OK can still get its OK.',
        'switch_off_button' => 'Switch this reason off',
        'switch_on_button' => 'Switch this reason on again',
        'locked' => 'CW sets this reason itself: it is never changed.',
        'status_on' => 'In use',
        'status_off' => 'Switched off',
        'status' => 'In use',
        'facts' => 'About this reason',
        'look_only' => 'You can look; Admins and Reviewers change reasons.',
        // Where a reason is offered, also on the order screens (review finding I6, Y51).
        'uses_title' => 'Where it is offered',
        'uses_text' => 'Tick the lists that offer this reason, also the order screens\' lists. Records that used it keep it.',
        'uses_button' => 'Save where it is offered',
        // The stock-out rules of a reason (pack A1; owner answer Q5).
        'given_to' => 'A stock out with it names who it was given to',
        'given_to_short' => 'Needs a name',
        'below_zero' => 'It may take a protected product below zero',
        'below_zero_short' => 'May go below zero',
        'rules_title' => 'Stock-out rules',
        'rules_text' => 'A sample or staff use names the person it was given to. A protected product (one the websites must not oversell) never goes below zero, unless its reason allows it. Records already final keep what they were made with.',
        'rules_button' => 'Save the rules',
    ];

    /** Notices after a change of a reason. */
    public const REASON_NOTICE = [
        'added' => 'Done: the reason was added. It is offered from now on.',
        'renamed' => 'Saved: the reason has its new name.',
        'off' => 'Done: the reason is switched off.',
        'on' => 'Done: the reason is switched on again.',
        'unchanged' => 'Nothing changed: the reason was already like that.',
        'uses_saved' => 'Saved: the reason is offered in the lists you ticked.',
        'rules_saved' => 'Saved: the reason\'s stock-out rules are changed.',
    ];

    /** Warehouses and the places inside them (/ui/reference/warehouses, /{id}). */
    public const WAREHOUSES = [
        'name' => 'Warehouse',
        'status' => 'In use',
        'sold_from' => 'Websites sell from it',
        'owner' => 'Whose stock',
        'stock' => 'In the building',
        'places' => 'Places inside',
        'ours' => 'Ours',
        'theirs' => '%s (another account)',
        'balance_link' => 'What it holds and what we owe',
        'stock_line' => '%s items, %s products',
        'none' => 'none',
        'status_on' => 'In use',
        'status_off' => 'Switched off',
        'yes_sites' => 'Yes: %s',
        'yes_no_site' => 'Yes (no website yet)',
        'no' => 'No',
        'built_in' => 'The system works with this warehouse: it is never switched off, and whether websites sell from it never changes.',
        'tip' => 'The VPG 2 room: add it with "Another account\'s stock" (it is never sold from). The overflow room: open the main warehouse and add it as a place inside it.',
        'add_title' => 'Add a warehouse',
        'code' => 'Short code',
        'code_hint' => 'Capital letters and digits, for example ROOM2. It never changes.',
        'wh_name' => 'Name',
        'owner_q' => 'Whose stock is kept here?',
        'owner_ours' => 'Ours',
        'owner_other' => 'Another account\'s, for example VPG 2 (never sold from)',
        'owner_name' => 'Name of that account',
        'sellable' => 'Websites may sell from it',
        'sellable_confirm' => 'Yes, websites may sell from this warehouse',
        'note' => 'Note (optional)',
        'add_button' => 'Add the warehouse',
        'code_label' => 'Short code',
        'stock_now' => 'Stock here now',
        'in_building' => 'In the building',
        'sold_waiting' => 'Sold, waiting to ship',
        'reserved' => 'Reserved (not paid)',
        'with_stock' => 'Products with stock',
        'websites' => 'Websites selling from it',
        'assigned' => 'Other websites using it',
        'rename' => 'Name and note',
        'rename_button' => 'Save the name and note',
        'owner_title' => 'Whose stock',
        'owner_button' => 'Save whose stock it is',
        'sellable_title' => 'Can websites sell from it?',
        'sellable_now_yes' => 'Websites may sell from it.',
        'sellable_now_no' => 'Websites may not sell from it.',
        'sellable_text' => 'This changes what the websites may sell. A website sells from it only once ' . self::ASK . ' points the website at it on the server.',
        'sellable_confirm_off' => 'Yes, websites may no longer sell from it',
        'sellable_on_button' => 'Let websites sell from it',
        'sellable_off_button' => 'Stop websites selling from it',
        'in_use' => 'Websites sell from it now (%s): ' . self::ASK . ' moves them to another warehouse first.',
        'switch_title' => 'Switch it off or on',
        'switch_off_text' => 'Only an empty warehouse is switched off: no stock, no website, no record waiting. It is never deleted.',
        'switch_off_confirm' => 'Yes, switch it off',
        'switch_off_button' => 'Switch this warehouse off',
        'switch_on_button' => 'Switch it on again',
        'not_empty' => 'It cannot be switched off yet: %s.',
        'places_title' => 'Places inside (optional)',
        'places_text' => 'Shelves or rooms inside this warehouse, for example the overflow room. Nothing asks for a place: stock is not split by place yet.',
        'places_none' => 'No places yet.',
        'place_code' => 'Short code',
        'place_code_hint' => 'Capital letters, digits and -, for example A-01 or OVERFLOW.',
        'place_name' => 'Name',
        'place_add' => 'Add a place',
        'place_add_button' => 'Add the place',
        'place_change' => 'Change this place',
        'place_rename_button' => 'Save the place',
        'place_off_button' => 'Switch this place off',
        'place_on_button' => 'Switch this place on again',
        'history' => 'Changes to this warehouse',
        'look_only' => 'You can look; Admins and Reviewers change warehouses.',
        // Whose stock changes only while the warehouse is empty, with a tick (review finding I4, Y48).
        'owner_text' => 'Whose stock it holds changes only while the warehouse is empty. Another account\'s stock becomes ours only with a release invoice.',
        'owner_confirm' => 'Yes, change whose stock this warehouse holds',
        'owner_not_empty' => 'Whose stock it holds cannot change now: %s. It changes only while the warehouse is empty. Another account\'s stock becomes ours only with a release invoice.',
    ];

    /** Why a warehouse cannot be switched off (Admin\Warehouses::notEmpty). */
    public const WHY_NOT_EMPTY = [
        'stock' => 'it holds stock',
        'website' => 'a website uses it',
        'records' => 'a record that is not final names it',
        'counts' => 'a recount is open there',
    ];

    /** Notices after a change of a warehouse or a place. */
    public const WAREHOUSE_NOTICE = [
        'added' => 'Done: the warehouse was added.',
        'saved' => 'Saved.',
        'unchanged' => 'Nothing changed: it was already like that.',
        'off' => 'Done: the warehouse is switched off.',
        'on' => 'Done: the warehouse is switched on again.',
        'place_added' => 'Done: the place was added.',
        'place_saved' => 'Saved: the place is changed.',
    ];

    /** A website's mode (channel.mode, the site's own CW_MODE). */
    public const MODE = [
        'off' => 'Off',
        'shadow' => 'Watching only',
        'live' => 'Live',
    ];

    /** The Websites page (/ui/system/sites). */
    public const SITES = [
        'mode' => 'In CW',
        'site_mode' => 'The website says',
        'contact' => 'Last contact',
        'queue' => 'Waiting to send to CW',
        'dead' => 'Failed for good',
        'feed' => 'Stock changes not taken yet',
        'sells_from' => 'Sells from',
        'writer' => 'Stock writer',
        'mismatch' => 'The website runs in another mode than CW. The lower one applies.',
        'never' => 'never',
        'ago' => '%s (%s ago)',
        'no_contact' => 'No contact for %s.',
        'no_contact_ever' => 'It has not contacted CW yet.',
        'queue_line' => '%s changes, the oldest %s old',
        'nothing' => 'nothing',
        'not_reported' => 'not reported yet',
        'feed_line' => '%s (CW stock reached it up to %s)',
        'writer_on' => 'On',
        'writer_waiting' => 'Switched on: starts when the website is live',
        'writer_off' => 'Off',
        'code' => 'Short code',
        'connector' => 'Connector version',
        'key' => 'Key set',
        'ips' => 'Allowed addresses',
        'commands' => 'What ' . self::ASK . ' runs on the CW server to change this website. Each one is a test run until he adds the apply option.',
        'none' => 'There is no website yet.',
        'seconds' => '%s seconds',
        'minutes' => '%s minutes',
        'hours' => '%s hours',
        'days' => '%s days',
        // Pack F (PF4): the store's ERPNext relay.
        'relay' => 'Messages from ERPNext',
        'relay_all' => 'All received',
        'relay_none' => 'None received',
        'relay_some' => 'Received: %s',
    ];

    /** What each server command of the Websites page does (Admin\Sites::commands). */
    public const SITE_COMMAND = [
        'mode' => 'Move to the next mode',
        'writer' => 'Switch the stock writer',
        'ips' => 'Change the allowed addresses',
        'warehouse' => 'Sell from another warehouse',
        'key' => 'Make a new key',
        'health' => 'List the problems of every website',
        // Pack F (PF4)
        'relay' => 'Give or end the messages from ERPNext',
    ];

    /** The System checks page (/ui/system/checks). */
    public const INTEGRITY = [
        'last' => 'Last check',
        'result_ok' => 'Everything agreed',
        'result_bad' => '%s problems found',
        'result_one' => '1 problem found',
        'took' => 'Finished %s, after %s seconds.',
        'details' => 'What was found (for ' . self::ASK . ')',
        'more' => 'Only the first %s are kept here. The server log has all of them.',
        'earlier' => 'Earlier checks',
        'when' => 'When',
        'result' => 'Result',
        'none' => 'No check has run yet. It runs every night.',
        'stale' => 'The last check finished %s. It should run every night: tell ' . self::ASK . '.',
        'tell' => 'Tell ' . self::ASK . ' the same day. Nothing is fixed by itself.',
    ];

    /** The audit log (/ui/system/audit). */
    public const AUDIT = [
        'from' => 'From',
        'to' => 'To',
        'who' => 'Who',
        'everyone' => 'Everyone',
        'system' => 'Set up by CW (jobs on the server)',
        'sites' => 'Websites',
        'record' => 'Kind of record',
        'any' => 'Any',
        'id' => 'Record number',
        'action' => 'What was done',
        'anything' => 'Anything',
        'search' => 'Search',
        'clear' => 'Clear the search',
        'download' => 'Download as a spreadsheet (CSV, opens in Excel)',
        'shown' => '%s entries, newest first.',
        'older' => 'Older entries',
        'none' => 'Nothing was recorded for this search.',
        'none_text' => 'Try more days, or clear the search.',
        'when' => 'When',
        'details' => 'Details',
        'ip' => 'From address',
        'cw' => 'set up by CW',
        'bad_filter' => 'This search cannot be done: %s. Nothing was searched.',
    ];

    /** audit_log.entity_type => the kind of record (the audit log's filter and rows; another type is made readable). */
    public const AUDIT_RECORD = [
        'app_setting' => 'Setting',
        'barcode_review' => 'Barcode to check',
        'brand' => 'Brand',
        'channel' => 'Website',
        'company_profile' => 'Company details',
        'other_account_entry' => 'Balance owed to another account',
        'demand_anomaly' => 'Days left out of sales',
        'document' => 'Record',
        'document_type' => 'Rule of a kind of record',
        'import_run' => 'Import',
        'incident' => 'Incident',
        'item_card' => 'Product card',
        'key_sample' => 'Spot check',
        'listing' => 'Website product',
        'mapping_batch' => 'Bulk action on matches',
        'match_decision' => 'Match',
        'match_run' => 'Computer check',
        'reason_code' => 'Reason for a stock change',
        'reservation' => 'Website order',
        'sales_import_batch' => 'Sales data',
        'sku' => 'Warehouse product',
        'sku_barcode' => 'Barcode',
        'staff_user' => 'Staff member',
        'stored_file' => 'File',
        'supplier' => 'Supplier',
        'supplier_item' => 'Supplier\'s product',
        'warehouse' => 'Warehouse',
        'warehouse_location' => 'Place in a warehouse',
        // Pack B
        'item_family' => 'Family',
        'item_pack' => 'Pack item',
        // Pack F (PF5)
        'goods_in_suspense' => 'ERPNext line to place',
        'count_review' => 'Stock figure to check',
        'policy_review' => 'Selling-mode change to check',
        'oversell_event' => 'Oversold order',
        'relay' => 'Message from ERPNext',
        // Pack H (0038).
        'counterparty' => 'Customer',
        'counterparty_entry' => 'What a customer owes',
        'counterparty_price' => 'Price a customer paid',
    ];

    /** The first part of an audit action => what kind of thing was done (the audit log's filter and rows). */
    public const AUDIT_FAMILY = [
        'count' => 'Stock counts',
        'setting' => 'Settings',
        'document_type' => 'Rules of records',
        'reason' => 'Reasons for stock changes',
        'warehouse' => 'Warehouses',
        'location' => 'Places in warehouses',
        'staff' => 'Staff access',
        'login' => 'Signing in',
        'logout' => 'Signing out',
        'password' => 'Passwords',
        'supplier' => 'Suppliers',
        'supplier_item' => 'Suppliers\' products',
        'po' => 'Purchase orders',
        'document' => 'Records',
        'grn' => 'Deliveries',
        'incident' => 'Incidents',
        'company' => 'Company details',
        'item_card' => 'Product cards',
        'sku_barcode' => 'Barcodes',
        'barcode_review' => 'Barcodes to check',
        'mapping' => 'Matching',
        'listing' => 'Website products',
        'channel' => 'Websites',
        'reorder' => 'Reorder',
        'sales' => 'Sales data',
        'selling_mode' => 'Selling modes',
        'reservation' => 'Website orders',
        'file' => 'Files',
        'stockop' => 'Stock records',
        'account' => 'Balance owed to another account',
        // Pack B
        'item' => 'Items made by hand',
        'family' => 'Families',
        'pack' => 'Pack items',
        'repack' => 'Repacks',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoice' => 'Supplier invoices',
        'credit_note' => 'Supplier credit notes',
        'return' => 'Returns to suppliers',
        // Pack F (PF5)
        'goods_in_suspense' => 'ERPNext lines to place',
        'count_review' => 'Stock figures to check',
        'policy_review' => 'Selling-mode changes to check',
        'oversell_event' => 'Oversold orders',
        'queue' => 'The stores\' queues',
        'relay' => 'Messages from ERPNext',
        'sku' => 'Warehouse products',
        // ---- Pack E
        'permission' => 'Who can do what',
        // ---- end Pack E
        // Pack H (0038).
        'handover' => 'Hand-overs',
        'counterparty' => 'Customers of hand-overs',
    ];

    /** audit_log.action => what was done, for the actions people look for most (the others: their kind, AUDIT_FAMILY). */
    public const AUDIT_ACTION = [
        'setting.change' => 'Setting changed',
        'permission.change' => 'Changed which jobs can do a task', // Pack E
        'document_type.change' => 'Rule of a kind of record changed',
        'reason.add' => 'Reason added',
        'reason.rename' => 'Reason renamed',
        'reason.switch_off' => 'Reason switched off',
        'reason.switch_on' => 'Reason switched on again',
        'reason.rules' => 'Reason\'s stock-out rules changed',
        'stockop.create' => 'Stock record started',
        'stockop.header' => 'Stock record\'s details changed',
        'count.open' => 'Count sheet opened',
        'count.upload' => 'Count sheet filled in from a file',
        'count.accept' => 'Count difference accepted',
        'count.reject' => 'Count difference rejected and the count put back',
        'account.payment' => 'Payment to another account recorded',
        'account.payment_reverse' => 'Payment to another account reversed',
        'warehouse.add' => 'Warehouse added',
        'warehouse.rename' => 'Warehouse renamed',
        'warehouse.sellable' => 'Selling from a warehouse changed',
        'warehouse.owner' => 'Whose stock a warehouse holds changed',
        'warehouse.switch_off' => 'Warehouse switched off',
        'warehouse.switch_on' => 'Warehouse switched on again',
        'location.add' => 'Place added',
        'location.rename' => 'Place renamed',
        'location.switch_off' => 'Place switched off',
        'location.switch_on' => 'Place switched on again',
        'mapping.bulk' => 'Bulk action on matches',
        'mapping.arrival_check' => 'The check on arrival ran',
        'listing.website_state' => 'Website state of a store\'s variants loaded',
        'staff.create' => 'Staff member added',
        'staff.roles' => 'Jobs changed',
        'staff.activate' => 'Can sign in again',
        'staff.deactivate' => 'Stopped from signing in',
        'staff.reset' => 'Sign-in reset',
        'staff.sign_out' => 'Signed out by an admin',
        'staff.setup' => 'Set up their sign-in',
        'staff.setup_fail' => 'Sign-in set-up did not work',
        'staff.role_request' => 'Asked for Admin or Reviewer',
        'staff.role_request_reject' => 'Request for Admin or Reviewer refused',
        'staff.role_request_withdraw' => 'Request for Admin or Reviewer withdrawn',
        'login.ok' => 'Signed in',
        'login.fail' => 'Sign-in did not work',
        'logout' => 'Signed out',
        'password.change' => 'Password changed',
        'password.fail' => 'Wrong old password',
        'supplier.activate_alone' => 'Supplier switched on without a second person',
        'channel.mode' => 'Website mode changed',
        'channel.allowlist' => 'Website addresses changed',
        'channel.site_writer' => 'Stock writer switched',
        'channel.warehouse_move' => 'Website moved to another warehouse',
        'staff.setup_start' => 'Started setting up their sign-in',
        'staff.new_code' => 'Set up a new sign-in code',
        'staff.setup_closed' => 'Sign-in set-up closed after too many wrong tries',
        'staff.reset_request' => 'Asked for a reviewer\'s OK to reset a sign-in',
        'staff.reset_request_approve' => 'Reset of a sign-in OK\'d',
        'supplier.alone_checked' => 'Supplier made usable alone: checked afterwards',
        // Pack B
        'item.create' => 'Item made by hand',
        'item.check' => 'Item made by hand checked',
        'family.create' => 'Family added',
        'family.rename' => 'Family renamed',
        'family.switch' => 'Family switched off or on',
        'family.assign' => 'Item put in a family',
        'family.unassign' => 'Item taken out of its family',
        'family.bulk_assign' => 'Items put in a family together',
        'family.bulk_remove' => 'Items taken out of a family together',
        'pack.create' => 'Pack item defined',
        'pack.switch' => 'Pack item switched off or on',
        'repack.book' => 'Repack booked',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'invoice.create' => 'Supplier invoice started',
        'invoice.header' => 'Supplier invoice\'s details changed',
        'invoice.add_delivery' => 'Delivery added to a supplier invoice',
        'invoice.add_order' => 'Order added to a supplier invoice',
        'invoice.post' => 'Supplier invoice made final',
        'invoice.reverse' => 'Supplier invoice cancelled',
        'invoice.accept' => 'Differences of a supplier invoice accepted',
        'invoice.accept_dropped' => 'Acceptance of a supplier invoice\'s differences dropped: its match changed',
        'invoice.recheck' => 'Invoices checked again',
        'credit_note.create' => 'Supplier credit note started',
        'credit_note.header' => 'Supplier credit note\'s details changed',
        'credit_note.post' => 'Supplier credit note made final',
        'credit_note.reverse' => 'Supplier credit note cancelled',
        'return.create' => 'Return to a supplier started',
        'return.header' => 'Return\'s details changed',
        'return.post' => 'Return to a supplier made final',
        'return.reverse' => 'Return to a supplier cancelled',
        // Pack F (PF4-PF9)
        'channel.relay' => 'Messages from ERPNext given or ended',
        'relay.reconciliation' => 'ERPNext corrections received',
        'relay.mode' => 'ERPNext selling-mode changes received',
        'goods_in_suspense.book' => 'ERPNext line booked on an item',
        'goods_in_suspense.correction' => 'ERPNext correction placed on an item',
        'goods_in_suspense.dismiss' => 'ERPNext line closed without booking',
        'count_review.confirm' => 'ERPNext correction confirmed',
        'count_review.dismiss' => 'ERPNext correction closed',
        'policy_review.accept' => 'ERPNext selling-mode change accepted',
        'policy_review.dismiss' => 'ERPNext selling-mode change closed',
        'oversell_event.handle' => 'Oversold order handled',
        'oversell_event.note' => 'Note added to an oversold order',
        // Pack H (0038).
        'handover.create' => 'Hand-over started',
        'handover.header' => 'Hand-over\'s details changed',
        'handover.copy' => 'Hand-over copied',
        'counterparty.add' => 'Customer added',
        'counterparty.change' => 'Customer changed',
        'counterparty.switch_off' => 'Customer switched off',
        'counterparty.switch_on' => 'Customer switched on again',
        'counterparty.price_import' => 'Old prices of customers loaded',
        'counterparty.payment' => 'Payment received from a customer recorded',
        'counterparty.payment_reverse' => 'Payment received from a customer reversed',
    ];

    /** What each permission lets a person do (the "Who can do what" page; Permissions::MAP). */
    public const PERMISSION = [
        'catalogue.view' => 'Look at products and find them',
        'linking.view' => 'Look at the matching pages',
        'mapping.decide' => 'Match website products to warehouse products',
        'mapping.approve' => 'Give the second OK of a match, decide when clues disagree, join duplicates',
        'staff.view' => 'Look at staff and their access',
        'staff.manage' => 'Add staff, give jobs, reset a sign-in, stop people signing in',
        'reference.view' => 'Look at the settings, rules and lists',
        'documents.view' => 'Look at records',
        'documents.review' => 'Check other people\'s records',
        'documents.approve' => 'Give the OK a record waits for',
        'accounts.view' => 'Look at stock values for the accounts (coming later)',
        'doc.PO.post' => 'Make, confirm, send, cancel and correct purchase orders',
        'doc.GRN.post' => 'Book in deliveries and check them at the goods-in bench',
        'doc.SINV.post' => 'Book supplier invoices (Invoices)',
        'doc.DN.post' => 'Send goods back to suppliers (Returns)',
        'doc.CNT.post' => 'Count stock on the shelves and answer count differences',
        'doc.ADJ.post' => 'Correct stock and write it off (Adjustments)',
        'doc.WO.post' => 'Write stock off (coming later)',
        'doc.TRD.post' => 'Hand stock to sister shops, our shop units and trade customers (Hand-overs)',
        'doc.SIN.post' => 'Book stock in without a supplier delivery (Stock In)',
        'doc.SOUT.post' => 'Book stock out without a website sale (Stock Out)',
        'doc.TRF.post' => 'Move stock between warehouses and places (Transfers)',
        'doc.REL.post' => 'Release another account\'s stock into ours (Releases)',
        'accounts.pay' => 'Record payments to another account (Balance owed)',
        'suppliers.view' => 'Look at suppliers',
        'suppliers.manage' => 'Add and change suppliers',
        'suppliers.approve' => 'Approve suppliers',
        'purchasing.view' => 'Look at purchase orders',
        'reorder.view' => 'Look at what to buy and the sales data',
        'reorder.manage' => 'Change the what-to-buy settings and work sales out again',
        'company.edit' => 'Change the company details',
        'company.confirm' => 'Confirm the company details',
        'catalogue.edit' => 'Change product cards and barcodes',
        'receiving.view' => 'Look at deliveries',
        'incidents.view' => 'Look at incidents',
        'incidents.resolve' => 'Close incidents',
        'modes.set' => 'Set the selling mode on the websites',
        'settings.manage' => 'Change settings, approval rules, reasons and warehouses',
        'audit.view' => 'Read the audit log',
        'system.view' => 'Look at the websites\' link and the system checks',
        'staff.approve' => 'Give the OK when someone is given Admin or Reviewer',
        'sales.view' => 'Look at each store\'s orders, dispatch and returns (Sales)', // pack D (PD2)
        // Pack B
        'items.create' => 'Add items by hand (Add Item)',
        'families.manage' => 'Make families and put items in them (Families)',
        'doc.RPK.post' => 'Break packs into singles and make packs (Repack)',
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'doc.SCN.post' => 'Book supplier credit notes (Invoices)',
        'invoices.view' => 'Look at supplier invoices, credit notes and the deliveries not invoiced yet',
        'invoices.accept' => 'Accept the differences of an invoice that does not match',
        'returns.view' => 'Look at returns to suppliers',
        // Pack F (PF5)
        'queues.view' => 'Look at what the stores sent that needs a person',
        'queues.suspense' => 'Place ERPNext lines the warehouse system could not match',
        'queues.corrections' => 'Confirm or close ERPNext stock corrections',
        'queues.modes' => 'Accept or close ERPNext selling-mode changes',
        'queues.oversell' => 'Handle oversold orders and add notes',
        // ---- Pack E
        'reports.view' => 'Look at the reports: stock and sales by store, low and slow stock',
        'values.view' => 'Look at what the stock is worth in pounds',
        // ---- end Pack E
        // Pack H (0038; docs/decisions.md PH13).
        'handovers.customers' => 'Add and change the customers of hand-overs and their prices',
        'handovers.pay' => 'Record payments received from customers (Hand-overs > Balances)',
        'duty.view' => 'Look at the duty register',
    ];

    /** The "Who can do what" page (/ui/reference/access). */
    public const ACCESS = [
        'rules' => 'Rules that always apply',
        'rule_own' => 'Nobody approves or checks their own work.',
        'rule_admin' => 'Admin can only go with %s. While an account has Admin, its other jobs are switched off.',
        'rule_settings' => 'Admins and Reviewers change settings, approval rules, reasons and warehouses. Admin still never makes, checks or approves stock records or matches.',
        'rule_approvals' => 'Which work waits for a second person is on the Approval Rules page.',
        'by_job' => 'What each job may do',
        'by_task' => 'Who may do each thing',
        'task' => 'What',
        'who' => 'Who',
        'can' => 'Can:',
        'people' => 'Users',
        // ---- Pack E: the map can be changed on this page
        'rule_give' => 'Only a Reviewer gives a job something new to do, and never to a job they have themselves. Admins and Reviewers can take things away.',
        'change' => 'Change',
        'change_who' => 'Change who can',
        'fixed' => 'Fixed',
        'fixed_why' => 'This never changes here: every job needs it, or it guards staff and settings.',
        // ---- end Pack E
    ];

    // ---- Pack E (docs/decisions.md PE1-PE14): changing who can do what, and the Reports tabs -----------------------------------------

    /** One permission's page (/ui/reference/access?perm=…): who can do it, and the form that changes it. */
    public const ROLES_EDIT = [
        'now' => 'Who can do this now',
        'jobs' => 'Jobs that can do this',
        'jobs_hint' => 'Tick every job that should be able to do this.',
        'admin_never' => 'Admin never does other work.',
        'looks_only' => 'This job can only look.',
        'always' => 'This job always keeps it.',
        'yours' => 'You have this job.',
        'give_rule' => 'Giving a job this needs a Reviewer. Nobody gives it to a job they have themselves.',
        'take_rule' => 'Admins and Reviewers can take it away from a job.',
        'read_only' => 'This only lets people look.',
        'acts' => 'This lets people change things.',
        'from_code' => 'As the system was delivered: nobody has changed it yet.',
        'save' => 'Save who can do this',
        'look_only' => 'You can look; Admins and Reviewers change who can do what.',
        'back' => 'Back to Roles and permissions',
        'none' => 'Nobody',
        'saved' => 'Saved. The jobs you ticked can do this from their next page.',
        'unchanged' => 'Nothing changed: the same jobs were ticked.',
    ];

    /** The refusals of a change of who can do what, by the service's error code (the service messages stay the API's). */
    public const ROLES_ERROR = [
        'own_job' => 'You cannot give this to a job you have yourself (%s). Nothing was saved. Ask another Reviewer.',
        'loosen_needs_reviewer' => 'Only a Reviewer gives a job something new to do. Nothing was saved. You can take things away.',
        'permission_locked' => 'This never changes on this page. Nothing was saved.',
        'acting_job' => 'Admin and the look-only jobs can never do this. Nothing was saved.',
        'required_job' => '%s always keep this. Nothing was saved.',
        'no_holders' => 'At least one job must be able to do this. Nothing was saved.',
        'bad_role' => 'Choose jobs from the list. Nothing was saved.',
        'unknown_permission' => 'There is no such task. Nothing was saved.',
    ];

    /** The Reports tabs (Reports › Stock by Store, Sales by Store, Low Stock, Stock Value, Slow Stock). */
    public const REPORTS = [
        'csv' => 'Download as CSV',
        'find' => 'Find a product',
        'store' => 'Store',
        'all_stores' => 'All stores',
        'show' => 'Show',
        'clear' => 'Clear the filter',
        'product' => 'Product',
        'brand' => 'Brand',
        'status' => 'Status',
        'units' => 'Units',
        'page' => 'Page %s of %s.',
        'older' => 'Next page',
        'newer' => 'Previous page',
        'figures_at' => 'Sales and costs were last brought up to date %s.',
        'never_run' => 'The reports have not been brought up to date yet. That happens every 15 minutes.',
        'job_off' => 'Keeping the reports up to date is switched off in the settings, so these are the figures of its last run.',
        // Stock by Store
        'sells_from' => 'Sells from %s',
        'no_warehouse' => 'This store sells from no warehouse yet.',
        'matched' => 'Products matched',
        'in_stock' => 'In stock',
        'out_of_stock' => 'Out of stock',
        'can_sell' => 'Units it can sell',
        'show_all' => 'All matched products',
        'show_in' => 'In stock',
        'show_out' => 'Out of stock',
        'warehouse_units' => 'Warehouse units',
        'store_units' => 'The store can sell',
        'pack' => '1 sale = %s units',
        'own_stock' => 'The website still uses its own stock figure for this product.',
        'stopped' => 'Stopped',
        'no_matched' => 'This store has no matched products yet.',
        'no_matched_text' => 'Products are matched under Products › Mapping.',
        'none_filtered' => 'Nothing fits the filter.',
        'none_filtered_text' => 'Clear the filter to see every product.',
        'no_stores' => 'There is no store yet.',
        // Sales by Store
        'period' => 'Days',
        'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'choose' => 'Choose the days',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Show',
        'period_says' => 'From %s to %s.',
        'too_long' => 'At most %s days at once: the last days of your choice are shown.',
        'bad_dates' => 'Those days are not real dates: the last 30 days are shown.',
        'orders' => 'Orders',
        'units_sold' => 'Units sold',
        'units_sent' => 'Units sent',
        'units_cancelled' => 'Units cancelled',
        'units_returned' => 'Units returned',
        'by_store' => 'By store',
        'by_day' => 'Day by day',
        'day' => 'Day',
        'total' => 'All stores',
        'no_sales' => 'No store sent CW an order in these days.',
        'no_sales_text' => 'Stores send their orders to CW once they are switched on.',
        'sales_note' => 'A unit is one warehouse product: a sale of a pack of 3 counts 3. Each figure is counted on the day the store told CW.',
        // Low Stock
        'low' => 'Low',
        'out' => 'Out of stock',
        'show_low' => 'Low',
        'show_none' => 'None left',
        'low_at' => 'Low at',
        'own_level' => 'its own',
        'default_level' => 'A product is low at %s units or fewer, unless it has its own level.',
        'reorder' => 'Reorder page',
        'no_low' => 'No product is low.',
        'no_low_text' => 'Every product the stores sell has more than its low level.',
        'no_out' => 'No product has run out.',
        'no_out_text' => 'Every product that has had stock still has some to sell.',
        // Stock Value
        'by_product' => 'By product',
        'by_brand' => 'By brand',
        'by_warehouse' => 'By warehouse',
        'value' => 'Value',
        'avg_cost' => 'Average cost',
        'products' => 'Products',
        'no_cost' => 'Units without a cost',
        'no_cost_list' => 'Products without a cost',
        'our_value' => 'Our stock value',
        'our_value_sub' => 'At average cost, our warehouses',
        'units_costed' => 'Units with a cost',
        'units_not_costed' => 'Units without a cost',
        'units_not_costed_sub' => 'Not in the value until a price is known',
        'theirs' => 'Another account\'s stock',
        'theirs_sub' => 'Not ours: left out of our value',
        'warehouse' => 'Warehouse',
        'whose' => 'Whose',
        'ours' => 'Ours',
        'not_ours' => 'Another account\'s',
        'no_brand' => 'No brand',
        'cost_note' => 'The average cost is worked out from every delivery and stock record that had a price.',
        'past_date' => 'The value on a past date is not available yet.',
        'below_zero' => 'Products below zero are left out of the value.',
        'no_value' => 'There is no stock to value.',
        'no_value_text' => 'Stock comes in through deliveries and stock records.',
        // Slow Stock
        'slow' => 'Slow',
        'dead' => 'Dead',
        'show_slow' => 'Slow',
        'show_dead' => 'Dead',
        'slow_says' => 'Slow: no sale for %s days or more. Dead: no sale for %s days or more.',
        'last_sale' => 'Last sale',
        'never_sold' => 'No sale known',
        'days_since' => 'Days without a sale',
        'in_stock_since' => 'Stock since',
        'sale_means' => 'A sale is a paid store order, a sale booked through ERPNext, or a day of the sales history loaded from the websites.',
        'no_slow' => 'No slow product.',
        'no_slow_text' => 'Every product in stock has sold lately.',
    ];

    /** Setting up one's own sign-in (/ui/enrol, public). */
    public const ENROL = [
        'email' => 'E-mail address',
        'setup_code' => 'Set-up code from your sheet',
        'setup_code_hint' => 'Three groups of letters and digits, for example 7KQ2M-X9D4H-RT3WP. Small or capital letters both work.',
        'code' => '6-digit code from the code app on your phone',
        'code_hint' => 'It changes every 30 seconds.',
        'new' => 'Your new password (at least %s characters)',
        'again' => 'Your new password again',
        'next' => 'Next: my own sign-in code',
        'what_next' => 'Next, your own page shows a new code for your code app. You scan it, type its 6 numbers and choose your password.',
        'failed' => 'That did not work. Check your e-mail, the set-up code and the 6 numbers, and wait for the next code. Each set-up code works once. '
            . 'After a few wrong tries, or when your time runs out, ask ' . self::ASK . ' for a new sheet.',
        'back' => 'Back to sign in',
        'link' => 'First time, or told to choose a new password? Set up my sign-in',
    ];

    /**
     * The person's own page with a fresh sign-in code (/ui/new-code, public; review finding B1, docs/decisions.md Y40-Y43): after
     * "Set up my sign-in" or a sign-in with a new sign-in code, the person scans a code only they see and confirms it.
     */
    public const NEW_CODE = [
        'by' => 'Finish this now: this page works until %s, on this phone or computer only. Nobody else sees this code.',
        'step_scan' => 'In your code app, tap + and scan this QR code, or type the key below.',
        'step_old' => 'When you save, the code you used a moment ago stops working. You can then delete it from the app.',
        'step_type' => 'Type the 6 numbers the app shows for this NEW code.',
        'code' => '6-digit code of the new code in your app',
        'save_enrol' => 'Save my new code and password, and sign in',
        'save_login' => 'Save my new code and sign in',
        'failed' => 'That did not work: type the 6 numbers of the NEW code, and wait for the next one if it just changed. After a few wrong tries this page closes.',
        'gone' => 'This set-up has ended.',
        'gone_text' => 'It was finished, its time ran out, or there were too many wrong tries. If you finished it, just sign in. Otherwise ask '
            . self::ASK . ' for a new sheet.',
        'done' => 'Done: your new code works and you are signed in. Delete the old code from your app.',
    ];

    /** What a reset waiting for a reviewer's OK is (staff_role_request.kind; approvals.staff_reset, Y44). */
    public const RESET_KIND = [
        'roles' => 'a change of jobs',
        'reset_code' => 'a new sign-in code or sign-up sheet',
        'reset_password' => 'a new password',
    ];

    /**
     * Home's card for reviewers (review finding I1, Y45): every staff member added, every sign-in reset and every approval rule made
     * looser in the last days. One line each.
     */
    public const WATCH = [
        'staff.create' => '%s: %s added %s.',
        'staff.reset' => '%s: %s reset the sign-in of %s.',
        'loosened' => '%s: %s made a rule looser: %s.',
        'server' => 'the server',
        'more' => 'And %s more: see the audit log.',
        'rule_doc' => 'checks of %s',
    ];

    /** The sign-up sheet (the answer of "Add them" and "Make a new sign-in code"; shown once). */
    public const SHEET = [
        'title_signup' => 'Sign-up sheet for %s',
        'title_code' => 'New sign-in code for %s',
        'title_password' => 'New password for %s: the set-up code',
        'once' => 'Shown only now. When you leave this page, this sheet is gone.',
        'steps' => 'What %s does now',
        'step_app' => 'Install a code app on the phone, for example Google Authenticator or Microsoft Authenticator.',
        'step_scan' => 'In the app, tap + and choose "Scan a QR code". Scan the code below.',
        'step_open' => 'On the phone or a computer, open %s and choose "Set up my sign-in".',
        'step_type' => 'Type the e-mail, the set-up code below and the 6 numbers the app shows.',
        'step_password_type' => 'Type the e-mail, the set-up code below and the 6 numbers of the code app on their own phone.',
        'step_own' => 'The next page shows a new code for them only. They scan it, type its 6 numbers and choose a password of at least %s characters.',
        'step_signin' => 'Open %s and sign in as usual: the e-mail, their own password and the 6 numbers of this code.',
        'step_signin_own' => 'The next page shows a new code for them only. They scan it and type its 6 numbers. The code on this sheet then stops working.',
        'step_by' => 'Do it before %s. After that, ask %s for a new sheet.',
        'qr' => 'QR code for the code app',
        'key' => 'Cannot scan it? In the app choose "Enter a setup key" and type this key:',
        'setup_code' => 'Set-up code (it works once):',
        'code_note' => 'This code works only together with their own password, and only once.',
        'account' => 'Account name: %s',
        'done' => 'Done: back to %s',
        'requested' => 'Admin and Reviewer wait for a reviewer\'s OK. Until then they have these jobs: %s.',
        'no_address' => 'the sign-in page of CW (ask %s for its address)',
        'address_missing' => 'The sign-in address is not set, so sheets cannot print it. Set it on the Settings page: "Sign-in address".',
    ];

    /** The reviewers' list of staff access waiting for an OK (/ui/staff-requests). */
    public const STAFF_REQUESTS = [
        'none' => 'Nothing is waiting for your OK.',
        'person' => 'Person',
        'now' => 'Jobs now',
        'asked' => 'Jobs asked for',
        'by' => 'Asked by',
        'on' => 'Asked on',
        'ok' => 'OK: give these jobs',
        'note' => 'Why not? (at least 3 characters)',
        'not_ok' => 'Not OK: keep their jobs as they are',
        'yours' => 'You asked for this, or it is about you: another reviewer must decide.',
        'own_request' => 'You asked for this change, so another reviewer must decide it. Nothing was changed.',
        'own_account' => 'This is about your own access, so another reviewer must decide it. Nothing was changed.',
        'request_closed' => 'This request was decided or withdrawn already. Nothing was changed.',
        'note_required' => 'Say why not in 3 to 500 characters. Nothing was changed.',
        'role_not_allowed' => 'Only a Reviewer whose job works decides this. Nothing was changed.',
        // Who the person is and how they set up their sign-in (review finding I1), and resets (Y44).
        'what' => 'What is asked',
        'email' => 'Their e-mail',
        'made' => 'Account made',
        'set_up' => 'Set up their sign-in',
        'set_up_at' => '%s, from address %s',
        'set_up_not_yet' => 'not yet',
        'set_up_server' => 'on the server',
        'not_set_up_note' => 'They have not set up their sign-in yet. Check with the person that this is really them before you say OK.',
        'ok_reset' => 'OK: the admin may make it, once',
        'not_ok_reset' => 'Not OK: no reset',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Status chip tones: needs (you act), done, waiting (someone else), blocked, info, off

    // ==================================================================================================================
    // ---- Pack B: Item Management (docs/decisions.md PB1-PB16): Products › Add Item, Families, Multipacks; Stock › Repack.
    // The provisional words of these pages are listed in PB16. Every group below is new; the shared lists above carry this pack's keys
    // at their end, each marked "Pack B".

    /** Products › Add Item (/ui/items/new): the form and the items made by hand. */
    public const ITEM_NEW = [
        'what' => 'What it is',
        'what_hint' => 'Only the name is needed. The rest helps the matching find this item\'s website variants later.',
        'name_hint' => 'As on the box, for example "Nic shot 10ml 18mg".',
        'not_known' => 'Not known',
        'pack_hint' => 'How many units one box holds. Leave it empty for a single unit.',
        'card' => 'Product card',
        'card_hint' => 'The legal and buying details. You can also fill them in later on the item\'s page.',
        'card_brand' => 'Brand on the box',
        'card_flavour' => 'Flavour on the box',
        'barcode' => 'Barcode',
        'barcode_hint' => 'Scan or type the barcode on the box. A barcode another item has already is refused.',
        'barcode_units' => 'Items per scan',
        'barcode_units_hint' => '1 for a single; 10 for an outer case of ten.',
        'family' => 'Family',
        'family_hint' => 'All the flavours or strengths of one product. Type the family\'s name, or leave it empty.',
        'add' => 'Add the item',
        'add_does' => 'It gets the next CW number and can be used at once: in a delivery, a stock record or a match.',
        'same_title' => 'An item of this name and with these details exists already:',
        'same_text' => 'Open it to check. If yours is a different item, tick the box below and press "Add the item" again.',
        'anyway' => 'It is a different item: add it anyway',
        'card_errors' => 'Nothing was saved: some details of the product card need correcting:',
        'made' => 'Made by hand',
        'made_text' => 'The items made on this page, newest first.',
        'made_none' => 'No item was made by hand yet.',
        'made_none_text' => 'Add one with the form above.',
        'waiting_none' => 'No item made by hand waits for a check.',
        'waiting_none_text' => 'Show them all to see the ones already checked.',
        'item' => 'Item',
        'who' => 'Made by',
        'when' => 'Made on',
        'check' => 'Second check',
        'check_button' => 'Checked: it is right',
        'check_note' => 'Note (optional)',
        'check_own' => 'Someone else checks it.',
        'checked_by' => 'Checked by %s on %s.',
        'show_waiting' => 'Only those waiting for a check',
        'show_all' => 'All of them',
        'check_on' => 'A second person checks each item made by hand afterwards. The item can be used at once.',
        'check_off' => 'Nobody else checks the items made by hand: the rule is off on the Approval rules page.',
        'page' => 'Page %s of %s',
        'prev' => 'Newer',
        'next' => 'Older',
        'on_item' => 'Made by hand by %s on %s.',
        'on_item_waiting' => 'A second person has not checked it yet.',
    ];

    /** The states of an item made by hand (item_by_hand.check_state). */
    public const BY_HAND_STATE = [
        'not_asked' => 'No check asked',
        'waiting' => 'Waiting for a check',
        'checked' => 'Checked',
    ];

    /** What the pages of pack B say after a button (?notice=<key>; never text from the address). */
    public const ITEMS_NOTICE = [
        'item_made' => 'The item is added. Its CW number is at the top. Fill in the rest of its card when you know it.',
        'item_made_waiting' => 'The item is added and can be used now. A second person checks it on Products › Add Item.',
        'checked' => 'Checked. The item no longer waits for a check.',
        'family_made' => 'The family is added. Put its items in it below.',
        'family_renamed' => 'The family is renamed.',
        'family_off' => 'The family is switched off. Its items keep it; it takes no new ones.',
        'family_on' => 'The family is switched on again.',
        'family_set' => 'The item is in that family now.',
        'family_out' => 'The item is taken out of its family.',
        'unchanged' => 'Nothing changed: it was already so.',
        'pack_made' => 'The pack item is saved. Stock › Repack can now break it into singles or make it from them.',
        'pack_off' => 'The pack item is switched off. Repacks can no longer use it; the records that used it stay.',
        'pack_on' => 'The pack item is switched on again.',
    ];

    /** A refusal on the pages of pack B, by error code (the services' messages stay the API's). */
    public const ITEMS_ERROR = [
        'bad_name' => 'Nothing was saved: a family\'s name is 2 to 255 letters on one line.',
        'bad_note' => 'Nothing was saved: the note is too long.',
        'family_exists' => 'Nothing was saved: a family of this name is in use already. Use that one, or choose another name.',
        'family_changed' => 'Nothing was saved: someone changed this family while you had it open. Reload the page and try again.',
        'family_moved' => 'Nothing was saved: this item changed family while you had the page open. Reload the page and check.',
        'family_off' => 'Nothing was saved: this family is switched off. Switch it on first, or choose another.',
        'unknown_family' => 'Nothing was saved: no family in use has that name. Check the spelling, or add the family first on Products › Families.',
        'empty_list' => 'Nothing was saved: type or paste at least one CW number or barcode.',
        'too_many_rows' => 'Nothing was saved: one change takes at most %s items. Split the list.',
        'merged_item' => 'Nothing was saved: this item was joined into another one. Use that one.',
        'unknown_item' => 'Nothing was saved: no item has that CW number or barcode.',
        'same_item' => 'Nothing was saved: the pack item and its single are two different items.',
        'bad_units' => 'Nothing was saved: check the number of units.',
        'pack_exists' => 'Nothing was saved: this item is a pack item already. Switch that one off first.',
        'pack_nested' => 'Nothing was saved: a pack is made of single items, never of other packs.',
        'unknown_pack' => 'Nothing was saved: choose a pack item from the list.',
        'pack_off' => 'Nothing was saved: this pack item is switched off. Switch it on again on Products › Multipacks.',
        'bad_direction' => 'Nothing was saved: choose whether to break packs or to make packs.',
        'bad_packs' => 'Nothing was saved: say how many packs, a whole number of 1 or more.',
        'below_zero' => 'Nothing was saved: this would take a product the website sells from warehouse stock below zero. Check the shelf first, or repack fewer.',
        'repack_lines' => 'Nothing was saved: this repack does not match its pack item. Stop it and book a new one.',
        'warehouse_required' => 'Nothing was saved: choose the warehouse.',
        'warehouse_inactive' => 'Nothing was saved: that warehouse is switched off.',
        'barcode_on_other_item' => 'Nothing was saved: this barcode belongs to another item. Open it to check.',
        'name_required' => 'Nothing was saved: type the item\'s name.',
        'bad_card' => 'Nothing was saved: check what you typed in "What it is".',
        'own_item' => 'Someone else checks this item: not the person who made it.',
        'not_waiting' => 'This item does not wait for a check any more.',
        'not_draft' => 'Nothing was changed: this repack is not a draft any more. Reload the page.',
        'version_conflict' => 'Nothing was changed: this repack changed meanwhile. Reload the page.',
        'not_awaiting_approval' => 'Nothing was changed: this repack does not wait for an OK any more.',
        'bad_cancel_reason' => 'Nothing was changed: say in a few words why the repack is stopped.',
        'admin_cannot_post' => 'Admins never book stock: ask someone who moves stock.',
        'admin_cannot_edit' => 'Admins never change items: ask the catalogue team.',
        'role_not_allowed' => 'Your jobs do not allow this. Nothing was saved.',
    ];

    /** Products › Families (/ui/items/families, a family's page, the item page's family box). */
    public const FAMILY = [
        'search' => 'Family name',
        'show_off' => 'Show the switched-off ones too',
        'clear' => 'Clear the search',
        'name' => 'Family',
        'items' => 'Items',
        'state' => 'State',
        'on' => 'In use',
        'off' => 'Switched off',
        'none' => 'No family yet.',
        'none_text' => 'Add the first one with "New family".',
        'none_filter' => 'No family has this name.',
        'none_filter_text' => 'Clear the search to see them all.',
        'add' => 'New family',
        'add_name' => 'Name',
        'add_name_hint' => 'What people call the product, for example "Elux Legend 3500".',
        'note' => 'Note (optional)',
        'add_button' => 'Add the family',
        'count_one' => '1 family',
        'count_many' => '%s families',
        'page' => 'Page %s of %s',
        'prev' => 'Previous page',
        'next' => 'Next page',
        'back' => 'Families',
        'made' => 'Added by %s on %s.',
        'changed' => 'Last changed by %s on %s.',
        'rename' => 'Rename the family',
        'rename_label' => 'New name',
        'rename_button' => 'Save the new name',
        'switch_off' => 'Switch the family off',
        'switch_off_does' => 'Its items keep it. It takes no new items and is left out of the choices. Nothing is deleted.',
        'switch_on' => 'Switch the family on again',
        'is_off' => 'This family is switched off. Its items keep it; it takes no new ones.',
        'members' => 'Items in this family',
        'members_count' => '%s items',
        'members_none' => 'No item is in this family yet.',
        'members_none_text' => 'Add them with "Add items".',
        'code' => 'CW number',
        'item' => 'Item',
        'brand' => 'Brand',
        'flavour' => 'Flavour',
        'strength' => 'Strength',
        'on_hand' => 'In stock',
        'since' => 'In it since',
        'tick' => 'Tick %s',
        'remove_button' => 'Take the ticked items out of the family',
        'remove_does' => 'They stay items; they are only no longer in this family.',
        'bulk' => 'Add items',
        'bulk_label' => 'CW numbers or barcodes',
        'bulk_hint' => 'Paste or type them one per line, or with commas. An item in another family, joined into another or not found is left out and listed.',
        'bulk_button' => 'Put them in this family',
        'result_added' => 'Put in the family (%s):',
        'result_removed' => 'Taken out of the family (%s):',
        'result_skipped' => 'Left out (%s), with the reason:',
        'result_none' => 'No item was changed.',
        'item_box' => 'Family and packs',
        'item_box_short' => 'Family',
        'of_item' => 'Family: %s',
        'in_none' => 'In no family.',
        'in_off' => 'switched off',
        'open' => 'See every item of this family',
        'put_label' => 'Family name',
        'put_hint' => 'Type the name of a family in use. Empty: take the item out of its family.',
        'put_button' => 'Save the family',
        'look_only' => 'You can look; the catalogue team and the buyers change families.',
    ];

    /** Why a line of a bulk change of a family was left out (Items\Families::SKIPS). */
    public const FAMILY_SKIP = [
        'not_found' => 'No item has this CW number or barcode.',
        'merged' => 'This item was joined into another one: use that one.',
        'repeated' => 'This item is on the list more than once: done once.',
        'already_here' => 'Already in this family.',
        'in_other_family' => 'In another family: %s. Move it on its own page if you mean to.',
        'not_in_family' => 'No longer in this family.',
    ];

    /** Products › Multipacks: the pack items, the pack links, the item page's pack box. */
    public const PACKS = [
        'pack_item' => 'Pack item',
        'single' => 'Single item',
        'equals' => '1 pack = %s singles',
        'stock' => 'In stock',
        'stock_line' => '%s packs · %s singles',
        'state' => 'State',
        'on' => 'In use',
        'off' => 'Switched off',
        'checks' => 'What to check',
        'nothing' => 'Nothing to check.',
        'add' => 'Define a pack item',
        'add_text' => 'A pack item is a sealed multipack kept in stock as packs, for example a box of 10 nic shots. Say which item it is, which single it holds '
            . 'and how many. Nothing is matched or moved: Stock › Repack breaks packs into singles or makes packs from singles.',
        'pack_label' => 'Pack item (CW number or barcode)',
        'single_label' => 'Single item (CW number or barcode)',
        'units_label' => 'Singles in one pack',
        'note' => 'Note (optional)',
        'add_button' => 'Save the pack item',
        'switch_off' => 'Switch off',
        'switch_on' => 'Switch on again',
        'switch_does' => 'A pack item is never deleted. Switched off, repacks cannot use it; the records that used it stay.',
        'repack' => 'Repack it',
        'none' => 'No pack item is defined yet.',
        'none_text' => 'Define one when a multipack is kept in stock as packs.',
        'none_filter' => 'No pack item fits this search.',
        'clear' => 'Clear the search',
        'search' => 'CW number or name',
        'show_off' => 'Show the switched-off ones too',
        'count_one' => '1 pack item',
        'count_many' => '%s pack items',
        'suggest_title' => 'Could be sold as singles:',
        'suggest_text' => 'The single item is sold on its own, so each of these variants could take %s singles a sale instead of 1 pack. '
            . 'A person decides on the variant\'s page, and a second matching lead says OK. Nothing changes by itself.',
        'suggest_open' => 'Open the variant',
        'own_product' => 'Its variants sell it as its own product, 1 unit a sale: the single item is not sold on its own.',
        'rule_on' => 'A pack is suggested as singles only when its single is sold on its own.',
        'rule_off' => 'A pack is suggested as singles even when its single is not sold on its own (the rule is off on the Settings page).',
        'how' => 'Two ways of selling a pack',
        'how_link' => 'A variant matched with more than 1 unit a sale: one sale takes that many singles from the single item\'s stock. Set on the variant\'s page.',
        'how_item' => 'A pack item: the pack has its own stock, kept in packs. Stock › Repack breaks packs into singles or makes packs from them.',
        'links_none' => 'No variant is matched with more than 1 unit a sale.',
        'links_none_text' => 'A variant that sells a multipack of a single item (for example 10 x 10ml) gets its units on its own page.',
        'variant' => 'Variant',
        'store' => 'Store',
        'item' => 'Item',
        'per_sale' => 'Units a sale',
        'links_count_one' => '1 variant',
        'links_count_many' => '%s variants',
        'pack_is' => 'This is a pack item: 1 pack = %s singles of',
        'packs_of' => 'Pack items made of it:',
        'item_box' => 'Packs',
        'item_none' => 'Not a pack item, and no pack item is made of it.',
        'page' => 'Page %s of %s',
        'prev' => 'Previous page',
        'next' => 'Next page',
        'look_only' => 'You can look; the catalogue team defines pack items.',
    ];

    /** What the Multipacks page says of a pack item (Items\Packs::FLAGS). */
    public const PACK_FLAG = [
        'single_sold' => 'The single is sold on its own.',
        'sold_both_ways' => 'Sold two ways: a variant also takes this many singles a sale, while the pack item keeps stock of its own.',
        'pack_of_packs' => 'A variant sells more than one of this pack a sale.',
        'count_differs' => 'The two product cards disagree on how many are in the pack.',
        'suggest' => 'Could be sold as singles.',
    ];

    /** What the pack links list says of a variant matched with more than 1 unit a sale (Items\Packs::LINK_FLAGS). */
    public const PACK_LINK_FLAG = [
        'pack_item_exists' => 'A pack item of this many singles exists: %s. Its stock is kept apart from the singles.',
        'item_is_pack' => 'Its item is a pack item itself: this sells packs of packs.',
    ];

    /** Stock › Repack (/ui/stock/repack). */
    public const REPACK = [
        'add' => 'Book a repack',
        'add_text' => 'Break sealed packs into singles, or make packs from singles. Choose the pack item, which way, and how many packs.',
        'warehouse' => 'Warehouse',
        'pack' => 'Pack item',
        'pack_choice' => '%s %s = %s x %s %s',
        'direction' => 'Which way',
        'packs' => 'How many packs',
        'note' => 'Note (optional)',
        'book' => 'Book the repack',
        'book_does' => 'The stock changes at once on both items, by the same goods. A mistake is put right by cancelling the record.',
        'no_packs' => 'No pack item is defined yet.',
        'no_packs_link' => 'Define one on Products › Multipacks',
        'record' => 'Repack',
        'status' => 'State',
        'what' => 'What',
        'where' => 'Where',
        'made_by' => 'Made by',
        'line_break' => '%s packs of %s into %s singles of %s',
        'line_make' => '%s singles of %s into %s packs of %s',
        'none' => 'No repack yet.',
        'none_text' => 'Book one above when packs are broken or made.',
        'none_filter' => 'No repack fits this search.',
        'search' => 'Repack number or CW number',
        'state' => 'Show',
        'any_state' => 'Every state',
        'clear' => 'Clear the search',
        'done_posted' => '%s is booked.',
        'done_waiting' => 'The repack waits for a reviewer\'s OK: nothing is booked until then.',
        'done_other' => 'The repack is saved as a draft.',
        'open' => 'Open it',
        'final' => 'Make it final',
        'stop' => 'Stop it',
        'stop_reason' => 'Why (a few words)',
        'take_back' => 'Take the OK request back',
        'cancel_how' => 'To cancel a final repack, open it and use the cancel box: a new record puts everything back.',
        'limit' => 'The newest %s are shown: search to find older ones.',
        'cancels' => 'Cancels %s',
        'look_only' => 'You can look; the stock controllers and the warehouse book repacks.',
    ];

    /** Which way a repack goes (repack.direction). */
    public const REPACK_DIRECTION = [
        'break' => 'Break packs into singles',
        'make' => 'Make packs from singles',
    ];

    // ---- end of pack B's groups
    // ==================================================================================================================
    // ------------------------------------------------------------------------------------------------------------------
    // --- Pack C (IM7, docs/decisions.md PC1-PC17): Purchasing › Invoices and Purchasing › Returns.

    /** The supplier invoice and credit note pages: shared words. */
    public const INVOICES = [
        'search' => 'Number, their number or supplier',
        'search_delivery' => 'Delivery number, their invoice number or supplier',
        'supplier' => 'Supplier',
        'any_supplier' => 'Every supplier',
        'match' => 'Match',
        'any_match' => 'Every match',
        'record' => 'Record',
        'credits' => 'Reduces the invoice',
        'on_account' => 'No invoice (a credit on account)',
        'debit_note' => 'Answers our return',
        'no_return' => 'No return',
        'reversal' => 'Cancellation record',
        'posted' => 'Final',
        'lines_off' => 'Lines off',
        'difference' => 'Charged over',
        'accepted_by' => 'Accepted by',
        'net' => 'Total without VAT',
        'net_hint' => 'Exactly as the paper says it.',
        'vat' => 'VAT',
        'vat_hint' => 'Exactly as the paper says it: the system never works VAT out.',
        'gross_hint' => 'Optional: when typed, it must be the two above added up.',
        'credited' => 'Credited by credit notes',
        'owed' => 'Owed now',
        'open_invoice' => 'The invoice it reduces',
        'open_return' => 'The return it answers',
        'rules_now' => 'Within the tolerance: a price up to %s%% or %s off a line, %s%% more units than received, the total up to %s a line off.',
        // --- Pack C follow-up (PC19, the owner's decision of 15 Oct 2026): the invoice's price is the right price.
        'rules_no_price' => 'Prices are not checked: the price on the invoice is used.',
        'rules_now_units' => 'Within the tolerance: %s%% more units than received, the total up to %s a line off.',
        'price_info_more' => 'Price differs from the order: %s more. The invoice price is used.',
        'price_info_less' => 'Price differs from the order: %s less. The invoice price is used.',
        'price_info_more_delivery' => 'Price differs from the delivery: %s more. The invoice price is used.',
        'price_info_less_delivery' => 'Price differs from the delivery: %s less. The invoice price is used.',
        'rules_link' => 'Change the tolerances',
        'show_to_check' => 'Show the invoices to check',
        'show_accepted' => 'Show the invoices whose differences were accepted',
        'recheck' => 'Check these again',
        'recheck_hint' => 'After a tolerance changed: works every listed invoice out again with the tolerances now.',
        'none_to_match' => 'Every final invoice matches.',
        'none_to_match_text' => 'An invoice that does not match its order or delivery shows here.',
        'none_accepted' => 'No invoice has accepted differences.',
        'none_waiting' => 'No delivery waits for its invoice.',
        'none_waiting_text' => 'Every delivery booked in is invoiced.',
        'waiting_total' => 'Waiting to be invoiced: %s at the deliveries\' prices.',
        'delivery' => 'Delivery',
        'arrived' => 'Arrived',
        'units_left' => 'Units to invoice',
        'value_left' => 'Worth',
        'book_invoice' => 'Book its invoice',
        'problems' => 'What stops it from being final',
        'match_title' => 'How it matches',
        'match_text' => 'Each goods line against its order and its delivery, as it is now.',
        'match_preview' => 'Each goods line against its order and its delivery, if it were made final now.',
        'match_off' => '%s lines are outside the tolerance: %s charged over what was ordered and received.',
        'total_rounding' => 'The lines differ from the stated total by %s (rounding, within the tolerance).',
        'accepted_line' => 'Differences accepted by %s on %s: %s',
        'accept_title' => 'Accept the differences',
        'accept_text' => 'Accept when the supplier is right (a price rise agreed, a delivery booked short by mistake). Otherwise ask the supplier for a credit note. If the invoice changes again, it needs a person again.',
        'accept_why' => 'Why they are accepted',
        'accept_button' => 'Accept the differences',
        'lines' => 'Lines',
        'no_lines' => 'No lines yet. Add a delivery, an item or a charge below.',
        'line' => 'Line',
        'line_kind' => 'Kind',
        'packs' => 'Packs',
        'pack_price' => 'Price of a pack in £',
        'unit_price' => 'Price of a unit in £ (optional)',
        'units' => 'Units',
        'amount' => 'Amount without VAT',
        'description' => 'What it is for',
        'for' => 'Bills',
        'for_delivery' => '%s line %s',
        'for_order' => 'Order %s line %s',
        'credits_line' => 'Credits line %s',
        'lines_add_up' => 'The lines add up to %s without VAT.',
        'add_title' => 'Add lines',
        'add_delivery' => 'A delivery of this supplier still to invoice',
        'add_delivery_button' => 'Add its lines',
        'no_deliveries' => 'This supplier has no delivery left to invoice.',
        'add_order' => 'An order (the goods have not come yet)',
        'add_order_button' => 'Add its lines',
        'add_order_hint' => 'Lines of an order without its delivery do not match until the goods are booked in: use the delivery when you can.',
        'credit_line' => 'Line of the invoice it credits',
        'credit_line_choice' => 'Line %s: %s (%s units left to credit)',
        'credit_needs_invoice' => 'Choose the invoice it reduces in the details below to credit its lines.',
        'delivery_choice' => '%s of %s: %s units to invoice',
        'stop_text' => 'A stopped draft is kept, never deleted. Its number is free again.',
        'credit_notes' => 'Credit notes',
        'no_credit_notes' => 'No credit note reduces this invoice.',
        'book_credit' => 'Book a credit note for this invoice',
        'events_title' => 'For the stock value',
        'events_text' => 'What this paper changes in the value of stock already booked in. It is kept for the stock value, which comes later: nothing is valued yet.',
        'event_item' => 'Product',
        'event_kind' => 'What',
        'event_basis' => 'How it was worked out',
        'billed' => '%s billed for %s',
        'there' => '%s there to invoice',
        'nothing_there' => 'no delivery',
        'no_ref' => 'no price to compare',
        'ref_order' => 'ordered at %s a unit',
        'ref_delivery' => 'delivered at %s a unit',
        'credited_units' => '%s units credited',
        // A delivery's page (Purchasing › Goods In).
        'receipt_invoices' => 'Its supplier invoice',
        'receipt_none' => 'No supplier invoice bills this delivery yet.',
        'receipt_left' => '%s items of it are still to invoice.',
    ];

    /** Words that differ between an invoice and a credit note (SupplierInvoices kinds). */
    public const INVOICE_KIND = [
        'invoice' => [
            'tab' => 'Supplier invoices',
            'one' => 'Supplier invoice',
            'number' => 'Their invoice number',
            'date' => 'Date on the invoice',
            'gross' => 'Total to pay',
            'start' => 'Book an invoice by hand',
            'start_text' => 'For an invoice that came with a delivery, use "Not invoiced yet": it fills the lines for you.',
            'start_button' => 'Start the invoice',
            'none' => 'No supplier invoice yet.',
            'none_text' => 'Book the first from "Not invoiced yet".',
            'how' => ['Open "Not invoiced yet" and press "Book its invoice" on the delivery: its number, date, copy and lines come with it.',
                'Check the lines against the paper. Several deliveries on one invoice: add each. Freight, duty: add a line.',
                'Type the total without VAT and the VAT exactly as the paper says, then make it final.',
                'The invoice is checked against the order and the delivery. One that does not match goes to "To match".'],
            'details' => 'The invoice\'s number, date and totals',
            'post_title' => 'Make it final',
            'post_does' => 'It is checked against the order and the delivery: if it does not match, it shows on "To match". It moves no stock.',
            'post_button' => 'Make the invoice final',
            'cancel_text' => 'A final invoice is never changed or deleted. Cancelling it makes a cancellation record; its number is free again. An invoice with a credit note is cancelled after its credit note.',
        ],
        'credit_note' => [
            'tab' => 'Credit notes',
            'one' => 'Credit note',
            'number' => 'Their credit note number',
            'date' => 'Date on the credit note',
            'gross' => 'Total credited',
            'start' => 'Book a credit note by hand',
            'start_text' => 'For a credit note that answers one of our returns, open the return and press "Book the supplier\'s credit note".',
            'start_button' => 'Start the credit note',
            'none' => 'No credit note yet.',
            'none_text' => 'Book one from a return, or from the invoice it reduces.',
            'how' => ['Open the return it answers and press "Book the supplier\'s credit note", or open the invoice it reduces.',
                'Credit the units of each invoice line it covers; add duty or other lines for the rest.',
                'Type the total without VAT and the VAT exactly as the paper says, then make it final.',
                'It lowers what is owed on the invoice, and a line credited is billed less.'],
            'details' => 'The credit note\'s number, date and totals',
            'post_title' => 'Make it final',
            'post_does' => 'It lowers what is owed on the invoice it reduces, and the invoice is checked again.',
            'post_button' => 'Make the credit note final',
            'cancel_text' => 'A final credit note is never changed or deleted. Cancelling it makes a cancellation record; what it took off the invoice comes back.',
        ],
    ];

    /** sinv_line.kind */
    public const INVOICE_LINE = [
        'goods' => 'Goods',
        'freight' => 'Freight',
        'duty' => 'Duty',
        'service' => 'Service',
        'other' => 'Other',
    ];

    /** supplier_invoice.match_state: how a posted invoice matches its orders and deliveries. */
    public const MATCH_STATE = [
        'matched' => 'Matched',
        'within_tolerance' => 'Within the tolerance',
        'needs_review' => 'Needs review',
        'accepted' => 'Differences accepted',
    ];

    /** One goods line's match. */
    public const MATCH_LINE = [
        'matched' => 'Matched',
        'within' => 'Within the tolerance',
        'off' => 'Off',
    ];

    /** Why a line does not match (InvoiceMath::WHY). */
    public const MATCH_WHY = [
        'no_delivery' => 'No delivery: nothing booked in to bill.',
        'delivery_reversed' => 'Its delivery was cancelled.',
        'qty_over' => 'Bills more units than are there to invoice.',
        'price_over' => 'Dearer than ordered.',
        'price_under' => 'Cheaper than ordered.',
    ];

    /** invoice_value_event.kind */
    public const VALUE_EVENT = [
        'price_difference' => 'Price differs from the delivery',
        'freight' => 'Freight',
        'duty' => 'Duty',
        'credit' => 'Credited',
    ];

    /** invoice_value_event.basis */
    public const VALUE_BASIS = [
        'delivery_cost' => 'Against the delivery\'s price',
        'value' => 'Shared by value',
        'ml' => 'Shared by liquid',
        'line' => 'The line itself',
    ];

    /** What the supplier's credit notes did to a return's claim. */
    public const CREDIT_STATE = [
        'open' => 'Not credited yet',
        'part' => 'Part credited',
        'credited' => 'Credited',
    ];

    /** The roles of a file kept with an invoice, a credit note or a return. */
    public const INVOICE_FILE = [
        'supplier_invoice' => 'The supplier\'s paper (PDF or photo)',
        'delivery_note' => 'Delivery note',
        'photo' => 'Photo',
        'evidence' => 'Other evidence',
    ];

    /** After a change of an invoice or a credit note. */
    public const INVOICE_NOTICE = [
        'created' => 'Started. Add its lines, type the totals, then make it final.',
        'from_receipt' => 'Started from the delivery: check the lines against the paper, type the total without VAT and the VAT, then make it final.',
        // Pack J (PJ6, PJ10)
        'receiving_saved' => 'Saved: how its goods come in.',
        'timed' => 'The time is set: it becomes final by itself then.',
        'untimed' => 'The time is taken off: it stays a draft.',
        'number_taken' => 'Started from the delivery. Its invoice number is already on %s: type this invoice\'s own number.',
        'from_return' => 'Started from the return: type the supplier\'s credit note number, date and totals, then make it final.',
        'header' => 'Saved.',
        'lines' => 'Lines saved.',
        'delivery_added' => 'The delivery\'s lines were added.',
        'order_added' => 'The order\'s lines were added.',
        'item_added' => 'Added.',
        'charge_added' => 'Added.',
        'credit_added' => 'Added.',
        'posted' => 'Done: %s is final.',
        'reversed' => 'Cancelled by %s.',
        'submitted' => 'Sent to a reviewer: it is final when they say OK.',
        'cancelled' => 'Stopped. It is kept, and its number is free again.',
        'withdrawn' => 'Taken back: it is a draft again.',
        'file' => 'File kept.',
        'file_again' => 'This file was already kept with it.',
        'accepted' => 'Accepted. The invoice leaves the "To match" list.',
        'rechecked' => 'Checked again: %s changed.',
    ];

    /** A refused change of an invoice or a credit note, by error code (a code without words keeps the service's sentence). */
    public const INVOICE_ERROR = [
        'on_line' => 'Line %s: %s',
        'supplier_required' => 'Choose the supplier.',
        'delivery_not_posted' => 'That delivery is not booked in (or was cancelled).',
        'return_not_posted' => 'Only a final return gets a credit note.',
        'order_not_posted' => 'That order is not confirmed.',
        'bad_packs' => 'Packs are a whole number above 0.',
        'bad_units' => 'Units are a whole number above 0.',
        'credit_line_mismatch' => 'That line of the invoice cannot be credited here: it is another item or another delivery.',
        'credit_left' => 'Not that many: %s units are left to credit on that line.',
        'duplicate_invoice' => 'The supplier\'s number %s is already booked as %s. A supplier\'s paper is booked once: cancel the other one first if it was keyed by mistake.',
        'accept_note' => 'Say in a few words why the differences are accepted.',
        'invoice_number_required' => 'Type the supplier\'s number from the paper.',
        'invoice_date_required' => 'Type the date on the paper.',
        'invoice_date_future' => 'The date on the paper is after today: check it.',
        'totals_required' => 'Type the total without VAT and the VAT exactly as the paper says them.',
        'credited_invoice_not_posted' => 'The invoice it reduces must be a final invoice of the same supplier.',
        'debit_note_not_posted' => 'The return it answers must be a final return to the same supplier.',
        'credited_invoice_required' => 'Choose the invoice it reduces before crediting its lines.',
        'credit_line_required' => 'Goods on a credit note credit a line of the invoice it reduces: use an "other" line for anything else.',
        'bad_cost' => 'A price is in pounds, not below 0, with at most 6 decimals.',
        'invoice_line_missing' => 'A line lost its details: save the lines again.',
        'bad_invoice_line' => 'A line is not complete: goods need packs and a price, other lines an amount.',
        'delivery_other_supplier' => 'That delivery is another supplier\'s.',
        'delivery_line_mismatch' => 'That delivery line is another item.',
        'order_other_supplier' => 'That order is another supplier\'s.',
        'order_line_mismatch' => 'That order line is another item.',
        'has_credit_notes' => 'This invoice has a final credit note: cancel the credit note first, then the invoice.',
        'has_credit_lines' => 'Remove the lines that credit the invoice first, then choose another invoice.',
        'gross_mismatch' => 'The total to pay must be the total without VAT and the VAT added up: check the three figures on the paper.',
        'nothing_to_invoice' => 'Everything of it is invoiced already (or went back to the supplier).',
        'not_an_invoice' => 'This is not a supplier invoice or credit note.',
        'own_invoice' => 'You made this invoice final: another person accepts its differences.',
        'not_needs_review' => 'This invoice does not need review now: nothing to accept.',
        'match_changed' => 'The figures changed since this form was opened. Check the figures shown now before accepting the differences.',
        'bad_amount' => 'An amount is in pounds with at most 2 decimals.',
        'bad_price' => 'A price is in pounds with at most 4 decimals.',
        'bad_line' => 'A line is not complete: check its numbers.',
        'too_many_lines' => 'Too many lines for one paper.',
        'total_negative' => 'The lines add up to less than £0.',
        'reason_required' => 'Say why the goods go back.',
        'reason_direction' => 'That reason puts stock up: a return takes it down.',
        'bad_return_line' => 'A line is not complete: goods need units and a price, a claim an amount above £0 and what it is for.',
        'return_line_missing' => 'A line lost its details: save the lines again.',
        'warehouse_required' => 'Say which warehouse the goods leave.',
        'warehouse_not_ours' => 'A return leaves one of our own warehouses that is switched on.',
        'not_own_stock' => 'That warehouse holds another account\'s stock: only our own goes back to our supplier.',
        'price_required' => 'Say the price per unit claimed back.',
        'invoice_not_posted' => 'The invoice must be a final invoice of the same supplier.',
        'nothing_to_return' => 'That delivery put nothing aside that has not gone back already.',
        'not_a_return' => 'This is not a return to a supplier.',
    ];

    /** The returns pages. */
    public const RETURNS = [
        'how' => ['Damaged or unstamped goods of a delivery: use "Return what a delivery put aside". Other goods: start a return by hand.',
            'Check the units, where they leave from, the price claimed and the duty paid on them (a retailer cannot reclaim duty on damaged stock).',
            'Make it final: the goods leave the stock, and the return is our claim (a debit note).',
            'When the supplier\'s credit note comes, book it from the return: it lowers what is owed.'],
        'quick_title' => 'Return what a delivery put aside',
        'quick_text' => 'Its damaged, wrong and over units, and its unstamped ones, at the delivery\'s price.',
        'quick_button' => 'Start the return',
        'start' => 'Start a return by hand',
        'start_text' => 'Choose the supplier, where the goods leave from and why they go back.',
        'start_button' => 'Start the return',
        'delivery_choice' => '%s of %s: %s units put aside',
        'from' => 'Leaves from',
        'return_warehouse' => 'The return\'s warehouse',
        'reason' => 'Why it goes back',
        'reason_of_return' => 'The return\'s reason',
        'ref' => 'Their return or collection reference',
        'date' => 'Date it went back',
        'delivery' => 'The delivery it came with',
        'no_delivery' => 'No delivery',
        'invoice' => 'The invoice it is about',
        'no_invoice' => 'No invoice',
        'claim' => 'Claimed back',
        'credited' => 'Credited',
        'none' => 'No return yet.',
        'none_text' => 'A return takes goods out of the stock and claims a credit from the supplier.',
        'is_debit_note' => 'Once final, this return is our debit note: what it claims is its lines\' amounts without VAT.',
        'lines' => 'What goes back',
        'no_lines' => 'Nothing yet. Add an item or a claim below.',
        'price' => 'Price of a unit claimed in £',
        'duty' => 'Duty claimed in £',
        'price_hint' => 'Leave the price empty to take this supplier\'s last price, else the average cost so far.',
        'claim_total' => 'Claimed: %s without VAT.',
        'claim_amount' => 'Claim without goods, in £',
        'claim_for' => 'What it is for (short delivery, overcharge)',
        'claim_button' => 'Add the claim',
        'details' => 'The return\'s details',
        'post_title' => 'Make it final',
        'post_does' => 'The goods leave the stock now, from where each line says, and the claim is our debit note.',
        'post_button' => 'Make the return final',
        'no_credit_notes' => 'No credit note answers this return yet.',
        'book_credit' => 'Book the supplier\'s credit note',
        'cancel_text' => 'A final return is never changed or deleted. Cancelling it makes a cancellation record and the goods come back into stock.',
    ];

    /** After a change of a return. */
    public const RETURN_NOTICE = [
        'claim_started' => 'The claim of what did not come is started: check it, then make it final (no stock moves).', // pack J
        'created' => 'Started. Add what goes back, then make it final.',
        'from_receipt' => 'Started from the delivery: check the units and the price claimed, choose why they go back, then make it final.',
        'header' => 'Saved.',
        'lines' => 'Lines saved.',
        'item_added' => 'Added.',
        'claim_added' => 'Added.',
        'posted' => 'Done: %s is final and the goods left the stock.',
        'reversed' => 'Cancelled by %s: the goods are back in stock.',
        'reversal_submitted' => 'The cancellation puts many goods back: it waits for a reviewer\'s OK.',
        'submitted' => 'Sent to a reviewer: it is final when they say OK.',
        'cancelled' => 'Stopped. It is kept, never deleted.',
        'withdrawn' => 'Taken back: it is a draft again.',
        'file' => 'File kept.',
        'file_again' => 'This file was already kept with it.',
    ];

    /** A refused change of a return (the rest: INVOICE_ERROR). */
    public const RETURN_ERROR = [
        'qty_positive' => 'Units are a whole number above 0.',
    ];

    // ==================================================================================================================
    // ---- Pack F: Approvals › From the stores, the relay and the alerts (docs/decisions.md PF1-PF14) -----------------

    /** A row of the stores' queues (QueueRules::STATUSES). */
    public const QUEUE_STATE = [
        'open' => 'Waiting',
        'resolved' => 'Done',
        'dismissed' => 'Closed',
    ];

    /** The words every queue page of Approvals › From the stores shares. */
    public const QUEUES = [
        'show' => 'Show',
        'all' => 'All',
        'filter' => 'Show these',
        'total_one' => 'One row.',
        'total_many' => '%s rows.',
        'more' => 'The first %s are shown. Answer these to see the rest.',
        'note' => 'Note: what you did, or why',
        'from' => 'From %s',
        'store_unknown' => 'a store',
        'someone' => 'someone',
        'received' => 'Arrived %s.',
        'closed' => '%s on %s by %s: %s',
        'closed_cw' => '%s on %s by the warehouse system.',
        'none_open' => 'Nothing is waiting here.',
        'none' => 'Nothing matches this filter.',
        'none_text' => 'Show what is waiting instead.',
        'show_open' => 'Show what is waiting',
    ];

    /** What a relayed ERPNext line is (relay_line kinds and goods-in suspense's movement types). */
    public const RELAY_KIND = [
        'goods_in' => 'Delivery',
        'supplier_return' => 'Return to a supplier',
        'erp_sale' => 'ERPNext sale',
        'erp_reconciliation' => 'ERPNext correction',
        'erp_mode' => 'ERPNext selling-mode change',
    ];

    /** Why a relayed line could not be placed (goods_in_suspense.reason). */
    public const RELAY_REASON = [
        'unknown_listing' => 'the store\'s variant is not known here yet',
        'unlinked_listing' => 'the store\'s variant is not matched to an item',
        'unknown_erp_item' => 'the ERPNext item is not known here',
        'unknown_sku' => 'the item is not known here',
    ];

    /** Approvals › From the stores › Lines to place. */
    public const SUSPENSE = [
        'title' => '%s: %s of "%s"',
        'no_description' => 'no description',
        'document' => 'ERPNext document %s, line %s.',
        'variant' => 'variant %s',
        'erp_item' => 'ERPNext item %s',
        'names' => 'The store named it: %s.',
        'why' => 'Why it waits: %s.',
        'picked' => 'Placed on "%s" (%s).',
        'pick_legend' => 'Place it on an item',
        'item' => 'Item code',
        'item_hint' => 'Like CW-000123. Find the item under Products.',
        'units' => 'Item units in one unit of this line',
        'units_hint' => 'Leave 1 unless the line counts packs.',
        'book' => 'Book it on this item',
        'book_hint' => 'This books the stock now, in the store\'s warehouse, with the ERPNext document\'s name.',
        'to_corrections' => 'Send it to the ERPNext corrections',
        'correction_hint' => 'This books nothing: the correction then waits under ERPNext corrections.',
        'dismiss_legend' => 'Close it without booking',
        'dismiss' => 'Nothing to book: close it',
        'none_open_text' => 'New lines arrive when a store sends an ERPNext document with a product the warehouse system cannot match.',
    ];

    /** Approvals › From the stores › ERPNext corrections. */
    public const CORRECTIONS = [
        'title' => '"%s": ERPNext set %s',
        'set_at' => 'Set in ERPNext on %s, for %s.',
        'document' => 'ERPNext document %s.',
        'then' => 'At that moment the warehouse held %s on the shelf, %s of them for paid orders not sent yet.',
        'target_for_sale' => 'So the shelf figure ERPNext meant is %s: %s for sale and %s for paid orders.',
        'target_shelf' => 'So the shelf figure ERPNext meant is %s.',
        'will_add' => 'Confirming adds %s now.',
        'will_take' => 'Confirming takes %s off now.',
        'no_difference' => 'No difference: confirming books nothing.',
        'not_count' => 'This is not a count: the item keeps the date it was last counted.',
        'carried' => 'That counts %s from earlier ERPNext corrections of this item confirmed since.',
        'newer' => 'A newer ERPNext correction of this item was confirmed already, so this one is out of date. Close it.',
        'answer_legend' => 'Your answer',
        'confirm' => 'Confirm: set the stock to this figure',
        'dismiss' => 'Do not use it: close it',
        'none_open_text' => 'A correction arrives when someone sets a stock figure in ERPNext.',
    ];

    /** Approvals › From the stores › Mode changes. */
    public const MODES = [
        'title' => '"%s": ERPNext set %s',
        'variant' => 'variant %s',
        'low_stock' => 'Low-stock level sent: %s.',
        'cw_now' => 'The warehouse system has %s for this store now.',
        'cw_none' => 'The warehouse system has no selling mode for this store yet.',
        'will_mode' => 'Accepting sets %s on %s. The item is not counted yet, so its stock rule stays as it is.',
        'will_policy' => 'The item is counted and protected, so accepting changes its stock rule to: %s.',
        'answer_legend' => 'Your answer',
        'accept' => 'Accept the change',
        'dismiss' => 'Keep what the warehouse system has',
        'superseded' => 'Replaced by a newer change from the store.',
        'none_open_text' => 'A change arrives when someone changes a selling mode in ERPNext and it differs from what the warehouse system has.',
    ];

    /** Approvals › From the stores › Oversold. */
    public const OVERSOLD = [
        'title' => '%s short of "%s"',
        'order' => 'Order %s, from %s.',
        'no_order' => 'No order: a stock change left it below zero.',
        'kind' => 'What happened: %s.',
        'available' => 'Left for sale after it: %s.',
        'notes' => 'Notes',
        'note_by' => '%s, %s: %s',
        'closed' => 'Handled on %2$s by %3$s (%1$s): %4$s',
        'handle_legend' => 'Mark it handled',
        'how' => 'How it was sorted out',
        'handle' => 'Mark handled',
        'note_legend' => 'Add a note',
        'add_note' => 'Add the note',
        'none_open_text' => 'An order arrives here when a store sells a protected item the warehouse does not have.',
    ];

    /** How an oversold order happened (oversell_event.kind; Reservations, Stock::flagShortfalls). */
    public const OVERSELL_KIND = [
        'commit_short' => 'paid when there was too little stock',
        'commit_after_expiry' => 'paid after its hold ran out',
        'outage_order' => 'ordered while the warehouse system could not be reached',
        'opening_short' => 'an order from before the store was connected',
        'stopped_sale' => 'sold while the item was stopped',
        'quarantined_sale' => 'sold from a variant that was set aside',
        'uncancel_short' => 'a cancelled order put back',
        'count_short' => 'a count left too little',
        'reset_short' => 'a dispatch was undone',
        'adopt_short' => 'a variant was matched with orders on it',
        'movement_short' => 'a stock change left too little',
    ];

    /** How an oversold order was sorted out (Oversells::RESOLUTIONS). */
    public const OVERSELL_RESOLUTION = [
        'backordered' => 'Sent later, when the stock came',
        'refunded' => 'Refunded',
        'no_action' => 'Nothing needed',
    ];

    /** The notice after an answer on Approvals › From the stores (the item's code where one was picked). */
    public const QUEUE_NOTICE = [
        'booked' => 'Booked on %s. The line is done.',
        'booked_before' => 'This line was already booked on %s. Nothing more was booked.',
        'sent_to_corrections' => 'Placed on %s. The correction now waits under ERPNext corrections.',
        'closed' => 'Closed. Nothing was booked.',
        'confirmed' => 'Confirmed. The difference is booked.',
        'confirmed_nothing' => 'Confirmed. There was no difference, so nothing was booked.',
        'confirmed_before' => 'This correction was already confirmed. Nothing more was booked.',
        'accepted_mode' => 'Accepted. The store\'s selling mode is changed in the warehouse system.',
        'accepted_policy' => 'Accepted. The item\'s stock rule is changed.',
        'kept' => 'Closed. The warehouse system keeps what it had.',
        'handled' => 'Marked handled.',
        'noted' => 'The note is added.',
    ];

    /** A refused answer on Approvals › From the stores, by error code (the service's message otherwise: `other`). */
    public const QUEUE_ERROR = [
        'note_required' => 'Write a note of 3 to 500 characters: what you did, or why. Nothing was saved.',
        'queue_settled' => 'Someone already answered this one. Nothing more was saved.',
        'unknown_queue_row' => 'This row is not in the list any more. Nothing was saved.',
        'unknown_item' => 'There is no item with that code. Check it under Products. Nothing was booked.',
        'merged_item' => 'That item was joined into another one. Use the other item\'s code. Nothing was booked.',
        'bad_item' => 'Type the item\'s code, like CW-000123. Nothing was booked.',
        'bad_units' => 'Type how many of the item\'s units one unit of the line is: a whole number from 1. Nothing was booked.',
        'role_not_allowed' => 'Your jobs do not allow this answer. Nothing was saved.',
        'staff_required' => 'Only a person can answer this. Nothing was saved.',
        'idempotency_key_reused' => 'This line was already booked on another item. Nothing more was booked.',
        'out_of_range' => 'The figure is too big to book. Nothing was booked.',
        'protected_item' => 'The item was counted meanwhile, so its stock rule decides now. Look at it again. Nothing was saved.',
        'not_matched' => 'This change names no item. Close it. Nothing was saved.',
        'newer_correction' => 'A newer ERPNext correction of this item was confirmed already. Close this one. Nothing was booked.',
        'newer_count' => 'A later shelf count already set this stock figure. Close this correction. Nothing was booked.',
        'bad_field' => 'Choose how it was sorted out. Nothing was saved.',
        'other' => 'This did not work: %s. Nothing was saved.',
    ];

    /** The alerts to a person (bin/health_alert.php, Ops\AlertDispatch): the message's lines. */
    public const ALERT = [
        'subject' => 'Warehouse system: %s to look at (%s)',
        'subject_clear' => 'Warehouse system: back to normal (%2$s)',
        'intro' => 'The warehouse system (%s) found this:',
        'footer' => 'Sent by the warehouse system. The limits are on Settings, under Alerts.',
        'heartbeat_late' => '%s has not reported for %s (the limit is %s seconds).',
        'heartbeat_never' => '%s is switched on but has never reported.',
        'outbox_deep' => '%s has %s messages waiting to reach the warehouse system (the limit is %s).',
        'outbox_old' => '%s has a message that has waited %s (the limit is %s seconds).',
        'dead_letters' => '%s has %s messages that failed for good (the limit is %s).',
        'feed_lag' => '%s is %s behind the warehouse system\'s stock changes (the limit is %s seconds).',
        'mode_mismatch' => '%s runs as %s, but the warehouse system has it as %s. The lower one applies.',
        'answer_slow' => 'The warehouse system took %s milliseconds to answer (the middle of %s tries; the limit is %s).',
        'answer_failed' => 'The warehouse system could not reach its database for the answer-time check.',
        'invariants_failed' => 'The last nightly safety check found %s problems.',
        'invariants_stale' => 'No nightly safety check has finished for %s hours.',
        'invoice_submit_failed' => '%s timed supplier invoices could not be made final (%s): see Purchasing › Invoices › Timed.', // pack J (PJ11)
        'cleared' => 'Back to normal: %s',
        'seconds' => '%s seconds',
        'minutes' => '%s minutes',
        'hours' => '%s hours',
    ];
    // ---- end of pack F --------------------------------------------------------------------------------------------
    // ------------------------------------------------------------------------------------------------------------------
    // ---- Pack H (0038; docs/decisions.md PH1-PH20): hand-overs, their customers and balances, the duty register. ----

    /** Stock > Hand-overs (HandoversController, views handovers / handover / handover_fields). */
    public const HANDOVER = [
        'how' => 'How a hand-over works',
        'search' => 'Find a hand-over by its number, the customer, their reference or a note',
        'state' => 'Status',
        'any_state' => 'Any status',
        'customer' => 'Customer',
        'any_customer' => 'Any customer',
        'stopped' => 'Show stopped drafts too',
        'clear' => 'Clear the filter',
        'download' => 'Download this list as a spreadsheet file',
        'start' => 'Start a hand-over',
        'start_text' => 'Choose who gets the stock. You add the items on the next page.',
        'start_button' => 'Start the hand-over',
        'no_customers' => 'There are no customers yet',
        'no_customers_text' => 'A hand-over goes to a customer: a sister shop, one of our shop units or a trade customer. Add one first.',
        'no_customers_link' => 'Add a customer',
        'none' => 'No hand-overs yet',
        'none_text' => 'When stock goes to a sister shop, a shop unit or a trade customer, start a hand-over here.',
        'none_filter' => 'No hand-over fits your filter',
        'none_filter_text' => 'Your filter hides every hand-over.',
        'group_reversal' => 'Cancellation records',
        'group_count' => '%s records',
        'record' => 'Hand-over',
        'status' => 'Status',
        'products' => 'Items',
        'units' => 'Units',
        'value' => 'Amount',
        'before_vat' => 'before VAT, so far',
        'made_by' => 'Made by',
        'next' => 'What happens next',
        'next_draft' => 'Add the items, then make it final.',
        'next_waiting' => 'Waits for a reviewer\'s OK.',
        'next_check' => 'Final. A reviewer checks it.',
        'next_done' => 'Nothing: it is final.',
        'next_stopped' => 'Nothing: it was stopped.',
        'next_reversal' => 'Puts back %s.',
        'no_number' => 'Draft',
        'started' => 'Started %s',
        'dated' => 'Dated %s',
        'limit' => 'Only the newest %s are shown: use the filter to find older ones.',
        'f_customer' => 'Customer',
        'f_warehouse' => 'Stock leaves from',
        'f_vat_code' => 'VAT code',
        'f_vat_hint' => '(leave it to use the customer\'s, else the company\'s)',
        'f_vat_not_registered' => '(the company details say we are not VAT registered: no VAT is charged)',
        'f_vat_default' => 'The customer\'s, else the company\'s (%s)',
        'f_vat_default_none' => 'The customer\'s, else the company\'s (none set yet)',
        'f_their_ref' => 'Their reference',
        'f_date' => 'Date',
        'f_note' => 'Note',
        'optional' => '(optional)',
        'choose' => 'Choose…',
        'customer_link' => 'This customer',
        'balance_link' => 'What this customer owes',
        'pdf' => 'Invoice (PDF)',
        'company_link' => 'Open the company details',
        'not_creator' => 'Only the person who started this draft changes it. You can look.',
        'facts' => 'About this hand-over',
        'warehouse' => 'Stock leaves from',
        'vat_code' => 'VAT code',
        'vat_default' => 'The customer\'s, else the company\'s',
        'no_vat' => 'No VAT (not VAT registered)',
        'their_ref' => 'Their reference',
        'date' => 'Date',
        'note' => 'Note',
        'made' => 'Started',
        'asked' => 'Sent for an OK',
        'final' => 'Made final',
        'stopped_at' => 'Stopped',
        'by' => '%s by %s',
        'cancelled_by' => 'Cancelled by',
        'cancel_waiting' => 'A cancellation waiting for an OK',
        'lines' => 'Items',
        'add_q' => 'Scan a barcode, or type a CW number or words of the name',
        'add_qty' => 'Units',
        'add_price' => 'Price of a unit before VAT (£)',
        'add_price_hint' => 'Leave the price empty to use what this customer paid last.',
        'add_button' => 'Add',
        'choices' => 'Several items match "%s". Choose one:',
        'choose_this' => 'Add this one',
        'no_lines' => 'No items yet.',
        'line_item' => 'Item',
        'line_units' => 'Units',
        'line_price' => 'Price of a unit (£)',
        'line_amount' => 'Amount',
        'line_stock' => 'Stock here now',
        'line_remove' => 'Remove',
        'line_remembered' => 'Paid last: %s',
        'line_no_price' => 'No price yet',
        'line_stock_text' => '%s on the shelf, %s free',
        'save_lines' => 'Save the items',
        'total_units' => '%s units',
        'net_so_far' => '%s before VAT',
        'net' => 'Before VAT',
        'vat_at' => 'VAT %s (%s%%)',
        'gross' => 'Total the customer owes',
        'net_draft' => 'Before VAT, so far',
        'missing_prices' => '%s lines have no price yet: type them before you make it final.',
        'details' => 'Change the customer, the warehouse, the VAT code or the note',
        'save_details' => 'Save the details',
        'final_title' => 'Make it final',
        'final_does' => 'The stock leaves the warehouse now, the prices are kept for this customer, and the total is added to what the customer owes.',
        'final_waits' => 'A rule on the Approval Rules page may ask a reviewer\'s OK first.',
        'final_button' => 'Make it final: the stock goes out',
        'stop_title' => 'Stop this draft',
        'stop_text' => 'A stopped draft moves no stock and stays in the list.',
        'stop_why' => 'Why it is stopped',
        'stop_button' => 'Stop the draft',
        'cancel_title' => 'Cancel this hand-over',
        'cancel_text' => 'A new record puts the stock back and takes the amount off what the customer owes. This one stays, marked cancelled.',
        'cancel_why' => 'Why it is cancelled',
        'cancel_note' => 'Note',
        'cancel_button' => 'Cancel it: put the stock back',
        'copy_title' => 'Make it again',
        'copy_text' => 'A new draft with the same customer, items and prices. Use it for a stopped or cancelled one, or one made before the company details were confirmed.',
        'copy_button' => 'Copy into a new draft',
    ];

    /** How a hand-over works (the fold on the list). */
    public const HANDOVER_HOW = [
        'Start a hand-over and choose the customer: a sister shop, one of our shop units or a trade customer.',
        'Scan or type the items. Each line takes the price this customer paid last; change it if needed.',
        'Make it final. The stock leaves the warehouse, and the total is added to what the customer owes.',
        'Print the invoice once the company details are confirmed.',
        'A mistake is put right by cancelling it: a new record puts the stock back. Nothing is ever deleted.',
    ];

    /** The notices of a hand-over's page. */
    public const HANDOVER_NOTICE = [
        'created' => 'The hand-over is started. Add the items below.',
        'header' => 'The details are saved.',
        'lines' => 'The items are saved.',
        'added' => '%s units added on line %s.',
        'incremented' => '%s more units on line %s.',
        'posted' => 'Hand-over %s is final. The stock has left the warehouse.',
        'submitted' => 'The hand-over waits for a reviewer\'s OK. Nothing has moved yet.',
        'cancelled' => 'The draft is stopped. Nothing moved.',
        'reversed' => 'Cancellation %s is final: the stock is back and the amount is off what the customer owes.',
        'reversal_submitted' => 'The cancellation waits for a reviewer\'s OK. Nothing has moved yet.',
        'copied' => 'This is a new draft copied from the other hand-over. Check it, then make it final.',
    ];

    /** A hand-over's refusals, by code (the services' messages stay theirs). */
    public const HANDOVER_ERROR = [
        'customer_required' => 'Choose the customer. Nothing was saved.',
        'unknown_customer' => 'That customer does not exist. Nothing was saved.',
        'customer_off' => 'That customer is switched off. Switch it on again on the Customers page, or choose another. Nothing was saved.',
        'warehouse_required' => 'Choose the warehouse the stock leaves from. Nothing was saved.',
        'unknown_warehouse' => 'That warehouse does not exist. Nothing was saved.',
        'warehouse_inactive' => 'That warehouse is switched off. Choose another. Nothing was saved.',
        'not_own_stock' => 'That warehouse holds another account\'s stock. Only our own stock is handed over. Nothing was saved.',
        'bad_line' => 'Line %s needs an item and a number of units above zero. Nothing was saved.',
        'line_warehouse' => 'Line %s is in another warehouse. Nothing was saved.',
        'price_required' => 'Line %s has no price. Type the price of a unit, then make it final again. Nothing moved.',
        'vat_code_required' => 'No VAT code applies. Choose one on this hand-over, or give the customer or the company details one. Nothing moved.',
        'vat_code_off' => 'That VAT code is not in use. Choose another. Nothing was saved.',
        'below_zero' => 'Line %s: %s would go below zero (%s free, %s handed over). Nothing moved.',
        'qty_positive' => 'The units are a whole number above zero. Nothing was saved.',
        'too_many_lines' => 'A hand-over has at most %s lines. Start another one. Nothing was saved.',
        'not_handover' => 'This is not a hand-over.',
        'merged_item' => 'That item was joined into another one. Use that one. Nothing was saved.',
        'unknown_sku' => 'That item does not exist. Nothing was saved.',
        'company_not_confirmed' => 'The invoice is not printed: the company details are not confirmed yet. A reviewer confirms them on the Company page.',
        'company_not_confirmed_posted' => 'The invoice is not printed: the company details were not confirmed when this hand-over was made final. Copy it into a new draft once they are confirmed, and cancel this one.',
        'vat_code_missing' => 'The invoice is not printed: no VAT code applies. Give the company details a VAT code for our invoices.',
        'no_invoice' => 'A cancellation record has no invoice. Open the hand-over it cancels.',
        'bad_field' => 'Something on the form was not right. Check it and try again. Nothing was saved.',
        'version_conflict' => 'Someone changed this hand-over while you had it open. Look at it again. Nothing was saved.',
        'role_not_allowed' => 'Your job does not keep hand-overs. Nothing was saved.',
        'admin_cannot_post' => 'Admin does not keep hand-overs. Nothing was saved.',
        'no_lines' => 'Add at least one item before you make it final. Nothing moved.',
        'not_draft' => 'This hand-over is not a draft any more: it was made final or stopped already. Nothing was done twice.',
    ];

    /** The words printed on a hand-over's invoice PDF (HandoverPdf). Legal wording is not written here (PH9): handovers.invoice_terms holds it. */
    public const HANDOVER_PDF = [
        'title' => 'Invoice %s',
        'draft_number' => 'DRAFT',
        'not_an_invoice' => 'DRAFT — NOT AN INVOICE',
        'do_not_send' => 'COMPANY DETAILS NOT CONFIRMED — DO NOT SEND',
        'cancelled' => 'cancelled by %s',
        'cancelled_banner' => 'CANCELLED — see %s',
        'seller' => 'From',
        'buyer' => 'To',
        'details' => 'Invoice',
        'name' => 'Name',
        'trading_as' => 'Trading as',
        'address' => 'Address',
        'company_number' => 'Company number',
        'vat_number' => 'VAT number',
        'tbc' => '[to be confirmed]',
        'number' => 'Number',
        'date' => 'Date',
        'their_ref' => 'Your reference',
        'currency' => 'Currency',
        'pounds' => 'Pounds (GBP)',
        'note' => 'Note',
        'line' => '#',
        'code' => 'CW number',
        'item' => 'Item',
        'units' => 'Units',
        'price' => 'Price',
        'vat_code' => 'VAT',
        'amount' => 'Amount',
        'total_units' => 'Units',
        'net' => 'Total before VAT',
        'vat_at' => 'VAT %s at %s%%',
        'no_vat' => 'No VAT',
        'gross' => 'Total',
    ];

    /** Stock > Hand-overs > Customers (views handover_customers / handover_customer / handover_customer_fields). */
    public const CUSTOMER = [
        'search' => 'Find a customer by name, company number, VAT number or e-mail',
        'kind' => 'Kind',
        'any_kind' => 'Any kind',
        'show_off' => 'Show switched-off customers too',
        'clear' => 'Clear the filter',
        'add' => 'Add a customer',
        'add_button' => 'Add the customer',
        'none' => 'No customers yet',
        'none_text' => 'Add the sister shops, shop units and trade customers you hand stock to.',
        'none_filter' => 'No customer fits your filter',
        'none_filter_text' => 'Your filter hides every customer.',
        'name' => 'Name',
        'vat_code' => 'VAT code',
        'vat_company' => 'The company\'s',
        'handovers' => 'Hand-overs',
        'balance' => 'Owes us',
        'last' => 'Last hand-over',
        'state' => 'Status',
        'on' => 'In use',
        'off' => 'Switched off',
        'details' => 'Details',
        'not_given' => 'Not given',
        'changed' => 'Last changed',
        'by' => '%s by %s',
        'balance_link' => 'Balances',
        'its_handovers' => 'Its hand-overs',
        'change' => 'Change the details',
        'save' => 'Save the details',
        'switch_off' => 'Switch this customer off',
        'switch_off_text' => 'A switched-off customer gets no new hand-overs. Its hand-overs, prices and balance stay.',
        'switch_on' => 'Switch this customer on again',
        'f_kind' => 'Kind of customer',
        'f_name' => 'Name',
        'f_name_hint' => '(as printed on the invoice)',
        'f_address' => 'Address',
        'f_address_hint' => '(one line per line, at most 8 lines)',
        'f_company_number' => 'Company number',
        'f_vat_number' => 'VAT number',
        'f_vat_code' => 'VAT code of their invoices',
        'f_vat_code_hint' => '(only when it differs from the company\'s)',
        'f_vat_code_company' => 'The company\'s',
        'f_email' => 'E-mail',
        'f_phone' => 'Phone',
        'f_note' => 'Note',
        'optional' => '(optional)',
        'prices' => 'Prices this customer paid last',
        'prices_text' => 'A new hand-over line takes the price this customer paid last for the item. A later hand-over replaces it.',
        'no_prices' => 'No prices yet.',
        'price_item' => 'Item',
        'price' => 'Price of a unit before VAT',
        'price_day' => 'Day',
        'price_from' => 'From',
        'price_imported' => 'An old invoice %s',
        'recent' => 'Its latest hand-overs',
        'no_handovers' => 'No hand-overs yet.',
        'import' => 'Load the prices of old trade invoices',
        'import_text' => 'A spreadsheet file (CSV) with one row per old invoice line: customer, item (CW number, barcode or ERPNext item code), '
            . 'price of a unit before VAT, and if you have them, date and invoice. Rows that cannot be read for sure are skipped and listed.',
        'import_file' => 'The file',
        'import_hint' => 'A CSV file, at most %s MB.',
        'import_button' => 'Load the prices',
        'import_done' => 'The prices are loaded',
        'import_counts' => '%s rows read: %s prices loaded, %s kept because a newer price was there, %s rows skipped.',
        'import_skipped' => 'These rows were skipped. Correct them in the file and load it again, or type the price on the hand-over.',
        'import_row' => 'Row of the file',
        'import_row_no' => 'Row %s',
        'import_value' => 'What it said',
        'import_why' => 'Why it was skipped',
    ];

    /** The kinds of customer (counterparty.kind). */
    public const CUSTOMER_KIND = [
        'sister' => 'Sister shop',
        'unit' => 'Our shop unit',
        'trade' => 'Trade customer',
    ];

    /** A customer form's field problems, by the service's code (Counterparties::check). */
    public const CUSTOMER_FIELD_ERROR = [
        'kind' => 'Choose the kind of customer.',
        'name_short' => 'Type the name: at least 2 characters.',
        'name_long' => 'This is too long.',
        'not_text' => 'This is not valid text: type it again.',
        'control' => 'This contains a hidden control character: type it again.',
        'unprintable' => 'This has a letter or sign the invoice cannot print. Use Western European letters only, such as é, ü, ß, £ and €.',
        'lines_many' => 'An address has at most 8 lines.',
        'line_long' => 'A line of the address is longer than 100 characters: split it over two lines.',
        'company_number' => 'A company number has 8 characters: 8 digits (keep the leading zeros) or 2 letters and 6 digits.',
        'vat_number' => 'A UK VAT number is GB and 9 digits (or 12 for a branch); XI instead of GB in Northern Ireland.',
        'vat_code' => 'Choose a VAT code of the list.',
        'email' => 'This is not an e-mail address (for example orders@example.co.uk).',
        'phone' => 'A phone number is digits with spaces and + ( ) - . only, 7 to 15 digits.',
        'note_long' => 'The note is at most 500 characters.',
        'other' => 'Check this field.',
    ];

    /** The customer list's refusals, by code. */
    public const CUSTOMER_ERROR = [
        'customer_invalid' => 'Some details need correcting: see the fields below. Nothing was saved.',
        'customer_exists' => 'There is a customer with this name already. Nothing was saved.',
        'customer_changed' => 'Someone changed this customer while you had it open. What you typed is kept below: check it and save again. Nothing was saved.',
        'unknown_customer' => 'That customer does not exist.',
        'import_columns' => 'The file needs the columns customer, item and price (date and invoice may be there too). Nothing was loaded.',
        'bad_file' => 'The file could not be read as a spreadsheet file (CSV). Nothing was loaded.',
        'too_many_rows' => 'The file has more rows than the setting allows. Split it into smaller files. Nothing was loaded.',
        'role_not_allowed' => 'Your job does not keep the customer list. Nothing was saved.',
        'admin_cannot_post' => 'Admin does not keep the customer list. Nothing was saved.',
    ];

    /** The customer pages' notices. */
    public const CUSTOMER_NOTICE = [
        'added' => 'The customer is added. Hand-overs can name it now.',
        'saved' => 'The details are saved.',
        'unchanged' => 'Nothing changed, so nothing was saved.',
        'switched_off' => 'The customer is switched off. Its hand-overs and balance stay.',
        'switched_on' => 'The customer is in use again.',
    ];

    /** Why a row of a file of old prices was skipped (Counterparties::importPrices). */
    public const PRICE_SKIP = [
        'unknown_customer' => 'No customer has this exact name: add it first, or correct the name.',
        'unknown_item' => 'No item has this CW number, barcode or ERPNext item code.',
        'item_doubtful' => 'It could be more than one item, or a pack of several units: not loaded, to be safe.',
        'bad_price' => 'This is not a price in pounds (for example 2.45).',
        'bad_date' => 'This is not a date (2026-10-14 or 14/10/2026), or it is in the future.',
        'bad_reference' => 'The invoice number is longer than 100 characters.',
    ];

    /** Stock > Hand-overs > Balances (view handover_balances). */
    public const BALANCE = [
        'accountant' => 'The accountant keeps the real accounts. This page is the warehouse\'s own record of hand-overs and the payments you tell it about.',
        'none' => 'No customers yet',
        'none_text' => 'A customer appears here once it is added.',
        'none_link' => 'Add a customer',
        'tile_balance' => 'Owes us now',
        'balance_sub' => 'hand-overs less payments received',
        'credit' => 'a credit: we owe the customer',
        'tile_month' => 'Handed over this month',
        'handovers_sub' => '%s hand-overs in all',
        'last_paid' => 'Last payment: %s on %s (reference %s).',
        'pay' => 'Record a payment received',
        'pay_text' => 'Type the amount that arrived, the day and the bank reference. A payment recorded by mistake is reversed, never deleted.',
        'amount' => 'Amount (£)',
        'paid_on' => 'Day it arrived',
        'reference' => 'Bank reference',
        'note' => 'Note',
        'pay_button' => 'Record the payment',
        'history' => 'Every entry',
        'no_history' => 'Nothing yet.',
        'what' => 'What',
        'when' => 'When',
        'amount_col' => 'Amount',
        'ref_col' => 'Reference',
        'who' => 'Who',
        'reversed_mark' => 'Reversed',
        'reverse' => 'Reverse this payment',
        'reverse_why' => 'Why it is reversed',
        'reverse_button' => 'Reverse the payment',
    ];

    /** An entry of what a customer owes (counterparty_entry.kind). */
    public const BALANCE_KIND = [
        'handover' => 'Hand-over %s',
        'handover_reversal' => 'Cancellation %s',
        'payment' => 'Payment received',
        'payment_reversal' => 'Payment reversed',
    ];

    /** The balances page's notices (%s: the balance now). */
    public const BALANCE_NOTICE = [
        'paid' => 'The payment is recorded. The customer now owes %s.',
        'payment_reversed' => 'The payment is reversed. The customer now owes %s.',
    ];

    /** The balances page's refusals, by code. */
    public const BALANCE_ERROR = [
        'more_than_owed' => 'The payment is more than the %s the customer owes. Check the amount. Nothing was saved.',
        'nothing_owed' => 'This customer owes nothing. Nothing was saved.',
        'bad_amount' => 'Type the amount in pounds, for example 1250.00. Nothing was saved.',
        'bad_date' => 'Type the day the money arrived, today or before. Nothing was saved.',
        'bad_reference' => 'Type the bank reference. Nothing was saved.',
        'bad_reason' => 'Say in a few words why the payment is reversed. Nothing was saved.',
        'already_reversed' => 'This payment is reversed already. Nothing was saved.',
        'not_a_payment' => 'Only a payment is reversed here. A hand-over is cancelled on its own page.',
        'unknown_entry' => 'That payment does not exist.',
        'unknown_customer' => 'That customer does not exist.',
        'role_not_allowed' => 'Your job does not record payments. Nothing was saved.',
        'admin_cannot_post' => 'Admin does not record payments. Nothing was saved.',
    ];

    /** Purchasing > Goods In > Duty register (DutyController, view duty_register). */
    public const DUTY = [
        'lines_stamp' => 'It lists the lines of the deliveries booked in that needed the duty stamp check.',
        'lines_all' => 'It lists every line of the deliveries booked in.',
        'search' => 'Find by item, CW number, delivery number, supplier invoice or delivery note',
        'from' => 'Arrived on or after',
        'to' => 'Arrived on or before',
        'supplier' => 'Supplier',
        'any_supplier' => 'Any supplier',
        'stamp' => 'Stamp',
        'any_stamp' => 'Any',
        'state' => 'Delivery',
        'any_state' => 'Any',
        'state_posted' => 'Booked in',
        'state_reversed' => 'Cancelled',
        'clear' => 'Clear the filter',
        'download' => 'Download as a spreadsheet file (at most %s lines)',
        'register' => 'Delivery lines',
        'count' => '%s lines',
        'none' => 'Nothing in the register yet',
        'none_text' => 'The register fills itself from the deliveries booked in.',
        'none_link' => 'Go to Goods In',
        'none_filter' => 'No line fits your filter',
        'none_filter_text' => 'Your filter hides every line.',
        'arrived' => 'Arrived',
        'item' => 'Item',
        'units' => 'Units',
        'check' => 'Stamp check',
        'unstamped' => 'Unstamped',
        'declaration' => 'Supplier\'s declaration',
        'expected' => 'Expected duty',
        'invoice' => 'Invoice %s',
        'delivery_note' => 'delivery note %s',
        'ml' => '%s ml',
        'went' => '%s in, %s held back, %s refused',
        'not_checked' => 'Not checked',
        'on_pack_yes' => 'On the outer pack and sealing it',
        'on_pack_no' => 'Not on the outer pack',
        'checked_by' => 'Checked by %s',
        'none_unstamped' => 'None',
        'incidents' => '%s incidents',
        'no_declaration' => 'None',
        'evidence_file' => 'A file of it is kept with the delivery.',
        'prev' => 'Newer',
        'next' => 'Older',
        'page' => 'Page %s of %s',
        'alarm' => '%s unstamped units are still held. From %s holding them is an offence: they must be sold, sent back, exported or destroyed.',
        'must_empty' => '%s unstamped units are held. The place must be empty by %s.',
        'held_title' => 'Unstamped stock and where it is',
        'held_text' => 'What the unstamped places hold now. The settings name these places.',
        'no_unstamped_place' => 'No place is named for unstamped stock in the settings.',
        'held_none' => 'Nothing unstamped is held',
        'where' => 'Where',
        'on_hand' => 'Units',
        'accepted_title' => 'Taken in unstamped on a supplier\'s declaration',
        'accepted_text' => 'These units arrived unstamped with the supplier\'s word that they were made or brought in before 1 Oct 2026. They '
            . 'went to the shelf with the rest. The system cannot tell how many are sold since: count them.',
        'accepted_none' => 'None.',
        'accepted_units' => 'Units taken in',
        'last_arrived' => 'Last arrived',
        'on_hand_now' => 'Units of the item there now',
        'list_more' => 'Only the first %s items are listed here. The totals count them all.',
        'held_total' => '%s units held unstamped in all.',
        'accepted_total' => '%s units taken in this way in all.',
    ];

    /** The stamp state of a line of the duty register (DutyRegister::STAMP_STATES). */
    public const DUTY_STAMP = [
        'stamped' => 'Stamped',
        'unstamped' => 'Unstamped',
        'declaration' => 'Unstamped, declared made before 1 Oct 2026',
        'not_checked' => 'Not checked',
        'not_needed' => 'No stamp needed',
    ];

    // ---- end of pack H ----

    // ==================================================================================================================
    // ---- Pack J (0041; docs/decisions.md PJ1-PJ16): goods on a final supplier invoice that have not arrived yet: how an invoice's goods
    // come in, when it becomes final, Goods In › Arriving, the claim of what did not come, a supplier's choices.

    /** How an invoice's goods not received yet come in when it is made final (supplier_invoice.receive_mode). */
    public const RECEIVE_MODE = [
        'count' => 'Count on arrival',
        'received' => 'Book as received (no count)',
    ];

    /** The duty stamp "book as received" asks on the invoice (sinv_line.stamp_answer). */
    public const STAMP_ANSWER = [
        'digital' => 'Digital stamp on the pack',
        'transitional' => 'Transitional stamp on the pack',
    ];

    /** A line of a final invoice on its way (invoice_arrival.state). */
    public const ARRIVAL_STATE = [
        'open' => 'On its way',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    /** The words of the arrival screens: the invoice's boxes, Goods In › Arriving, Purchasing › Invoices › Timed, a receipt's claim, a supplier's box. */
    public const ARRIVALS = [
        // The invoice: how its goods come in
        'how_title' => 'How its goods come in',
        'how_count' => 'Count on arrival: when the invoice is final, its goods are on their way (Goods In › Arriving). Goods In receives them against the invoice and counts them.',
        'how_received' => 'Book as received: when the invoice is final, its goods are booked into stock at once, without a count. Say the duty stamp of each duty-liable line.',
        'sells_before' => 'This supplier\'s goods are sold on the websites before they arrive: customers can order tonight, the orders go out when the goods land.',
        'sells_after' => 'This supplier\'s goods are sold on the websites once they are received.',
        'stamp' => 'Duty stamp',
        'stamp_choose' => 'Choose the stamp',
        'stamp_not_needed' => 'No stamp needed',
        'save_how' => 'Save how its goods come in',
        'lines_on_delivery' => 'Every goods line of this invoice came on a delivery already: nothing comes in with it.',
        // The invoice: when it becomes final
        'when_title' => 'Make it final later',
        'when_text' => 'Set the date and time this invoice becomes final by itself (UK time). Until then you can change it, set another time or stop it.',
        'at' => 'Becomes final at',
        'set_time' => 'Set the time',
        'take_off' => 'Take the time off',
        'clock_title' => 'Which %s?',
        'clock_bst' => '%s BST: the first one, before the clocks go back',
        'clock_gmt' => '%s GMT: the second one, an hour later, after the clocks go back',
        'timed' => 'It becomes final by itself on %s (set by %s).',
        'failed' => 'It could not be made final at its time: %s Put it right, then set the time again.',
        // The invoice once final
        'on_way_title' => 'Its goods',
        'on_way_none' => 'Nothing of it is on its way.',
        'line' => 'Line',
        'item' => 'Item',
        'units' => 'Units',
        'state' => 'Where it is',
        'sold_before' => 'Sold before it arrives',
        'yes' => 'Yes',
        'no' => 'No',
        'receipt' => 'Receipt',
        'booked_received' => 'Booked as received; its receipt:',
        // Goods In › Arriving
        'none' => 'Nothing is on its way.',
        'none_text' => 'Goods of final supplier invoices counted on arrival show here until Goods In receives them.',
        'invoice' => 'Invoice',
        'supplier' => 'Supplier',
        'since' => 'Final since',
        'units_on_way' => 'Units on their way',
        'units_sold' => 'Of them sold before they arrive',
        'late' => 'Late',
        'hours' => '%s hours',
        'receive' => 'Receive',
        'open_receipt' => 'Open its receipt',
        'more' => 'Only the first %s are shown.',
        // Purchasing › Invoices › Timed
        'timed_none' => 'No invoice waits for its time.',
        'timed_none_text' => 'An invoice whose time is set shows here until it becomes final.',
        'timed_at' => 'Becomes final at',
        'timed_by' => 'Set by',
        'timed_state' => 'State',
        'waiting' => 'Waiting for its time',
        'could_not' => 'Could not be made final',
        // A receipt made from an invoice
        'against' => 'Received against the supplier invoice:',
        'missing_title' => 'What did not come',
        'missing_text' => 'The invoice billed these, the delivery did not bring them. Claim them from the supplier: a debit note, no stock moves.',
        'missing_line' => '%s x %s %s (invoice line %s): %s',
        'claim' => 'Claim what did not come',
        'claim_open' => 'Open the claim',
        'nothing_missing' => 'Everything the invoice billed came.',
        // A supplier
        'supplier_title' => 'Goods on their way',
        'supplier_text' => 'What happens to the goods of this supplier\'s invoices that have not arrived yet.',
        'sell_before' => 'Sell before it arrives',
        'sell_before_hint' => 'Yes: the goods of a final invoice are sold on the websites before they land.',
        'default_mode' => 'How its invoices come in',
        'own_none' => 'This supplier has no choice of its own yet: the settings decide.',
        'save' => 'Save',
        'saved' => 'Saved.',
        'not_saved' => 'Not saved: %s',
    ];

    /** A refused change around goods on their way. */
    public const ARRIVAL_ERROR = [
        'bad_submit_at' => 'Choose a date and time still to come, or make it final now.',
        'ambiguous_submit_at' => 'That time comes twice that night: the clocks go back an hour. Choose which one below.',
        'bad_mode' => 'Choose how its goods come in.',
        'bad_stamp' => 'Choose the stamp from the list.',
        'stamp_answer_required' => 'Say which duty stamp is on the pack: booked as received, nobody checks it at the bench.',
        'invoice_copy_required' => 'Attach the supplier\'s invoice (a PDF or a photo): booked as received, its receipt keeps the copy.',
        'receive_first' => 'Make it final with its own button: booked as received, its receipt is booked first.',
        'receipt_mismatch' => 'Its lines changed after its receipt was booked: put the lines back, or cancel that receipt.',
        'nothing_arriving' => 'Nothing of this invoice is on its way.',
        'arrived' => 'Its goods were received: cancel that receipt first, or book the supplier\'s credit note.',
        'not_from_invoice' => 'This receipt was not made from a supplier invoice.',
        'nothing_missing' => 'Everything the invoice billed came: there is nothing to claim.',
        'incoming_on_item' => 'Units of this product are on their way on a supplier invoice: receive them first.',
        'not_book_as_received' => 'Only a draft booked as received books its receipt this way.',
        'nothing_to_receive' => 'Every goods line of this invoice came on a delivery already.',
        'receipt_not_posted' => 'Its receipt is not final: open it.',
    ];

    // ---- end of pack J ----

    public const TONES = ['needs', 'done', 'waiting', 'blocked', 'info', 'off', 'review'];

    /** group => code => tone; a code not listed is `info`. */
    public const TONE = [
        'ARRIVAL_STATE' => ['open' => 'waiting', 'received' => 'done', 'cancelled' => 'off'], // pack J
        'LISTING_STATUS' => ['unmapped' => 'needs', 'suggested' => 'needs', 'mapped' => 'done', 'ignored' => 'off', 'quarantined' => 'blocked'],
        'DECISION_STATE' => ['pending_second' => 'review', 'applied' => 'done', 'withdrawn' => 'off'],
        'SAMPLE_STATE' => ['open' => 'needs', 'confirmed' => 'done', 'needed_second' => 'done', 'waiting_second' => 'review', 'rejected' => 'blocked',
            'superseded' => 'off', 'decided_otherwise' => 'blocked', 'confirmed_by_other' => 'blocked', 'confirmed_not_by_lead' => 'blocked', 'changed_since' => 'blocked'],
        'SAMPLE_RESULT' => ['waiting' => 'needs', 'passed' => 'done', 'failed' => 'blocked', 'unusable' => 'blocked'],
        // Design v4 (owner's approval, 8 Oct 2026): a draft is grey, waiting for someone's OK purple, sent blue, part delivered orange.
        'PO_STATE' => ['draft' => 'waiting', 'awaiting_approval' => 'review', 'approved' => 'needs', 'sent' => 'info', 'part_received' => 'needs',
            'received' => 'done', 'closed' => 'done', 'cancelled' => 'off'],
        'SUPPLIER_STATUS' => ['draft' => 'waiting', 'pending_approval' => 'review', 'active' => 'done', 'inactive' => 'off'],
        'REVIEW_STATE' => ['not_required' => 'off', 'pending' => 'review', 'approved' => 'done', 'rejected' => 'blocked'],
        'TASK_STATE' => ['open' => 'review', 'approved' => 'done', 'rejected' => 'blocked', 'withdrawn' => 'off'],
        'DOC_STATUS' => ['draft' => 'waiting', 'awaiting_approval' => 'review', 'posted' => 'done', 'reversed' => 'off', 'cancelled' => 'off'],
        'POLICY' => ['legacy' => 'off', 'strict' => 'done', 'backorder' => 'info', 'stopped' => 'blocked'],
        'FIELD_STATE' => ['same' => 'done', 'differs' => 'blocked', 'conflict' => 'blocked', 'alike' => 'info', 'spelt' => 'info', 'unknown' => 'off', 'missing' => 'off'],
        'CARD_STATE' => ['none' => 'off', 'unconfirmed' => 'needs', 'changed' => 'needs', 'confirmed' => 'done', 'warned' => 'needs', 'blocked' => 'blocked'],
        'RECEIPT_STATE' => ['draft' => 'needs', 'awaiting_approval' => 'review', 'posted' => 'done', 'reversed' => 'off', 'cancelled' => 'off'],
        'BENCH_STATE' => ['todo' => 'needs', 'part' => 'needs', 'done' => 'done', 'refused' => 'blocked'],
        'INCIDENT_STATE' => ['open' => 'needs', 'resolved' => 'done', 'dismissed' => 'off'],
        'MODE' => ['off' => 'off', 'shadow' => 'waiting', 'live' => 'done'],
        // The lists of matches as v4 colours them: strong green, likely orange, new product blue, not sure purple, clues disagree red.
        'BAND' => ['Key' => 'done', 'Check' => 'needs', 'New item' => 'info', 'Can\'t tell' => 'review', 'Conflict' => 'blocked', 'Manual' => 'off'],
        'STORE_STATE' => ['waiting' => 'review', 'quarantined' => 'blocked', 'ignored' => 'off', 'linked' => 'done', 'suggested' => 'needs', 'not_matched' => 'waiting'],
        'NEW_VARIANT_STATE' => ['new' => 'info', 'suggested' => 'needs', 'not_checked' => 'waiting', 'linked' => 'done', 'quarantined' => 'blocked',
            'waiting' => 'review', 'ignored' => 'off'],
        'BULK_RESULT' => ['done' => 'done', 'pending_second' => 'review', 'skipped' => 'needs'],
        // Stock › Reservations: in a checkout blue, to ship orange, dealt with green, freed and run out grey.
        'RESV_GROUP' => ['held' => 'info', 'to_ship' => 'needs', 'closed' => 'done', 'released' => 'off', 'expired' => 'waiting'],
        'RESV_UNIT' => ['held' => 'info', 'allocated' => 'needs', 'shipped' => 'done', 'cancelled' => 'off', 'released' => 'off', 'returned' => 'waiting'],
        // By Item's cells, as Store Products colours the same states.
        'PRODUCT_STORE' => ['matched' => 'done', 'waiting' => 'review', 'suggested' => 'needs', 'rejected' => 'blocked', 'none' => 'waiting'],
        // ---- Pack D (docs/decisions.md PD6, PD7): a waiting order late or on time; back on sale or not ----
        'DISPATCH_CHIP' => ['late' => 'blocked', 'on_time' => 'waiting'],
        'SALE_RESTOCK' => ['yes' => 'done', 'no' => 'blocked', 'none' => 'off'],
        // ---- end of pack D ----
        // Pack B: an item made by hand's check; a pack item's flags.
        'BY_HAND_STATE' => ['not_asked' => 'off', 'waiting' => 'review', 'checked' => 'done'],
        'PACK_FLAG' => ['single_sold' => 'done', 'sold_both_ways' => 'needs', 'pack_of_packs' => 'blocked', 'count_differs' => 'needs', 'suggest' => 'info'],
        'PACK_LINK_FLAG' => ['pack_item_exists' => 'needs', 'item_is_pack' => 'blocked'],
        // --- Pack C (IM7): supplier invoices, credit notes, returns to suppliers.
        'MATCH_STATE' => ['matched' => 'done', 'within_tolerance' => 'info', 'needs_review' => 'needs', 'accepted' => 'review'],
        'MATCH_LINE' => ['matched' => 'done', 'within' => 'info', 'off' => 'needs'],
        'CREDIT_STATE' => ['open' => 'needs', 'part' => 'waiting', 'credited' => 'done'],
        // Pack F (PF5): waiting orange, done green, closed grey.
        'QUEUE_STATE' => ['open' => 'needs', 'resolved' => 'done', 'dismissed' => 'off'],
        // Pack H (0038): a line of the duty register.
        'DUTY_STAMP' => ['stamped' => 'done', 'unstamped' => 'blocked', 'declaration' => 'needs', 'not_checked' => 'review', 'not_needed' => 'off'],
    ];

    // ==================================================================================================================

    /** The word for $code in $group (a constant above); a code without one is made readable ("price_outlier" -> "Price outlier"). */
    public static function of(string $group, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $words = self::group($group);
        if (isset($words[$code]) && is_string($words[$code])) {
            return $words[$code];
        }
        return ucfirst(str_replace('_', ' ', $code));
    }

    /**
     * A word with its `%s` filled in ("2 records, newest first."); a whole number gets thousands separators. A text with no
     * `%s` is returned as it is.
     */
    public static function say(string $group, string $code, string|int|float ...$args): string
    {
        $text = self::of($group, $code);
        if ($args === []) {
            return $text;
        }
        return vsprintf($text, array_map(static fn (string|int|float $a): string => is_int($a) ? number_format($a) : (string) $a, $args));
    }

    /** Whether $group has its own word for $code. */
    public static function has(string $group, string $code): bool
    {
        return isset(self::group($group)[$code]);
    }

    /** @return array<string, mixed> */
    public static function group(string $group): array
    {
        if (!in_array($group, self::GROUPS, true)) {
            throw new \InvalidArgumentException("no word group {$group}");
        }
        /** @var array<string, mixed> */
        return constant(self::class . '::' . $group);
    }

    /** The chip tone of a status ('info' when the group or code has none). */
    public static function tone(string $group, ?string $code): string
    {
        return self::TONE[$group][$code ?? ''] ?? 'info';
    }

    /**
     * The jobs of a person: "Buyer · Matcher", with "(off)" after a job that Admin switches off
     * ("Admin · Matching lead (off) · Reviewer (off)"); "no job yet" for none.
     *
     * @param list<string> $roles
     */
    public static function roles(array $roles): string
    {
        if ($roles === []) {
            return self::UI['no_jobs'];
        }
        $off = Permissions::switchedOff($roles);
        $out = [];
        foreach ($roles as $r) {
            $out[] = self::of('ROLE', $r) . (in_array($r, $off, true) ? ' (' . self::UI['off'] . ')' : '');
        }
        return implode(' · ', $out);
    }

    /** How many warehouse products 1 sale of a website product uses (plan §1.2): "1 sale = 1 product", "1 sale = 10 products". */
    public static function saleUses(int|string|null $units): string
    {
        $n = max(1, (int) ($units ?? 1));
        return '1 sale = ' . number_format($n) . ($n === 1 ? ' product' : ' products');
    }

    /** A name the way a sentence quotes it ('"Elux Legend Blue Razz"'), or $otherwise when there is none. */
    public static function quoted(?string $name, string $otherwise): string
    {
        $name = $name === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $name));
        return $name === '' ? $otherwise : '"' . (mb_strlen($name) > 78 ? mb_substr($name, 0, 77) . '…' : $name) . '"';
    }

    /** "A, B and C". @param list<string> $words */
    public static function andList(array $words, string $and = 'and'): string
    {
        $words = array_values($words);
        if (count($words) <= 1) {
            return $words[0] ?? '';
        }
        $last = array_pop($words);
        return implode(', ', $words) . " {$and} " . $last;
    }

    /** Who holds $perm, as jobs ("Reviewers", "Buyers and Purchasing managers"; "everyone with a job" for all). */
    public static function whoCan(string $perm): string
    {
        // The map in force (pack E: it can be changed on the screen), the code's order first so an unchanged map reads as it always did.
        $holders = Permissions::inCodeOrder($perm, Permissions::holders($perm));
        if (count($holders) === count(Permissions::ROLES)) {
            return 'everyone with a job';
        }
        return self::andList(array_map(static fn (string $r): string => self::of('ROLE_PLURAL', $r), $holders));
    }

    /**
     * The yellow strip of an account that holds Admin with working jobs (correction a, 7 Oct): name the jobs that are off and
     * the one fix, never a second account. Null when nothing is switched off.
     *
     * @param list<string> $roles
     */
    public static function switchedOffNote(array $roles): ?string
    {
        $off = Permissions::switchedOff($roles);
        if ($off === []) {
            return null;
        }
        usort($off, static fn (string $a, string $b): int => array_search($a, self::JOB_ORDER, true) <=> array_search($b, self::JOB_ORDER, true));
        $jobs = self::andList(array_map(static fn (string $r): string => self::of('ROLE', $r), $off));
        return 'Your ' . $jobs . (count($off) === 1 ? ' job is' : ' jobs are') . ' switched off because this account also has Admin. '
            . 'Ask ' . self::ASK . ' to take Admin off this account.';
    }

    /** "receiving deliveries, supplier invoices": the screens not built yet that the roles will use ('' for none). @param list<string> $roles */
    public static function comingLater(array $roles): string
    {
        return implode(', ', array_map(static fn (string $k): string => self::of('COMING_LATER', $k), Permissions::comingLater($roles)));
    }

    /** The intro of a page: what it is, then what to do (or, for $lookOnly, "You can look; <who> change this."). */
    public static function intro(string $page, ?string $lookOnly = null): string
    {
        $intro = self::PAGE_INTRO[$page] ?? null;
        if ($intro === null) {
            return '';
        }
        $second = $lookOnly !== null ? sprintf(self::UI['look_only'], $lookOnly) : $intro[1];
        return trim($intro[0] . ' ' . $second);
    }

    /** A page's title: PAGE_TITLE, else its menu name, else ''. */
    public static function title(string $page): string
    {
        return self::PAGE_TITLE[$page] ?? self::MENU[$page] ?? '';
    }

    /** The message of an error code (ERROR, with its numbers filled in), or $fallback (the service's own message). */
    public static function error(string $code, string $fallback = '', int|string ...$args): string
    {
        $text = self::ERROR[$code] ?? null;
        if ($text === null) {
            return $fallback;
        }
        if ($args === [] && str_contains($text, '%')) {
            return $fallback; // a text with numbers is filled in where it is made (Kernel), not again here
        }
        return $args === [] ? $text : vsprintf($text, $args);
    }

    /** "This needs a note", naming the line when the service names one (detail.line of note_required). @param array<string, mixed> $detail */
    public static function noteRequired(array $detail): string
    {
        return is_int($detail['line'] ?? null) ? sprintf(self::ERROR['note_required_line'], $detail['line']) : self::ERROR['note_required'];
    }

    /** The heading of an error page ($post: the refusal of a button press, not of a page). */
    public static function errorTitle(int $status, bool $post = false): string
    {
        if ($post && isset(self::ERROR_TITLE[$status . '_post'])) {
            return self::ERROR_TITLE[$status . '_post'];
        }
        return self::ERROR_TITLE[(string) $status] ?? ($status >= 500 ? self::ERROR_TITLE['500'] : self::ERROR_TITLE['400']);
    }

    /**
     * The review rule of a kind of record in a sentence (Approval Rules, Settings): who checks what afterwards. A stock count's rule
     * means the second person who answers a count difference (docs/decisions.md CT8; review M-4 of 14 Oct 2026), not a reviewer's check.
     */
    public static function reviewRule(string $code, string $rule, int $limit, int $days): string
    {
        $cnt = $code === 'CNT';
        return match ($rule) {
            'all' => self::say('SETTINGS_PAGE', $cnt ? 'rule_cnt_all' : 'rule_all', $days),
            'over_limit' => $cnt ? self::say('SETTINGS_PAGE', 'rule_cnt_over', $limit) : self::say('SETTINGS_PAGE', 'rule_over', $limit, $days),
            default => self::SETTINGS_PAGE[$cnt ? 'rule_cnt_none' : 'rule_none'],
        };
    }

    /** What a reviewer's Not OK does to a kind of record, in a sentence. */
    public static function rejectRule(string $code, string $action): string
    {
        if ($action !== 'record') {
            return self::SETTINGS_PAGE['rule_reverse'];
        }
        return self::SETTINGS_PAGE[match ($code) {
            'PO' => 'rule_record_po',
            'CNT' => 'rule_record_cnt',
            default => 'rule_record',
        }];
    }

    /** One record of a kind ("Purchase order"), or many ("Purchase orders"); $fallback (document_type.name) for a kind without a word. */
    public static function docType(string $code, bool $many = false, ?string $fallback = null): string
    {
        return ($many ? self::DOC_TYPES : self::DOC_TYPE)[$code] ?? ($fallback ?? $code);
    }

    /**
     * A record as the screens name it (plan F099, F408): "PO-000001 – Elux Wholesale", "Order for Elux Wholesale (no number yet)",
     * "Cancellation of PO-000003 – Elux Wholesale", "Stock correction ADJ-000001", "Stock correction (no number yet)".
     * Document::label() stays: it names downloaded files and appears in service messages.
     *
     * @param string|null $cancels the number of the record this one cancels (a cancellation record), else null
     */
    public static function docTitle(string $type, ?string $number, ?string $supplier = null, ?string $cancels = null, ?string $typeName = null): string
    {
        $supplier = $supplier === null || trim($supplier) === '' ? null : $supplier;
        $who = $supplier === null ? '' : ' – ' . $supplier;
        if ($cancels !== null) {
            return sprintf(self::CHECKS['cancellation'], $cancels) . $who;
        }
        if ($type === 'PO' && $supplier !== null) {
            return $number === null ? sprintf(self::CHECKS['order_no_number'], $supplier) : $number . $who;
        }
        $name = self::docType($type, false, $typeName);
        return $number === null ? sprintf(self::CHECKS['record_no_number'], $name) : $name . ' ' . $number;
    }

    /** A setting's plain name (SETTING), else its key made readable ("reorder.new_thing" -> "New thing"). */
    public static function settingName(string $key): string
    {
        return self::SETTING[$key] ?? ucfirst(str_replace(['_', '.'], ' ', (string) substr($key, (int) strrpos('.' . $key, '.'))));
    }

    /** What a setting does (SETTING_HELP), else the migration's own description. */
    public static function settingHelp(string $key, string $fallback): string
    {
        return self::SETTING_HELP[$key] ?? $fallback;
    }

    /** The topic a setting is listed under (by its key's prefix; "Other" for none). */
    public static function settingTopic(string $key): string
    {
        $prefix = strstr($key, '.', true);
        return self::SETTING_TOPIC[$prefix === false ? $key : $prefix] ?? 'Other';
    }

    /**
     * Why this person cannot decide a check: the refusal code's words (REFUSAL), else the service's message (the API's,
     * shown as it is). @param array{code?: string, message?: string}|null $refusal
     */
    public static function refusal(?array $refusal): ?string
    {
        if ($refusal === null) {
            return null;
        }
        return self::REFUSAL[$refusal['code'] ?? ''] ?? ($refusal['message'] ?? null);
    }
}
