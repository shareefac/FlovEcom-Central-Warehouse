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
    private const GROUPS = ['SITE', 'ROLE', 'ROLE_PLURAL', 'ROLE_HELP', 'ROLE_GROUP', 'SECTION', 'MENU', 'MENU_HELP', 'TAB', 'BADGE', 'COMING_LATER',
        'PAGE_TITLE', 'BAND', 'BAND_TITLE', 'BAND_HELP', 'LANE', 'AI', 'FLAG', 'VETO', 'BAND_REASON', 'FIELD_STATE', 'NEEDS_SECOND', 'ACTION',
        'DECISION_STATE', 'LISTING_STATUS', 'POLICY', 'STOCK', 'SAMPLE_STATE', 'SAMPLE_RESULT', 'PO_STATE', 'SEND_VIA', 'REASON', 'SUPPLIER_STATUS',
        'CHECK_REASON', 'CHECK_KIND', 'REVIEW_STATE', 'TASK_STATE', 'DOC_STATUS', 'REORDER_FLAG', 'SETTING', 'ERROR_TITLE', 'ERROR', 'UI', 'HOME',
        'SIGN_IN', 'REFUSAL', 'DOC_TYPE', 'DOC_TYPES', 'CHECK_STATE', 'CHECKS', 'RECORDS', 'RECORD', 'RECORD_NOTICE', 'COMPANY', 'COMPANY_NOTICE',
        'SETTINGS_PAGE', 'REASON_USE', 'SETTING_TOPIC', 'SETTING_HELP', 'STAFF', 'STAFF_NOTICE', 'SPOT', 'FIELD', 'FORM_VALUE', 'NIC_TYPE', 'AI_FIELD',
        'AI_FIELD_STATE', 'ACTION_DONE', 'MOVEMENT', 'ORIGIN', 'QUEUE', 'LISTING', 'PENDING', 'MATCH_NOTICE', 'MATCH_ERROR', 'SAMPLE', 'SAMPLE_FIT', 'DUPS',
        'DUP_NOTICE', 'DUP_ERROR', 'SEARCH', 'ITEM', 'PO', 'PO_FILTER', 'PO_SOURCE', 'ORDERS', 'ORDER', 'PO_NOTICE', 'BUY_ERROR', 'PO_WARN', 'REORDER', 'WHY',
        'REORDER_ITEM', 'BRANDS', 'ANOMALIES', 'REORDER_NOTICE', 'SALES', 'SUPPLIERS', 'SUPPLIER', 'SUPPLIER_FIELD', 'SUPPLIER_FORM', 'SUPPLIER_NOTICE', 'SUPPLIER_ITEMS', 'SI_NOTICE',
        'CARD_FIELD', 'CARDS', 'CARD_STATE', 'CARD', 'CARD_ERROR', 'CARD_NOTICE', 'CARD_IMPORT', 'BARCODE', 'BARCODE_SOURCE', 'BARCODE_REASON', 'BARCODE_DECISION'];

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
        'purchasing_manager' => 'What a Buyer does, plus deliveries and supplier invoices (coming later).',
        'goods_in' => 'Books in deliveries (coming later).',
        'purchasing_desk' => 'Deliveries, supplier invoices, returns and trade sales (coming later).',
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
    // 2. The menu (Permissions::MENU keeps key, perm and path; its words are here)

    /** Section key => heading. */
    public const SECTION = [
        'home' => 'Home',
        'check' => 'To check',
        'match' => 'Match products',
        'buy' => 'Buying',
        'products' => 'Products',
        'records' => 'Records',
        'staff' => 'Staff',
        'settings' => 'Settings',
    ];

    /** Menu (and page) key => its name. The same word in the menu, the page title and the crumbs. */
    public const MENU = [
        'home' => 'Home',
        'reviews' => 'Waiting for me',
        'review' => 'Products to match',
        'pending' => 'Waiting for 2nd OK',
        'samples' => 'Spot check',
        'duplicates' => 'Possible duplicates',
        'reorder' => 'What to buy',
        'orders' => 'Purchase orders',
        'suppliers' => 'Suppliers',
        'sales_history' => 'Sales data',
        'cards' => 'Product list',
        'barcodes' => 'Barcodes to check',
        'documents' => 'All records',
        'people' => 'Staff and access',
        'company' => 'Company details',
        'settings' => 'Settings and lists',
    ];

    /** One line under each menu item on Home ("What you can use"). */
    public const MENU_HELP = [
        'home' => 'What is waiting for you today.',
        'reviews' => 'Other people\'s work that needs your OK or your check.',
        'review' => 'Match each website product to its warehouse product.',
        'pending' => 'Matches that need a second matching lead before they take effect.',
        'samples' => '20 random strong matches, checked by one matching lead.',
        'duplicates' => 'Vape and Go pages that may be the same product.',
        'reorder' => 'What to buy now, worked out from sales.',
        'orders' => 'The orders we send to suppliers.',
        'suppliers' => 'The companies we buy from.',
        'sales_history' => 'Units sold per day, per product.',
        'cards' => 'Every product with its legal details and barcodes.',
        'barcodes' => 'Barcodes the computer could not place.',
        'documents' => 'Every final record, by number.',
        'people' => 'Who can use this system and what they may do.',
        'company' => 'Our name and addresses, printed on every purchase order.',
        'settings' => 'How the system is set up, with the reasons and number lists.',
    ];

    /** The phone tab bar: menu key => short name (Ui\Tabs picks up to four, then "More"). */
    public const TAB = [
        'home' => 'To do',
        'review' => 'Matches',
        'duplicates' => 'Duplicates',
        'orders' => 'Orders',
        'reviews' => 'To check',
        'reorder' => 'To buy',
        'suppliers' => 'Suppliers',
        'cards' => 'Products',
        'barcodes' => 'Barcodes',
        'people' => 'Staff',
        'documents' => 'Records',
        'company' => 'Company',
        'more' => 'More',
    ];

    /** Badge name (Context::badges) => the words a screen reader hears after the number ("3 waiting for your second OK"). */
    public const BADGE = [
        'linking_pending' => 'waiting for your second OK',
        'linking_duplicates' => 'waiting for you to decide',
        'reviews_open' => 'waiting for you',
        'barcodes_open' => 'barcodes for you to check',
    ];

    /** Permissions::COMING_LATER key => words for Home's "Coming later" line (no phase codes, no dates). */
    public const COMING_LATER = [
        'receive' => 'receiving deliveries',
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
        'reasons' => 'Reasons for stock changes',
        'series' => 'How record numbers are made',
        'reviews' => 'Things to check',
        'pending' => 'Waiting for a second OK',
        'samples' => 'Spot checks',
        'item' => 'Warehouse product',
        'card_import' => 'Change many product cards',
        'company_edit' => 'Change the company details',
        'supplier_new' => 'New supplier',
        'reorder_brands' => 'Brand settings',
        'reorder_anomalies' => 'Days to leave out of sales',
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
        'samples' => ['20 random strong matches that one matching lead checks.', 'If all 20 are right, the rest are confirmed together.'],
        'sample' => ['One spot check of 20 strong matches.', 'Open each one and say yes only if it is right. The button below opens the next one.'],
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
        'document' => ['The permanent record of one document and its checks.', 'To change an order, open it under Buying.'],
        'reorder' => ['What to buy now, worked out from recent website sales (unusual days left out), delivery times, and the stock you have and have ordered.',
            'It is only a suggestion: tick what you want, change the packs if needed, press "Create draft orders", then check each draft.'],
        'reorder_item' => ['Why the list suggests this amount.', 'Buyers can change this product\'s settings below.'],
        'reorder_brands' => ['Make the list buy more or less of a whole brand.', ''],
        'reorder_anomalies' => ['Days with unusual sales (for example stockpiling before the duty) that should be left out when CW works out what to buy.', ''],
        'sales_history' => ['Daily website sales that What to buy uses. ' . self::ASK . ' loads them from the websites.',
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
        'settings' => ['How the system is set up.', 'To change a setting, ask ' . self::ASK . '.'],
        'reasons' => ['The reasons people choose when stock goes up or down outside a sale.', ''],
        'series' => ['Each kind of record has its own numbers (PO-000001, PO-000002 …), with no gaps.', ''],
        'people' => ['Everyone who can use this system and what they may do.', 'Tap a name to change their access.'],
        'person' => ['What this person may do, and their history.', 'Tick their jobs and press Save, or stop them signing in.'],
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

    /** Why an order was cancelled or corrected (PurchaseOrders::CANCEL_REASONS, AMEND_REASONS). */
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
            . '(new suppliers, orders over the approval limit, matches where 1 sale is not 1 product, or the website already sells warehouse stock). '
            . '**Check after:** it happens at once, and a reviewer checks it within 7 days (every confirmed order, supplier changes). '
            . '**Nobody approves or checks their own work.** If you made it, someone else must.',
        // Correction a (7 Oct): never suggest a second account (the spot check owner-1 belongs to this account).
        'admin_off' => 'Admin gives people access. To keep that safe, the person who gives access must not also approve work. '
            . 'So while an account has Admin, its working jobs (Reviewer, Matching lead, Buyer …) are switched off. '
            . 'Only these jobs still work: Look only (matching), Accountant, Auditor. Ask ' . self::ASK . ' to take Admin off the account that does the work.',
        'spot_check' => 'Instead of checking every strong match one by one, one matching lead checks 20 picked at random. '
            . 'If all 20 are right, the rest are confirmed together in one step (' . self::ASK . ' runs it, and it can be undone). '
            . 'If even one is wrong, the rest must be checked one by one.',
        'sale_uses' => 'Most website products are single items: 1 sale uses 1 warehouse product. A 10-pack page uses 10. '
            . 'If this number is wrong, stock goes wrong, so anything other than 1 needs a second OK.',
        'stock_rule' => '**Website uses its own stock (not linked yet):** the website still uses its own stock figure. '
            . '**Website sells warehouse stock only:** the website can sell only what the warehouse has. '
            . '**Can sell when out:** customers can order and wait. **Not for sale.** '
            . 'Once a website sells warehouse stock, matching or joining its product needs a second OK.',
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
        'my_account' => 'Change my password',
        'sign_out' => 'Sign out',
        'no_jobs' => 'no job yet',
        'off' => 'off',
        'help' => 'What does this mean?',
        'quote_rid' => 'If you report a problem, quote this number:',
        'back_home' => 'Back to Home',
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
        'title' => 'Home',
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
            'text' => 'These already happened: confirmed orders, and changes to suppliers or to our company details. A reviewer checks each one within 7 days.',
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
            'text' => 'So What to buy may be too low. Ask ' . self::ASK . ' to load the new sales.',
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
        'open_order_note' => 'Its own page under Buying: lines, sending, cancelling, and the PDF for the supplier.',
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
        'alone' => 'Nobody else is a Reviewer yet, so this check stays open. It stops nothing. When a second person has the Reviewer job (Staff and access), they can close it.',
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
        'settings_text' => '"Not agreed yet" means the owner still has to say yes to this value. To change a setting, tell ' . self::ASK
            . ' the new value and why: it is changed on the server and logged. Company details: use the box above.',
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
        'reversal' => 'cancellations',
        'increase' => 'Up',
        'decrease' => 'Down',
        'either' => 'Up or down',
    ];

    /** app_setting key prefix => the topic its settings are listed under. */
    public const SETTING_TOPIC = [
        'po' => 'Purchase orders',
        'suppliers' => 'Suppliers',
        'reorder' => 'What to buy',
        'costs' => 'Costs',
        'company' => 'Company details',
    ];

    /** app_setting key => what the setting does, in words (the migration's description is the fallback). */
    public const SETTING_HELP = [
        'po.terms' => 'The text printed under every purchase order.',
        'po.default_vat_code' => 'Used on a new order line when the supplier has no VAT code.',
        'po.over_delivery_tolerance_pct' => 'How much more than ordered goods in may still accept (used when receiving deliveries is added).',
        'costs.site_writeback' => 'Write the warehouse average cost into the websites\' cost field (not available yet).',
        'suppliers.approval_due_days' => 'A reviewer should decide a new supplier, or a change of its duty-stamp arrangement, within this many days.',
        'suppliers.change_review' => 'When the details of a supplier we order from change, a reviewer checks them afterwards. Orders are not stopped.',
        'reorder.default_lead_days' => 'Days from order to delivery, when neither the product, the supplier\'s product nor the supplier says.',
        'reorder.default_review_days' => 'Days until the next order to the same supplier, when the supplier does not say.',
        'reorder.default_safety_days' => 'Extra days of stock to keep (5, the same as now).',
        'reorder.short_window_days' => 'What to buy looks at the sales of this many recent days (4 weeks).',
        'reorder.long_window_days' => 'What to buy also looks at the sales of this many days (3 months).',
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
    ];

    /** Staff and access (/ui/people, /ui/people/{id}; plan §6.35, 6.36). */
    public const STAFF = [
        'add' => 'To add a new staff member, send their name, e-mail and job to the developer. They set up the account and the phone code.',
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
    ];

    /** Notices after a click on a staff member's page (PeopleController::NOTICES). */
    public const STAFF_NOTICE = [
        'roles_saved' => 'Saved. The change works the next time the person opens or refreshes a page.',
        'roles_unchanged' => 'Nothing changed: the person already had exactly these jobs.',
        'deactivated' => 'Done: this person can no longer sign in. They were signed out everywhere.',
        'activated' => 'Done: this person can sign in again.',
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
        'from_dups' => '(from Possible duplicates)',
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
        'unusable' => 'This spot check cannot be used (it has fewer than 20, or it changed after it was made). Ask ' . self::ASK . ' to start a new one.',
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
        'sample_too_small' => 'fewer than 20 matches',
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
        'pending' => 'Saved, not live yet: joining %s needs a second matching lead\'s OK (see Waiting for 2nd OK).',
        'split' => 'Done: the join is undone. The page is back on its own warehouse product, with the stock that came with it.',
        'split_pending' => 'Saved, not live yet: undoing the join needs a second matching lead\'s OK (see Waiting for 2nd OK).',
        'done' => 'No possible duplicates are left to decide.',
        'next' => 'Here is the next group.',
        'the_group' => 'the group',
    ];

    /** A refusal on the duplicates pages, by error code (DecisionService's and the page's own). */
    public const DUP_ERROR = [
        'map_version_conflict' => 'One of these pages changed since you opened this page (someone matched or joined it, or the website renamed it). '
            . 'Nothing was saved: the page shows them as they are now. Check and decide again.',
        'proposal_changed' => 'The suggestions for this group changed since you opened this page. Nothing was saved: check the page and decide again.',
        'pending_second_exists' => 'A decision on one of these pages waits for a second OK. Nothing was saved: approve or cancel it first (Waiting for 2nd OK).',
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
        'reorder' => 'From What to buy',
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
        'title' => 'What to buy: %s (%s)',
        'product_page' => 'Product page',
        'no_brand' => 'No brand',
        'merged' => 'Joined into %s: never suggested.',
        'suggest' => 'What the list suggests',
        'not_listed' => 'This product is not on What to buy: no website sales were found for it.',
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
        'none' => 'No brand is on What to buy yet.',
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
            . 'Up to %s days at a time. After adding or ending, press "Work out sales again" on What to buy.',
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
        'item_saved' => 'Saved: What to buy uses these settings at once.',
        'brand_saved' => 'Saved: What to buy uses the brand\'s settings at once.',
        'anomaly_added' => 'Saved: these days are left out the next time sales are worked out ("Work out sales again" on What to buy).',
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
        'unmatched_text' => 'Their sales do not help What to buy. Ask the matching team to match these.',
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
        'products_line' => '%s products from this supplier. For %s of them this is the main supplier (What to buy orders from here).',
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
    ];

    /** A supplier's products (/ui/purchasing/suppliers/{id}/items, supplier-items/{id}, plan §6.30). */
    public const SUPPLIER_ITEMS = [
        'title' => 'Products from %s',
        'intro' => 'Prices are per pack, in £, without VAT. "Box of 24" means one box holds 24 single items. "Main supplier" means What to buy orders this product from here.',
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
        'main_yes' => 'Yes (What to buy orders from here)',
        'main_no' => 'No (a backup supplier)',
        'in_use' => 'Still bought here',
        'no' => 'No',
        'price_now' => 'Price now',
        'price_line' => '%s a pack (%s an item), %s on %s',
        'none_yet' => 'none yet',
        'po_price' => 'Price on the last order',
        'po_line' => '%s on %s',
        'make_main' => 'Make this the main supplier for this product',
        'make_main_does' => 'What to buy will order this product from here. Any other supplier stops being its main supplier.',
        'stop_main' => 'Stop using as main supplier',
        'stop_main_does' => 'What to buy will not know where to order this product until you choose another main supplier.',
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
        'main_tick' => 'Main supplier for this product (What to buy orders from here)',
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
        'preferred' => 'Done: this is now the main supplier for this product. What to buy orders it from here.',
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
            . 'A block stops What to buy and the confirming of orders. It does not stop website sales yet: take a blocked product off sale on the website by hand.',
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
        'blocked' => 'Blocked: What to buy never suggests it, and an order with it cannot be confirmed. It is still on sale on the website: take it off by hand.',
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
        'discontinued' => 'Not sold any more: never suggest it on What to buy',
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
            . 'If both products\' website products carry it, decide it in Barcodes to check instead. Nothing was changed.',
        // "Change many product cards": a file with columns the import does not know.
        'unknown_columns' => 'Nothing was imported: the file has columns the product cards do not have: %s. Use the columns of the downloaded product list.',
    ];

    /** Notices after a click on a product page (ItemController::NOTICES). */
    public const CARD_NOTICE = [
        'card_saved' => 'Saved: the product card.',
        'card_saved_unconfirmed' => 'Saved. A legal detail changed, so the card is no longer confirmed: check it and confirm it again.',
        'card_unchanged' => 'Nothing changed.',
        'accepted' => 'Done: the product card now has this suggestion.',
        'confirmed' => 'Confirmed: the product card. If a person confirms details that break a rule, the product is blocked instead of warned about.',
        'confirmed_blocked' => 'Confirmed. The product breaks a rule, so it is now BLOCKED: What to buy never suggests it, and an order with it cannot be confirmed. '
            . 'It is still on sale on the website: take it off by hand. It stays blocked until someone corrects the card and confirms it again.',
        'confirmed_lifted' => 'Confirmed. The details no longer break the rules the last confirmation blocked, so the product is no longer blocked.',
        'already_confirmed' => 'The product card was already confirmed.',
        'barcode_added' => 'Done: the barcode is added.',
        'barcode_added_unusable' => 'Added, but it cannot be used yet: it waits in Barcodes to check.',
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
        'open_reviews' => 'Waiting in Barcodes to check:',
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
        'review' => 'From Barcodes to check',
        'reband' => 'From the computer check',
        'in_review' => 'waits in Barcodes to check',
    ];

    /** Why a barcode waits in Barcodes to check (BarcodeReviews::REASONS keys). */
    public const BARCODE_REASON = [
        'on_another_item' => 'On another product',
        'multipack_listing' => 'Pack or single item?',
        'removed' => 'Removed by a person',
    ];

    /** The answers in Barcodes to check (BarcodeReviews::DECISIONS and RECORDED keys). */
    public const BARCODE_DECISION = [
        'keep_holder' => 'It belongs to the product that has it (the website product carries a wrong barcode)',
        'move' => 'It belongs to the website product\'s product: move it there',
        'unusable' => 'Shared by both: keep it, but do not use it',
        'add' => 'Add it to the website product\'s product',
        'dismiss' => 'Do not add it',
        'removed' => 'Removed from the product',
        'moved_away' => 'Moved to another product in Barcodes to check',
    ];

    // ------------------------------------------------------------------------------------------------------------------
    // Status chip tones: needs (you act), done, waiting (someone else), blocked, info, off

    public const TONES = ['needs', 'done', 'waiting', 'blocked', 'info', 'off'];

    /** group => code => tone; a code not listed is `info`. */
    public const TONE = [
        'LISTING_STATUS' => ['unmapped' => 'needs', 'suggested' => 'needs', 'mapped' => 'done', 'ignored' => 'off', 'quarantined' => 'blocked'],
        'DECISION_STATE' => ['pending_second' => 'waiting', 'applied' => 'done', 'withdrawn' => 'off'],
        'SAMPLE_STATE' => ['open' => 'needs', 'confirmed' => 'done', 'needed_second' => 'done', 'waiting_second' => 'waiting', 'rejected' => 'blocked',
            'superseded' => 'off', 'decided_otherwise' => 'blocked', 'confirmed_by_other' => 'blocked', 'confirmed_not_by_lead' => 'blocked', 'changed_since' => 'blocked'],
        'SAMPLE_RESULT' => ['waiting' => 'needs', 'passed' => 'done', 'failed' => 'blocked', 'unusable' => 'blocked'],
        'PO_STATE' => ['draft' => 'needs', 'awaiting_approval' => 'waiting', 'approved' => 'needs', 'sent' => 'waiting', 'part_received' => 'waiting',
            'received' => 'done', 'closed' => 'done', 'cancelled' => 'off'],
        'SUPPLIER_STATUS' => ['draft' => 'needs', 'pending_approval' => 'waiting', 'active' => 'done', 'inactive' => 'off'],
        'REVIEW_STATE' => ['not_required' => 'off', 'pending' => 'waiting', 'approved' => 'done', 'rejected' => 'blocked'],
        'TASK_STATE' => ['open' => 'waiting', 'approved' => 'done', 'rejected' => 'blocked', 'withdrawn' => 'off'],
        'DOC_STATUS' => ['draft' => 'needs', 'awaiting_approval' => 'waiting', 'posted' => 'done', 'reversed' => 'off', 'cancelled' => 'off'],
        'POLICY' => ['legacy' => 'off', 'strict' => 'done', 'backorder' => 'info', 'stopped' => 'blocked'],
        'FIELD_STATE' => ['same' => 'done', 'differs' => 'blocked', 'conflict' => 'blocked', 'alike' => 'info', 'spelt' => 'info', 'unknown' => 'off', 'missing' => 'off'],
        'CARD_STATE' => ['none' => 'off', 'unconfirmed' => 'needs', 'changed' => 'needs', 'confirmed' => 'done', 'warned' => 'needs', 'blocked' => 'blocked'],
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
