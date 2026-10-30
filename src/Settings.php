<?php

declare(strict_types=1);

namespace CW;

use CW\Company\CompanyDetails;

/**
 * CW's typed settings (app_setting, 0009; docs/decisions.md I38-I41): the supplier approval rules (decision 11), the cost
 * write-back switch (decision 12) and, from the later I-2 tasks, the PO and reorder defaults; since 0019 the approval switches
 * (approvals.*: Admin\ApprovalRules), the spot-check size and the staff set-up window. Since 0019 (the set-it-yourself pack, Y4)
 * the Settings page changes them (change(): an admin or a reviewer, settings.manage, with a reason; every change is a version in
 * config_change and an audit row); bin/settings.php --admin keeps working through the same code (set()). Rows are read once per
 * instance (a request or a job sees one consistent set).
 *
 * The company details of the PO letterhead (decision 9) were company.* settings until 0013; they are now versions in
 * company_profile, added and confirmed by staff on the Company details screen (CW\Company\CompanyDetails, I90-I99).
 * company() reads them from there, and set() refuses a company.* key with a pointer to the screen.
 *
 * value_json holds a JSON string, number or boolean; "" means "not set" (an empty placeholder: get() returns '' for the
 * text types and null for int, decimal and date). A decimal is kept as a JSON string (its digits exactly as given; a
 * seeded JSON number is read through its text), and get() returns it as a decimal string: never a float.
 */
final class Settings
{
    public const TYPES = ['string', 'text', 'int', 'decimal', 'bool', 'date'];
    /** Where the company details are changed since 0013 (I91). */
    public const COMPANY_SCREEN = '/ui/reference/company';
    public const STRING_MAX = 255;
    public const TEXT_MAX = 4000;

    /**
     * Key-specific checks on top of the type (parse()): `min`/`max` (an int or a decimal string), `vat_code` (the code
     * must exist in vat_code and be active), `email` (a valid address or empty), `in` (one of the listed values, exactly),
     * `pattern` (a regular expression the text must match; `pattern_says` words it for the refusal), `list_in` (a list of the listed
     * values separated by commas, each once, in any order, or empty; `words` names the Ui\Words group the screens show them in).
     * Every key ending in `_days` is a day count from 0 to 120 unless listed here.
     */
    public const RULES = [
        'suppliers.approval_due_days' => ['min' => 1, 'max' => 120],
        'po.default_vat_code' => ['vat_code' => true],
        'po.over_delivery_tolerance_pct' => ['min' => 0, 'max' => 200],
        'po.board_recent_days' => ['min' => 1, 'max' => 365], // 0053 (PV18): the board's "Received or closed" group
        'reorder.short_weight' => ['min' => '0', 'max' => '1'],
        'reorder.promo_price_drop' => ['min' => '0', 'max' => '1'],
        'reorder.promo_units_uplift' => ['min' => '1', 'max' => '100'],
        'reorder.promo_min_units' => ['min' => 0, 'max' => 1_000_000],
        'reorder.spike_cap_multiple' => ['min' => 1, 'max' => 100],
        'reorder.spike_cap_floor' => ['min' => 0, 'max' => 1_000_000],
        // The windows and their fewest valid days (review nit, I82): bounded as ReorderSettings::params() reads them, so a
        // value the tool accepts never makes every reorder list and build fail with 500 bad_setting.
        'reorder.short_window_days' => ['min' => 1, 'max' => 120, 'at_least' => ['reorder.min_valid_days_short'], 'at_most' => ['reorder.long_window_days']],
        'reorder.long_window_days' => ['min' => 1, 'max' => 120, 'at_least' => ['reorder.short_window_days', 'reorder.min_valid_days_long']],
        'reorder.min_valid_days_short' => ['min' => 1, 'max' => 120, 'at_most' => ['reorder.short_window_days']],
        'reorder.min_valid_days_long' => ['min' => 1, 'max' => 120, 'at_most' => ['reorder.long_window_days']],
        // Receiving (IM6, 0017; I125-I147): the duty rate behind the expected duty shown, how far back a paper sheet may be dated,
        // the mode an Out-Of-Stock item gets when its previous mode is not known (never Out-Of-Stock: the delivery is for sale).
        'receiving.duty_pence_per_ml' => ['min' => 1, 'max' => 1000],
        'receiving.backdate_max_days' => ['min' => 0, 'max' => 120],
        'receiving.mode_after_out_of_stock' => ['in' => ['In-Stock', 'From-Warehouse']],
        // The site stock writer (IM10, 0018; I148-I166): the sites a receipt's selling mode lands on, as channel codes.
        'site_writer.receipt_mode_sites' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}(,[a-z][a-z0-9_]{0,31})*$/D',
            'pattern_says' => 'channel codes separated by commas, like vapeandgo,electrofag (empty: none)'],
        // The set-it-yourself pack (0019, Y5-Y8): the spot-check size (KeySample), the staff set-up window, the reviewers asked for.
        'approvals.spot_check_size' => ['min' => 5, 'max' => 200],
        // At most a week (review finding I5): a set-up window open longer is a longer chance to guess.
        'staff.setup_hours' => ['min' => 1, 'max' => 168],
        'staff.setup_max_fails' => ['min' => 1, 'max' => 20],
        'staff.min_reviewers' => ['min' => 1, 'max' => 10],
        // The address printed on the sign-up sheets (review nit: never the Host header a request happened to carry).
        'staff.sign_in_address' => ['pattern' => '#^https?://[A-Za-z0-9.-]{1,200}(:[0-9]{1,5})?$#D',
            'pattern_says' => 'the address of the sign-in page without a path, like https://warehouse.example.com'],
        // Bulk action on the matching screens (0021, M46): the match strengths whose ticked rows may be confirmed together (the
        // others stay one at a time; empty: none), and the most rows one bulk action takes (a page holds 50).
        'mapping.bulk_confirm_bands' => ['list_in' => \CW\Mapping\Proposals::BANDS, 'words' => 'BAND'],
        'mapping.bulk_max_rows' => ['min' => 1, 'max' => 500],
        // Products › By Item (0025, U123): how many items a page shows unless the person picks another number there.
        'mapping.by_item_page_size' => ['in' => [50, 100, 200]],
        // Products › New Variants (0026, U132): the days back the page looks when it opens (up to a year; "Any time" is on the page).
        'mapping.new_variants_days' => ['min' => 1, 'max' => 365],
        // The check on arrival (0027, U145): the stores it looks at, as channel codes (empty: the check is switched off); the one main
        // store; how many variants and how many seconds one run takes at most; and above how many waiting variants of a store the
        // timer leaves the store to a hand run.
        'mapping.arrival_stores' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}(,[a-z][a-z0-9_]{0,31})*$/D',
            'pattern_says' => 'channel codes separated by commas, like vapebig,electrofag (empty: the check is switched off)'],
        'mapping.arrival_main_store' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}$/D', 'pattern_says' => 'one channel code, like vapeandgo (empty: no main store)'],
        'mapping.arrival_max_per_run' => ['min' => 1, 'max' => 5000],
        'mapping.arrival_max_seconds' => ['min' => 5, 'max' => 600],
        'mapping.arrival_queue_alarm' => ['min' => 1, 'max' => 100000],
        // The website state (0028, M66): how many days old a variant's stored state may be before it reads as not known (a day
        // at the least, a year at the most: not the 0 to 120 of the other day counts).
        'mapping.arrival_state_max_days' => ['min' => 1, 'max' => 365],
        // Stock counting (pack A2a, 0029; docs/decisions.md CT6, CT10): the count plan's days have a range of their own (a day count
        // stops at 120 otherwise, and the plan may run to two years); the two limits above which a count difference is checked by a
        // person (units: 0 = every difference; percent of what the system held: 0 = not used).
        'counts.plan_days' => ['min' => 1, 'max' => 730],
        'counts.review_over_units' => ['min' => 0, 'max' => 1_000_000],
        'counts.review_over_percent' => ['min' => 0, 'max' => 1000],
        // How far before its own time a sheet line may be booked under a shared count time (review I-1, 14 Oct 2026; CT3): minutes,
        // 0 to 60. Up to 10 (the default, the engine's "near a count" time) a dispatch or a reset reported late in the gap is flagged on
        // Differences; above it, it may not be.
        'counts.time_window_max_minutes' => ['min' => 0, 'max' => 60],
        // ---- Pack D (0033; docs/decisions.md PD9): Stock › Sales, its orders, dispatch and returns per store. ----
        // Rows a page shows (also the sold units on one order's page); the days Orders looks back when it opens; the hours after
        // which a paid order not sent is late on Dispatch (up to 30 days); the most rows a CSV holds; and the most rows one page
        // reads to fill itself where a filter is not in an index (the page then says it stopped, and offers to read on).
        'sales.page_size' => ['in' => [25, 50, 100, 200]],
        'sales.default_days' => ['min' => 1, 'max' => 365],
        'sales.late_hours' => ['min' => 1, 'max' => 720],
        'sales.csv_max_rows' => ['min' => 100, 'max' => 50_000],
        'sales.scan_max_rows' => ['min' => 500, 'max' => 50_000],
        // ---- end of pack D ----
        // ---- Pack B (0031, docs/decisions.md PB5): the most items one bulk change of a family takes (a list of CW numbers, or the ticks).
        'items.family_bulk_max_rows' => ['min' => 1, 'max' => 1000],
        // ---- end of pack B
        // --- Pack C (0032; docs/decisions.md PC3, PC9): supplier invoices. The 3-way match's tolerances (the plan's IM7 numbers; it
        // gives none for the quantity: exact) and how an invoice's freight and duty are spread over its goods for the stock value.
        'invoices.price_tolerance_pct' => ['min' => '0', 'max' => '100'],
        'invoices.price_tolerance_gbp' => ['min' => '0', 'max' => '100000'],
        'invoices.qty_tolerance_pct' => ['min' => '0', 'max' => '100'],
        'invoices.total_tolerance_per_line' => ['min' => '0', 'max' => '100'],
        'invoices.freight_spread' => ['in' => ['value', 'ml']],
        'invoices.duty_spread' => ['in' => ['ml', 'value']],
        // --- end of pack C
        // ---- Pack F (go-live, 0036; docs/decisions.md PF3, PF7, PF10, PF11) -----------------------------------------------------
        // Where alerts go (an https address and an e-mail address; empty: nothing is sent), how often the same alert is sent again, one
        // limit per check, the answer-time probe; how long the pruned tables keep their rows (idempotency at least the plan's 30 days,
        // D14; the audit log at least 7 years, the plan's "year end + 6 years"), the chunk and the time a pruning run takes; the relay's
        // map of an ERPNext selling mode to the stock rule it proposes for a protected item (plan §9).
        'alerts.webhook_url' => ['pattern' => '#^https://[A-Za-z0-9.-]{1,200}(:[0-9]{1,5})?(/[\x21-\x7e]{0,200})?$#D',
            'pattern_says' => 'an https address, like https://hooks.example.com/services/abc (empty: no alert is posted)'],
        'alerts.email_to' => ['email' => true],
        'alerts.repeat_minutes' => ['min' => 5, 'max' => 1440],
        'alerts.heartbeat_late_seconds' => ['min' => 60, 'max' => 86400],
        'alerts.outbox_max_depth' => ['min' => 1, 'max' => 1_000_000],
        'alerts.outbox_max_age_seconds' => ['min' => 30, 'max' => 86400],
        'alerts.dead_letters_max' => ['min' => 0, 'max' => 100_000],
        'alerts.feed_lag_max_seconds' => ['min' => 5, 'max' => 86400],
        'alerts.answer_max_ms' => ['min' => 50, 'max' => 60000],
        'alerts.answer_probes' => ['min' => 1, 'max' => 10],
        'alerts.invariants_max_age_hours' => ['min' => 25, 'max' => 168],
        'alerts.send_timeout_seconds' => ['min' => 1, 'max' => 30],
        'housekeeping.idempotency_keep_days' => ['min' => 30, 'max' => 3650],
        'housekeeping.channel_health_keep_days' => ['min' => 7, 'max' => 3650],
        'housekeeping.audit_keep_years' => ['min' => 7, 'max' => 50],
        'housekeeping.chunk_rows' => ['min' => 100, 'max' => 10000],
        'housekeeping.max_seconds' => ['min' => 10, 'max' => 3600],
        'relay.mode_in_stock' => ['in' => ['strict', 'backorder', 'stopped']],
        'relay.mode_from_warehouse' => ['in' => ['strict', 'backorder', 'stopped']],
        'relay.mode_out_of_stock' => ['in' => ['strict', 'backorder', 'stopped']],
        // ---- end of pack F ------------------------------------------------------------------------------------------------------
        // ---- Pack E (0034; docs/decisions.md PE8-PE12): the Reports tabs and the job that keeps them up to date. The slow and dead days
        // hold each other (dead at least slow); the job's two bounds keep one run small on the staging cluster.
        'reports.low_stock_default' => ['min' => 0, 'max' => 100_000],
        'reports.slow_after_days' => ['min' => 7, 'max' => 730, 'at_most' => ['reports.dead_after_days']],
        'reports.dead_after_days' => ['min' => 7, 'max' => 1095, 'at_least' => ['reports.slow_after_days']],
        'reports.sales_max_days' => ['min' => 7, 'max' => 1830],
        'reports.refresh_max_rows' => ['min' => 1000, 'max' => 1_000_000],
        'reports.refresh_max_days' => ['min' => 1, 'max' => 366],
        // ---- end Pack E
        // ---- Pack I (0039, the ERPNext opening import; docs/decisions.md PI1-PI6) ----
        // What an item below zero opens at; the valuation rates that are only placeholders (pounds, comma-separated); the store whose
        // variant numbers ERPNext's items carry; the CW warehouse the opening is booked at. The warehouse, company and blocked-name lists
        // are texts, one name per line (ErpOpeningRules refuses a list that names a blocked name).
        'erp_opening.below_zero' => ['in' => ['zero', 'carry']],
        'erp_opening.placeholder_costs' => ['pattern' => '/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,6})?(,(0|[1-9][0-9]{0,7})(\.[0-9]{1,6})?)*$/D',
            'pattern_says' => 'amounts in pounds separated by commas, like 0,1 (empty: no placeholder amount; a blank rate always is one)'],
        'erp_opening.listing_store' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}$/D', 'pattern_says' => 'one channel code, like vapeandgo (empty: none)'],
        'erp_opening.cw_warehouse' => ['pattern' => '/^[A-Z][A-Z0-9_]{0,31}$/D', 'pattern_says' => 'a CW warehouse code in capitals, like MAIN'],
        // ---- end of pack I ----
        // --- Pack G: the reorder engine (0037; docs/decisions.md PG1-PG16) ------------------------------------------------------------
        // The stores whose sales come from CW's own record (empty: none, the imported history only); how often the list is worked out
        // again, in what steps, and up to how many items at once after a click; the UK hour of the nightly sales work-out; how many
        // days of the own record are folded again and at most per run; the most products one low-stock click changes (a page's 200).
        'reorder.own_sales_stores' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}(,[a-z][a-z0-9_]{0,31})*$/D',
            'pattern_says' => 'channel codes separated by commas, like vapeandgo,electrofag (empty: the imported sales only)'],
        'reorder.refresh_minutes' => ['min' => 1, 'max' => 1440],
        'reorder.refresh_chunk_items' => ['min' => 50, 'max' => 5000],
        'reorder.refresh_inline_max_items' => ['min' => 0, 'max' => 50000],
        'reorder.demand_hour' => ['min' => 0, 'max' => 23],
        'reorder.fold_refold_days' => ['min' => 1, 'max' => 31],
        'reorder.fold_days_per_run' => ['min' => 1, 'max' => 400],
        'reorder.low_stock_bulk_max' => ['min' => 1, 'max' => 200],
        // --- end of Pack G ---------------------------------------------------------------------------------------------------------------
        // ---- Pack H (0038; docs/decisions.md PH1-PH20): hand-overs, the company's VAT code, the duty register. ----
        // A new hand-over line's price when the customer has none remembered: typed by the person, or the cost hint (PH6); the most rows
        // of one file of past prices (PH7); the warehouses whose stock is unstamped, as warehouse codes (PH16); the duty register's page
        // and download sizes (PH17).
        'handovers.price_fallback' => ['in' => ['none', 'cost']],
        'handovers.price_import_max_rows' => ['min' => 1, 'max' => 100000],
        'duty.register_lines' => ['in' => ['stamp', 'all']],
        'duty.unstamped_warehouses' => ['pattern' => '/^[A-Z][A-Z0-9_]{0,15}(,[A-Z][A-Z0-9_]{0,15})*$/D',
            'pattern_says' => 'warehouse codes separated by commas, like UNSTAMPED (empty: none)'],
        'duty.page_rows' => ['in' => [50, 100, 200, 500]],
        'duty.export_max_rows' => ['min' => 100, 'max' => 100000],
        // ---- end of pack H ----
        // ---- Pack J (0041; docs/decisions.md PJ5, PJ6, PJ12): goods on a final supplier invoice that have not arrived yet. ----
        'arrivals.late_hours' => ['min' => 1, 'max' => 720],
        'arrivals.default_mode' => ['in' => ['count', 'received']],
        // ---- end of pack J ----
        // ---- Pack RB (0046; docs/decisions.md RB2, RB4): the rollback file. The ERPNext names are typed as ERPNext writes them; one that
        // starts like a spreadsheet formula is refused (the files are read by ERPNext and opened by people). Empty: the file is not made.
        'rollback.cw_warehouses' => ['pattern' => '/^[A-Z][A-Z0-9_]{0,31}(,[A-Z][A-Z0-9_]{0,31})*$/D',
            'pattern_says' => 'warehouse codes separated by commas, like MAIN,VERIFY'],
        'rollback.erp_warehouse' => ['pattern' => '/^[^\x00-\x20\x7F=+\-@][^\x00-\x1F\x7F]{0,139}$/Du',
            'pattern_says' => 'an ERPNext warehouse name, like Stores - VPG'],
        'rollback.erp_company' => ['pattern' => '/^[^\x00-\x20\x7F=+\-@][^\x00-\x1F\x7F]{0,139}$/Du',
            'pattern_says' => 'an ERPNext company name, like VAPE AND GO'],
        'rollback.max_rows' => ['min' => 1, 'max' => 10_000_000],
        // ---- end of pack RB ----
        // ---- How long a person stays signed in (0047; docs/decisions.md SL1-SL5; Auth\Sessions::limits): minutes without opening a page,
        // and hours after signing in (also the session cookie's lifetime). Never shorter than the fixed limits of before (30 minutes, 12
        // hours: the defaults); at most 30 days away and 90 days in all.
        'session.idle_minutes' => ['min' => 30, 'max' => 43_200],
        'session.absolute_hours' => ['min' => 12, 'max' => 2_160],
        // ---- 2-step verification switched off for a while (0048; docs/decisions.md TF1-TF6; Auth\SecondStep): a UK time as typed, at most
        // 90 days ahead, one that happens once that day in the UK (`uk_time`; checked against the clock when it is saved). Empty: the code.
        'auth.totp_off_until' => ['pattern' => \CW\Auth\SecondStep::FORM, 'pattern_says' => 'a UK date and time like 2026-10-27 18:00 (empty: the code is asked for)',
            'uk_time' => true, 'ahead_max_days' => \CW\Auth\SecondStep::MAX_DAYS],
        // ---- HU (hide-unsold, 0051; docs/decisions.md HU1): the matching lists leave out a website product not matched yet that sold nothing in
        // the last year and was first seen more than a year ago. On (365 days) or off (0): the stores send the units of a year and of 30 days
        // only, so no other number of days can be known (a day count would otherwise be 0 to 120).
        'mapping.hide_unsold_days' => ['in' => [0, 365]],
        // ---- end of HU
    ];

    /**
     * --- Pack C follow-up (0040; docs/decisions.md PC19): a setting that is not used while a switch is off => that switch. The Settings
     * list greys it and says so; its value is kept for when the switch is on again.
     */
    public const UNUSED_WHEN_OFF = ['invoices.price_tolerance_pct' => 'invoices.check_price', 'invoices.price_tolerance_gbp' => 'invoices.check_price'];

    /**
     * Settings whose value is a secret (review finding 6 of 14 Oct 2026: a chat channel's incoming address carries its token). get()
     * gives the value whole, to the code that uses it; all() (the Settings pages, bin/settings.php) shows only its scheme and host
     * (masked()); the setting's history (config_change) and the audit log keep a fingerprint, never the value (fingerprint(), applied by
     * Admin\ConfigHistory to the live row and every version alike, so the nightly K checks still compare). A form sent back with the
     * masked value as it was drawn keeps the value (change()).
     */
    public const SECRET = ['alerts.webhook_url'];

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET, true);
    }

    /** What a secret setting shows: "https://hooks.example.com/…" ('' when not set). */
    public static function masked(mixed $value): string
    {
        $v = (string) ($value ?? '');
        if ($v === '') {
            return '';
        }
        $parts = parse_url($v);
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        return ($host === '' ? '' : ($parts['scheme'] ?? 'https') . '://' . $host) . '/…';
    }

    /** A secret setting's stored JSON text as the history keeps it: "" stays "", any other value its sha256 fingerprint. */
    public static function fingerprint(string $json): string
    {
        return trim($json) === '""' ? $json : Idempotency::json('hidden:sha256:' . substr(hash('sha256', $json), 0, 16));
    }

    /** The default rule of a day count (a key ending in `_days`). */
    public const DAYS_RULE = ['min' => 0, 'max' => 120];

    /** @var array<string, array<string, mixed>>|null setting_key => row */
    private ?array $rows = null;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The typed value of a setting: string|int|decimal string|bool|'Y-m-d' (null for an unset int, decimal or date; ''
     * for an unset string or text). An unknown key is a programming error (\LogicException).
     */
    public function get(string $key): mixed
    {
        $row = $this->rows()[$key] ?? throw new \LogicException("unknown setting {$key}");
        return self::decode((string) $row['value_type'], (string) $row['value_json']);
    }

    /** Whether the setting exists (a later task's key on an older schema does not). */
    public function has(string $key): bool
    {
        return isset($this->rows()[$key]);
    }

    /**
     * The company that buys and owns the warehouse stock (decision 9, provisional): what a PO letterhead prints, from the
     * version of company_profile in use (CW\Company\CompanyDetails::company(), read on every call, never cached). Empty
     * strings until someone provides them; confirmed false until someone confirms them; vat_registered null until someone
     * says; version 0 when there is no version at all.
     *
     * @return array{legal_name: string, trading_name: string, address: string, company_number: string, vat_number: string, phone: string, email: string, delivery_address: string, confirmed: bool, vat_registered: ?bool, version: int}
     */
    public function company(): array
    {
        return (new CompanyDetails($this->db))->company();
    }

    /**
     * Every setting for the screen and the CLI, key order: key, type, value (typed), display (text), provisional,
     * decision, description, updated_actor, updated_at.
     *
     * @return list<array{key: string, type: string, value: mixed, display: string, provisional: bool, decision: ?string, description: string, updated_actor: string, updated_at: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rows() as $key => $r) {
            $out[] = self::shown((string) $key, $r);
        }
        return $out;
    }

    /**
     * Every setting as all() gives it, with the version of its history that value is (`version`: what a form drawn with it sends as
     * `seen`; 0 when it has none), the value and its version read in ONE statement, so another person's change between two reads never
     * pairs the new value with the old version (docs/decisions.md W13.21: the row's valid save was refused as changed meanwhile). Read
     * now, never from the rows read before.
     *
     * @return list<array{key: string, type: string, value: mixed, display: string, provisional: bool, decision: ?string, description: string, updated_actor: string, updated_at: string, version: int}>
     */
    public function allWithVersions(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT s.setting_key, s.value_type, CAST(s.value_json AS CHAR) AS value_json, s.provisional, s.decision, s.description, '
            . "s.updated_actor, s.updated_at, (SELECT MAX(c.version) FROM config_change c WHERE c.subject_type = 'setting' AND c.subject_key = s.setting_key) AS version "
            . 'FROM app_setting s ORDER BY s.setting_key') as $r) {
            $out[] = self::shown((string) $r['setting_key'], $r) + ['version' => (int) ($r['version'] ?? 0)];
        }
        return $out;
    }

    /**
     * A setting's row as the screen and the CLI get it (all()).
     *
     * @param array<string, mixed> $r an app_setting row
     * @return array{key: string, type: string, value: mixed, display: string, provisional: bool, decision: ?string, description: string, updated_actor: string, updated_at: string}
     */
    private static function shown(string $key, array $r): array
    {
        $value = self::decode((string) $r['value_type'], (string) $r['value_json']);
        return [
            'key' => $key,
            'type' => (string) $r['value_type'],
            'value' => self::isSecret($key) && $value !== '' ? self::masked($value) : $value,
            'display' => self::isSecret($key) && $value !== '' ? self::masked($value) : self::display($value),
            'provisional' => (int) $r['provisional'] === 1,
            'decision' => $r['decision'] === null ? null : (string) $r['decision'],
            'description' => (string) $r['description'],
            'updated_actor' => (string) $r['updated_actor'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    /** A typed value as people read it: true/false, '' as "(not set)". */
    public static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null, $value === '' => '(not set)',
            default => (string) $value,
        };
    }

    /**
     * Parses what a person typed for a setting of $type (bin/settings.php), or throws CwException 400 bad_value:
     *   int      ^-?\d{1,9}$
     *   decimal  ^\d{1,8}(\.\d{1,6})?$ (returned as the string given)
     *   bool     true | false
     *   string   at most 255 characters, one line
     *   text     at most 4000 characters, line breaks allowed (CRLF read as LF)
     *   date     a valid Y-m-d
     * An empty value is "not set" for string, text, int, decimal and date ('' / null); a bool is always true or false.
     */
    public static function parse(string $type, string $raw): mixed
    {
        $bad = static fn (string $why): CwException => new CwException('bad_value', $why, 400, ['type' => $type]);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw $bad('the value is not valid UTF-8 text');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            throw $bad('the value contains control characters');
        }
        switch ($type) {
            case 'string':
                if (preg_match('/[\r\n]/', $raw) === 1) {
                    throw $bad('a string setting is one line (use a text setting for several)');
                }
                $v = trim($raw);
                if (mb_strlen($v) > self::STRING_MAX) {
                    throw $bad('a string setting is at most ' . self::STRING_MAX . ' characters');
                }
                return $v;
            case 'text':
                $v = trim(str_replace("\r\n", "\n", $raw));
                if (str_contains($v, "\r")) {
                    throw $bad('a text setting uses line feeds, not bare carriage returns');
                }
                if (mb_strlen($v) > self::TEXT_MAX) {
                    throw $bad('a text setting is at most ' . self::TEXT_MAX . ' characters');
                }
                return $v;
            case 'int':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^-?\d{1,9}$/D', $v) !== 1) {
                    throw $bad('an int setting is a whole number of at most 9 digits');
                }
                return (int) $v;
            case 'decimal':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^\d{1,8}(\.\d{1,6})?$/D', $v) !== 1) {
                    throw $bad('a decimal setting is a number like 0.50 (at most 8 digits before the point and 6 after, no sign)');
                }
                return $v;
            case 'bool':
                $v = strtolower(trim($raw));
                if ($v !== 'true' && $v !== 'false') {
                    throw $bad('a bool setting is true or false');
                }
                return $v === 'true';
            case 'date':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1
                    || \DateTimeImmutable::createFromFormat('!Y-m-d', $v, Clock::utc())?->format('Y-m-d') !== $v) {
                    throw $bad('a date setting is a real date, YYYY-MM-DD');
                }
                return $v;
            default:
                throw new \InvalidArgumentException("unknown setting type {$type}");
        }
    }

    /**
     * The key-specific rule of a setting (RULES; `_days` keys 0..120), checked on a parsed value: 400 bad_value.
     * Needs the database for `vat_code`.
     */
    public function checkRule(string $key, mixed $value): void
    {
        $rule = self::RULES[$key] ?? (str_ends_with($key, '_days') ? self::DAYS_RULE : []);
        if ($rule === [] || $value === null || $value === '') {
            return;
        }
        $bad = static fn (string $why): CwException => new CwException('bad_value', "{$key}: {$why}", 400, ['key' => $key]);
        if (isset($rule['min']) || isset($rule['max'])) {
            if (is_int($value)) {
                if (isset($rule['min']) && $value < (int) $rule['min'] || isset($rule['max']) && $value > (int) $rule['max']) {
                    throw $bad("must be from {$rule['min']} to {$rule['max']}");
                }
            } elseif (is_string($value) && preg_match('/^\d+(\.\d+)?$/D', $value) === 1) {
                if (isset($rule['min']) && self::cmpDecimal($value, (string) $rule['min']) < 0
                    || isset($rule['max']) && self::cmpDecimal($value, (string) $rule['max']) > 0) {
                    throw $bad("must be from {$rule['min']} to {$rule['max']}");
                }
            }
        }
        if (($rule['vat_code'] ?? false) === true) {
            if ($this->db->value('SELECT 1 FROM vat_code WHERE code = ? AND is_active = 1', [(string) $value]) === null) {
                throw $bad('there is no active VAT code ' . mb_substr((string) $value, 0, 8));
            }
        }
        if (($rule['email'] ?? false) === true && filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
            throw $bad('must be an e-mail address');
        }
        if (isset($rule['in']) && !in_array($value, $rule['in'], true)) {
            throw $bad('must be one of ' . implode(', ', $rule['in']));
        }
        if (isset($rule['pattern']) && preg_match($rule['pattern'], (string) $value) !== 1) {
            throw $bad('must be ' . ($rule['pattern_says'] ?? 'in the expected form'));
        }
        if (($rule['uk_time'] ?? false) === true) {
            // A UK time that happens once that day (UkTime: not in the hour the clocks go back or forward), and not too far ahead (TF2).
            $at = \CW\Auth\SecondStep::moment((string) $value)
                ?? throw $bad('must be a UK date and time that happens once that day, like 2026-10-27 18:00 (not in the hour the clocks change)');
            if (isset($rule['ahead_max_days']) && $at > Clock::now()->modify('+' . (int) $rule['ahead_max_days'] . ' days')) {
                throw $bad("must be at most {$rule['ahead_max_days']} days ahead");
            }
        }
        if (isset($rule['list_in'])) {
            $items = explode(',', (string) $value);
            if (array_diff($items, $rule['list_in']) !== [] || count(array_unique($items)) !== count($items)) {
                throw $bad('must be some of ' . implode(', ', $rule['list_in']) . ', separated by commas, each once');
            }
        }
        // Against other settings (whole numbers): at least / at most their current values.
        foreach (['at_least' => 1, 'at_most' => -1] as $kind => $sign) {
            foreach ($rule[$kind] ?? [] as $other) {
                $o = $this->has($other) ? $this->get($other) : null;
                if (is_int($o) && is_int($value) && ($value <=> $o) === -$sign) {
                    throw $bad('must be ' . ($sign === 1 ? 'at least' : 'at most') . " {$other} ({$o})");
                }
            }
        }
    }

    /**
     * Changes one setting the way bin/settings.php does (a system caller): $confirm also marks it as agreed by the owner
     * (provisional = 0); otherwise the agreed mark stays as it is. See change().
     *
     * @return array{changed: bool, before: mixed, after: mixed, version: int}
     */
    public function set(Caller $caller, string $key, string $raw, string $reason, bool $confirm = false): array
    {
        return $this->change($caller, $key, $raw, $reason, $confirm ? true : null, null);
    }

    /**
     * Changes one setting (the Settings page and bin/settings.php; Y4). $raw is parsed for the key's type and checked against its
     * rule; $agreed true marks the value agreed by the owner (provisional = 0), false marks it not agreed yet, null keeps the mark.
     * $seen: the setting's history version the form was drawn with (409 changed_meanwhile when someone changed it since; null:
     * no check, the CLI). A staff caller needs settings.manage (an admin or a reviewer, re-read inside the transaction); a CLI tool
     * (system caller) may. Writes value_json, provisional, updated_actor (the caller) and updated_at, the next config_change
     * version (`change`, or `agree` / `unagree` when only the mark moved) and audit setting.change {key, before, after, reason,
     * version, loosened when an approval rule became looser}. Nothing is written when neither the value nor the mark changes. A
     * company.* key is refused (400 company_details): the company details have their own screen and history since 0013 (I91).
     * Loosening an approval rule (Admin\ApprovalRules::loosens: a switch off, a smaller spot check) needs a Reviewer (403
     * loosen_needs_reviewer, review finding I1); tightening it, or only marking it agreed, needs settings.manage as any change.
     *
     * @return array{changed: bool, before: mixed, after: mixed, version: int}
     */
    public function change(Caller $caller, string $key, string $raw, string $reason, ?bool $agreed = null, ?int $seen = null): array
    {
        if (str_starts_with($key, 'company.')) {
            throw new CwException('company_details', 'the company details are no longer settings: a reviewer adds, changes and confirms them on the '
                . 'Company details screen (Reference > Company details, ' . self::COMPANY_SCREEN . '), which keeps every version', 400);
        }
        $reason = \CW\Admin\ConfigHistory::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $key, $raw, $reason, $agreed, $seen): array {
            \CW\Admin\ConfigHistory::authorise($db, $caller);
            $row = $db->one('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS value_json, provisional FROM app_setting WHERE setting_key = ? FOR UPDATE', [$key])
                ?? throw new CwException('unknown_setting', 'there is no setting ' . mb_substr($key, 0, 64), 400);
            \CW\Admin\ConfigHistory::checkSeen($db, 'setting', $key, $seen);
            $type = (string) $row['value_type'];
            $before = self::decode($type, (string) $row['value_json']);
            if (self::isSecret($key) && $before !== '' && trim($raw) === self::masked($before)) {
                $raw = (string) $before; // the form as it was drawn (the masked value): the secret stays as it is
            }
            $after = self::parse($type, $raw);
            $this->checkRule($key, $after);
            $json = self::encode($type, $after);
            $was = (int) $row['provisional'];
            $provisional = $agreed === null ? $was : ($agreed ? 0 : 1);
            $version = \CW\Admin\ConfigHistory::version($db, 'setting', $key);
            if ($before === $after && $provisional === $was) {
                return ['changed' => false, 'before' => $before, 'after' => $after, 'version' => $version];
            }
            $loosened = \CW\Admin\ApprovalRules::loosens($key, $before, $after);
            if ($loosened) {
                \CW\Admin\ApprovalRules::authoriseLoosening($db, $caller);
            }
            $old = \CW\Admin\ConfigHistory::state($db, 'setting', $key);
            $db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ?, updated_at = NOW(6) WHERE setting_key = ?',
                [$json, $provisional, $caller->actor, $key]);
            $new = \CW\Admin\ConfigHistory::state($db, 'setting', $key) ?? throw new \LogicException("setting {$key} vanished");
            $action = $before !== $after ? 'change' : ($provisional === 0 ? 'agree' : 'unagree');
            $v = \CW\Admin\ConfigHistory::record($db, $caller, 'setting', $key, $action, $old ?? $new, $new, $reason);
            $shown = static fn (mixed $x): mixed => self::isSecret($key) ? json_decode(self::fingerprint(self::encode($type, $x)), true) : $x;
            Audit::write($db, $caller, 'setting.change', 'app_setting', $key, null, ['key' => $key, 'before' => $shown($before), 'after' => $shown($after), 'reason' => $reason,
                'version' => $v['version']] + ($provisional !== $was ? ($provisional === 0 ? ['confirmed' => true] : ['agreed' => false]) : [])
                + ($loosened ? ['loosened' => true] : []));
            $this->rows = null;
            return ['changed' => true, 'before' => $before, 'after' => $after, 'version' => $v['version']];
        });
    }

    /**
     * A bool setting read straight from the database (the approval switches, Admin\ApprovalRules): $default when the row does not
     * exist (a schema before 0019) or is not a bool. Read on every call: a switch changed on the screen applies to the next action.
     */
    public static function flag(Db $db, string $key, bool $default): bool
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'bool'", [$key]);
        return $v === null ? $default : trim((string) $v) === 'true';
    }

    /**
     * A list setting (a `list_in` rule) read straight from the database: its values, in the rule's order; $default when the row
     * does not exist (a schema before its migration). An empty value is an empty list. A stored value the rule no longer allows is
     * left out (fail closed: a list of what is allowed).
     *
     * @param list<string> $default
     * @return list<string>
     */
    public static function listOf(Db $db, string $key, array $default): array
    {
        $allowed = self::RULES[$key]['list_in'] ?? throw new \LogicException("{$key} is not a list setting");
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'", [$key]);
        if ($v === null) {
            return $default;
        }
        $text = json_decode((string) $v, false, 4);
        $items = is_string($text) && $text !== '' ? explode(',', $text) : [];
        return array_values(array_filter($allowed, static fn (string $a): bool => in_array($a, $items, true)));
    }

    /** An int setting read straight from the database: $default when the row does not exist, is not an int or is not set. */
    public static function number(Db $db, string $key, int $default): int
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'int'", [$key]);
        return $v === null || preg_match('/^-?\d{1,9}$/D', trim((string) $v)) !== 1 ? $default : (int) trim((string) $v);
    }

    /** The JSON a typed value is stored as ("" for not set; a decimal as a JSON string). */
    public static function encode(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? 'true' : 'false',
            'int' => $value === null ? '""' : (string) (int) $value,
            default => Idempotency::json($value === null ? '' : (string) $value),
        };
    }

    /** The typed value of a stored JSON text (decode of encode(), plus seeded JSON numbers for decimals). */
    public static function decode(string $type, string $json): mixed
    {
        $v = json_decode($json, false, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        return match ($type) {
            'bool' => $v === true,
            'int' => $v === '' || $v === null ? null : (int) $v,
            'decimal' => $v === '' || $v === null ? null : (is_string($v) ? $v : trim($json)),
            'date' => $v === '' || $v === null ? null : (string) $v,
            default => $v === null ? '' : (string) $v,
        };
    }

    /** <0, 0, >0 for two non-negative decimal strings (no floats). */
    public static function cmpDecimal(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $ai = ltrim($ai, '0');
        $bi = ltrim($bi, '0');
        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) <=> strlen($bi);
        }
        $w = max(strlen($af), strlen($bf));
        return strcmp($ai . str_pad($af, $w, '0'), $bi . str_pad($bf, $w, '0')) <=> 0;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->db->all('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS value_json, provisional, decision, description, updated_actor, updated_at '
                . 'FROM app_setting ORDER BY setting_key') as $r) {
                $this->rows[(string) $r['setting_key']] = $r;
            }
        }
        return $this->rows;
    }
}
