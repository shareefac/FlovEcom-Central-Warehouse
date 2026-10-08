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
        'PAGE_TITLE', 'BAND', 'BAND_TITLE', 'BAND_HELP', 'LANE', 'AI', 'FLAG', 'VETO', 'BAND_REASON', 'FIELD_STATE', 'NEEDS_SECOND', 'ACTION',
        'DECISION_STATE', 'LISTING_STATUS', 'POLICY', 'STOCK', 'SAMPLE_STATE', 'SAMPLE_RESULT', 'PO_STATE', 'SEND_VIA', 'REASON', 'SUPPLIER_STATUS',
        'CHECK_REASON', 'CHECK_KIND', 'REVIEW_STATE', 'TASK_STATE', 'DOC_STATUS', 'REORDER_FLAG', 'SETTING', 'ERROR_TITLE', 'ERROR', 'UI', 'HOME',
        'SIGN_IN', 'REFUSAL', 'DOC_TYPE', 'DOC_TYPES', 'CHECK_STATE', 'CHECKS', 'RECORDS', 'RECORD', 'RECORD_NOTICE', 'COMPANY', 'COMPANY_NOTICE',
        'SETTINGS_PAGE', 'REASON_USE', 'SETTING_TOPIC', 'SETTING_HELP', 'STAFF', 'STAFF_NOTICE', 'SPOT', 'FIELD', 'FORM_VALUE', 'NIC_TYPE', 'AI_FIELD',
        'AI_FIELD_STATE', 'ACTION_DONE', 'MOVEMENT', 'ORIGIN', 'QUEUE', 'LISTING', 'PENDING', 'MATCH_NOTICE', 'MATCH_ERROR', 'SAMPLE', 'SAMPLE_FIT', 'DUPS',
        'DUP_NOTICE', 'DUP_ERROR', 'SEARCH', 'ITEM', 'PO', 'PO_FILTER', 'PO_SOURCE', 'ORDERS', 'ORDER', 'PO_NOTICE', 'BUY_ERROR', 'PO_WARN', 'REORDER', 'WHY',
        'REORDER_ITEM', 'BRANDS', 'ANOMALIES', 'REORDER_NOTICE', 'SALES', 'SUPPLIERS', 'SUPPLIER', 'SUPPLIER_FIELD', 'SUPPLIER_FORM', 'SUPPLIER_NOTICE', 'SUPPLIER_ITEMS', 'SI_NOTICE',
        'CARD_FIELD', 'CARDS', 'CARD_STATE', 'CARD', 'CARD_ERROR', 'CARD_NOTICE', 'CARD_IMPORT', 'BARCODE', 'BARCODE_SOURCE', 'BARCODE_REASON', 'BARCODE_DECISION',
        'SELLING', 'SELLING_NOTICE', 'SITE_SYNC', 'SELLING_ERROR', 'MODE_MEANING', 'RECEIPT_STATE', 'BENCH_STATE', 'INCIDENT_WHERE',
        'INCIDENT_KIND', 'INCIDENT_STATE', 'RECEIPT_FILE', 'STAMP_TYPE', 'UNSTAMPED_ACTION', 'MODE_SOURCE', 'RECEIVING', 'RECEIPT', 'BENCH', 'INCIDENTS',
        'RECEIPT_NOTICE', 'RECEIPT_ADDED', 'RECEIPT_ERROR', 'RECEIPT_PLAN', 'CONFIG', 'CONFIG_ACTION', 'CONFIG_ERROR', 'SETTING_EDIT', 'SETTING_NOTICE',
        'APPROVALS', 'REASONS_EDIT', 'REASON_NOTICE', 'WAREHOUSES', 'WHY_NOT_EMPTY', 'WAREHOUSE_NOTICE', 'MODE', 'SITES', 'SITE_COMMAND', 'INTEGRITY', 'AUDIT',
        'AUDIT_RECORD', 'AUDIT_FAMILY', 'AUDIT_ACTION', 'PERMISSION', 'ACCESS', 'ENROL', 'SHEET', 'STAFF_REQUESTS', 'RULE', 'NEW_CODE', 'RESET_KIND',
        'WATCH', 'STOCK_VIEW', 'TILE'];

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
        'purchasing_desk' => 'Books in deliveries with their invoices. Supplier invoices, returns and trade sales are coming later.',
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
        'stock' => 'What is in each warehouse, and every change to it.',
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
        'pending' => 'Second approval',
        'samples' => 'Spot check',
        'duplicates' => 'Duplicates',
        'barcodes' => 'Barcodes',
        // Stock
        'stock' => 'Overview',
        'movements' => 'Movements',
        'adjustments' => 'Adjustments',
        'counts' => 'Counts',
        'transfers' => 'Transfers',
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
    ];

    /** A page's create button (Sections::MAP `new`): a verb that names the result. */
    public const NEW = [
        'orders' => 'New purchase order',
        'receiving' => 'Receive delivery',
        'suppliers' => 'New supplier',
        'people' => 'New user',
        'warehouses' => 'New warehouse',
        'reasons' => 'New reason',
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
        'duplicates' => 'Vape and Go pages that may be the same product.',
        'barcodes' => 'Barcodes the computer could not place.',
        'stock' => 'What is in each warehouse now.',
        'movements' => 'Every change to the stock, newest first.',
        'reorder' => 'What to buy now, worked out from sales.',
        'orders' => 'The orders we send to suppliers.',
        'goods_in' => 'Book in each delivery against its supplier invoice, check it, and deal with what was wrong.',
        'suppliers' => 'The companies we buy from.',
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
        // A sidebar section's count: everything in it that waits for this person.
        'section' => 'waiting for you',
    ];

    /** Permissions::COMING_LATER key => words for Home's "Coming later" line (no phase codes, no dates). */
    public const COMING_LATER = [
        'invoices' => 'supplier invoices',
        'returns' => 'returns to suppliers',
        'counts' => 'stock counts',
        'adjustments' => 'stock corrections and write-offs',
        'trade' => 'trade sales',
        'values' => 'stock values for the accounts',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // 4. Page titles and intros: one sentence pair at the top of every page ([what it is, what to do])

    /** Page key => title, where it is not the menu name. */
    public const PAGE_TITLE = [
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
        'access' => 'Roles and permissions',
        'enrol' => 'Set up my sign-in',
        'staff_requests' => 'Staff requests',
        'new_code' => 'Your own sign-in code',
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
        'sales_history' => ['Daily website sales that Reorder uses. ' . self::ASK . ' loads them from the websites.',
            'If a website product is not matched to a warehouse product, its sales are not used: the list below shows the biggest ones.'],
        'orders' => ['Every order to a supplier.', 'Start a new one below, or open one to see where it is.'],
        'order_draft' => ['A new order, not confirmed yet.', 'Add products by scanning or searching, check packs and prices, then confirm it. An order over the approval limit goes to a reviewer first.'],
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
        'incidents' => ['Problems the goods-in bench found in deliveries that are booked in: short, extra, damaged, the wrong product, or no duty stamp.',
            'Close each one with what was done, for example a credit asked for or the goods sent back.'],
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
        'veto_on_key' => 'a rule says the barcode\'s product cannot be right',
        'alias_pending' => 'the range has another name',
        'no_candidates' => 'nothing similar in the warehouse list',
        'all_candidates_vetoed' => 'every similar product is ruled out',
        'placeholder' => 'not a real product',
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
        'later' => 'This first version shows the warehouse figures. Stock by store, the VPG 2 room and overflow come in a later update.',
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
        'supplier_changed' => 'Supplier details changed',
        'all_documents' => 'Every order is checked',
        'company_changed' => 'Company details changed',
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
        'staff.setup_hours' => 'Hours to set up a sign-in',
        'staff.min_reviewers' => 'Reviewers needed',
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
        'not_checked_help' => 'Nothing to do now: they come in the next computer check.',
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
        'ADJ' => 'Stock correction',
        'WO' => 'Write-off',
        'TRD' => 'Trade sale',
    ];

    /** document_type.code => many of that kind (lists, filters, sentences). */
    public const DOC_TYPES = [
        'PO' => 'Purchase orders',
        'GRN' => 'Deliveries',
        'SINV' => 'Supplier invoices',
        'DN' => 'Returns to suppliers',
        'CNT' => 'Stock counts',
        'ADJ' => 'Stock corrections',
        'WO' => 'Write-offs',
        'TRD' => 'Trade sales',
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
        'increase' => 'Up',
        'decrease' => 'Down',
        'either' => 'Up or down',
    ];

    /** app_setting key prefix => the topic its settings are listed under. */
    public const SETTING_TOPIC = [
        'po' => 'Purchase orders',
        'suppliers' => 'Suppliers',
        'reorder' => 'Reorder',
        'costs' => 'Costs',
        'company' => 'Company details',
        'receiving' => 'Deliveries',
        'site_writer' => 'Websites',
        'approvals' => 'Approval rules',
        'staff' => 'Staff',
    ];

    /** app_setting key => what the setting does, in words (the migration's description is the fallback). */
    public const SETTING_HELP = [
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
        'company.confirmed' => 'Until the owner confirms the company details, every purchase order PDF says DO NOT SEND.',
        'approvals.supplier_activation' => 'A new supplier, a supplier used again, and an overseas supplier\'s duty-stamp arrangement wait for a reviewer\'s OK. '
            . 'Off: a buyer\'s request makes the supplier usable at once, marked "approved alone".',
        'approvals.match_multiple' => 'A match where 1 sale uses more or less than 1 warehouse product waits for a second matching lead.',
        'approvals.match_counted' => 'Joining (or undoing a join of) products whose stock was counted waits for a second matching lead.',
        'approvals.company_own_change' => 'A reviewer who confirms their own change of our name, numbers, purchasing e-mail or delivery address gets another '
            . 'reviewer\'s check afterwards.',
        'approvals.staff_grant' => 'Giving someone the Admin or Reviewer job waits for a reviewer\'s OK. Off: the admin\'s change works at once.',
        'approvals.staff_reset' => 'A new sign-in code, password or sign-up sheet for someone with Admin or Reviewer waits for a reviewer\'s OK. Off: the admin makes it at once.',
        'staff.setup_max_fails' => 'After this many wrong tries, a sign-in set-up closes: the person cannot try again until the admin makes a new sheet, and the Dashboard says so.',
        'staff.sign_in_address' => 'The address staff open to sign in, for example https://warehouse.example.com. The sign-up sheets print it. Leave it empty until you know it.',
        'approvals.spot_check_size' => 'How many strong matches a spot check holds. A smaller spot check never confirms the rest together.',
        'staff.setup_hours' => 'How long a new person, or one told to choose a new password, has to set up their sign-in (at most a week).',
        'staff.min_reviewers' => 'Users and the Dashboard warn when fewer people than this can approve work.',
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
        'seed' => 'made from this website product',
    ];

    /** How a stock change came about (stock_ledger.movement_type). */
    public const MOVEMENT = [
        'reserve' => 'Reserved in a checkout',
        'release' => 'Reservation freed',
        'expire' => 'Reservation ran out',
        'commit' => 'Order paid',
        'commit_release' => 'Order paid (reservation freed)',
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
        'opening' => 'Opening stock',
        'merge_in' => 'Joined from a duplicate',
        'merge_out' => 'Moved to the product it was joined into',
        'split_in' => 'Join undone: came back',
        'split_out' => 'Join undone: moved back',
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
        'pick_first' => 'Pick the warehouse product first: search for it, or use one the AI looked at. Nothing was saved.',
        'nothing_to_reject' => 'Nothing is suggested, so there is nothing to say no to. Nothing was saved.',
        'ignore_why' => 'Write a few words to say why you ignore it. Nothing was saved.',
        'units' => '"How many does 1 sale use?" must be a whole number from 1 to %s. Nothing was saved.',
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
    ];

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
        'po_closed' => 'This delivery cannot be taken back here: %s was closed after the delivery, and taking it back would open the order again. Say OK if it is right. '
            . 'If it is wrong, leave the check open and ask the purchasing manager (the stock is corrected with a stock correction).',
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
        'reject_record_only' => 'Not OK on a purchase order only records it: an order with deliveries cannot be cancelled. Nothing was saved.',
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
        'others' => 'Other things could wait for a second person too, for example write-offs over a value, settings changes or warehouse changes. '
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
        'agreed' => 'The owner has agreed this rule',
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
        'approvals.staff_grant' => [
            'title' => 'Giving someone Admin or Reviewer',
            'on' => 'Giving someone the Admin or Reviewer job waits for a reviewer\'s OK. Nothing changes until then.',
            'off' => 'Off: the admin\'s change works at once.',
        ],
        'approvals.staff_reset' => [
            'title' => 'Resetting the sign-in of an Admin or a Reviewer',
            'on' => 'A new sign-in code, a new password or a new sign-up sheet for someone with Admin or Reviewer waits for a reviewer\'s OK. '
                . 'The admin then makes it, once. With only one Reviewer, a reset of that Reviewer is done on the server.',
            'off' => 'Off: the admin makes it at once. The admin still never holds both keys of anybody.',
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
    ];

    /** Notices after a change of a reason. */
    public const REASON_NOTICE = [
        'added' => 'Done: the reason was added. It is offered from now on.',
        'renamed' => 'Saved: the reason has its new name.',
        'off' => 'Done: the reason is switched off.',
        'on' => 'Done: the reason is switched on again.',
        'unchanged' => 'Nothing changed: the reason was already like that.',
        'uses_saved' => 'Saved: the reason is offered in the lists you ticked.',
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
    ];

    /** What each server command of the Websites page does (Admin\Sites::commands). */
    public const SITE_COMMAND = [
        'mode' => 'Move to the next mode',
        'writer' => 'Switch the stock writer',
        'ips' => 'Change the allowed addresses',
        'warehouse' => 'Sell from another warehouse',
        'key' => 'Make a new key',
        'health' => 'List the problems of every website',
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
        'demand_anomaly' => 'Days left out of sales',
        'document' => 'Record',
        'document_type' => 'Rule of a kind of record',
        'import_run' => 'Import',
        'incident' => 'Incident',
        'item_card' => 'Product card',
        'key_sample' => 'Spot check',
        'listing' => 'Website product',
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
    ];

    /** The first part of an audit action => what kind of thing was done (the audit log's filter and rows). */
    public const AUDIT_FAMILY = [
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
    ];

    /** audit_log.action => what was done, for the actions people look for most (the others: their kind, AUDIT_FAMILY). */
    public const AUDIT_ACTION = [
        'setting.change' => 'Setting changed',
        'document_type.change' => 'Rule of a kind of record changed',
        'reason.add' => 'Reason added',
        'reason.rename' => 'Reason renamed',
        'reason.switch_off' => 'Reason switched off',
        'reason.switch_on' => 'Reason switched on again',
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
        'doc.SINV.post' => 'Book supplier invoices (coming later)',
        'doc.DN.post' => 'Send goods back to suppliers (coming later)',
        'doc.CNT.post' => 'Count stock on the shelves (coming later)',
        'doc.ADJ.post' => 'Correct stock (coming later)',
        'doc.WO.post' => 'Write stock off (coming later)',
        'doc.TRD.post' => 'Trade sales (coming later)',
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

    public const TONES = ['needs', 'done', 'waiting', 'blocked', 'info', 'off', 'review'];

    /** group => code => tone; a code not listed is `info`. */
    public const TONE = [
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
        $holders = Permissions::MAP[$perm] ?? throw new \InvalidArgumentException("unknown permission {$perm}");
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
