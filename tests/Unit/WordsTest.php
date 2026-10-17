<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Documents\Document;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\OneAtATime;
use CW\Mapping\Proposals;
use CW\Matching\Band;
use CW\Matching\Form;
use CW\Matching\Veto;
use CW\Movements;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Stock;
use CW\Suppliers\Suppliers;
use CW\Ui\Compare;
use CW\Ui\Controller\ReorderController;
use CW\Ui\Controller\ReviewController;
use CW\Ui\Controller\SamplesController;
use CW\Ui\Duplicates;
use CW\Ui\Html;
use CW\Ui\Queries;
use CW\Ui\ReservationViews;
use CW\Ui\Sections;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The wording of the staff screens (Ui\Words, plan /root/cw_work/ui_clarity/plan.md §1 and §7, owner decision 7 Oct 2026):
 * every code the system has gets a plain word, the owner's corrections of the plan hold, and no word breaks the writing
 * rules (no codes, no "UTC", no phase codes, no server commands, no second account).
 */
final class WordsTest extends TestCase
{
    /** @return array<string, list<string>> group => every code of the system it must name */
    private static function codes(): array
    {
        $migration = static function (string $file, string $column): array {
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . $file);
            self::assertSame(1, preg_match('/\b' . preg_quote($column, '/') . '\s+ENUM\(([^)]*)\)/', $sql, $m), "{$file} {$column}");
            return array_map(static fn (string $v): string => trim($v, " '\n"), explode(',', $m[1]));
        };
        return [
            'BAND' => Proposals::BANDS,
            'BAND_TITLE' => Proposals::BANDS,
            'BAND_HELP' => Proposals::BANDS,
            'ROLE' => Permissions::ROLES,
            'ROLE_PLURAL' => Permissions::ROLES,
            'ROLE_HELP' => Permissions::ROLES,
            'ROLE_GROUP' => array_keys(Permissions::ROLE_GROUPS),
            'DOC_STATUS' => Document::STATUSES,
            'PO_STATE' => ['draft', 'awaiting_approval', ...PurchaseOrders::STATES],
            'SEND_VIA' => array_keys(PurchaseOrders::SEND_VIA),
            'REASON' => ['not_needed', 'supplier_cannot_supply', 'entered_in_error', 'duplicate', 'po_amended', 'other'],
            'REASON_USE' => array_values(\CW\Admin\ReasonCodes::USES),
            'RESET_KIND' => \CW\Staff\RoleRequests::KINDS,
            'SUPPLIER_STATUS' => Suppliers::STATUSES,
            'CHECK_REASON' => ['all_documents', 'over_limit', 'positive_without_supplier_doc', 'over_size', ...Suppliers::APPROVAL_REASONS, 'import_route', 'supplier_changed',
                'company_changed'],
            'CHECK_KIND' => $migration('0008_documents.sql', 'kind'),
            'REVIEW_STATE' => $migration('0008_documents.sql', 'review_state'),
            'TASK_STATE' => ['open', 'approved', 'rejected', 'withdrawn'],
            'SAMPLE_STATE' => array_keys(SamplesController::STATE_LABELS),
            'ACTION' => DecisionService::ACTIONS,
            'DECISION_STATE' => $migration('0004_matching.sql', 'state'),
            'LISTING_STATUS' => $migration('0004_matching.sql', 'prev_status'),
            'NEEDS_SECOND' => ['protected_sku', 'units_per_item', 'previously_rejected', 'merge', 'counted_item', 'bulk'],
            'POLICY' => Stock::POLICIES,
            'STOCK' => Stock::BUCKETS,
            'LANE' => Queries::LANES,
            'AI' => Band::OUTCOMES,
            'FLAG' => [...Veto::SOFT_FLAGS, ...Band::CONFLICT_FLAGS],
            'VETO' => Veto::CODES,
            'REORDER_FLAG' => array_keys(ReorderController::FLAG_TEXT),
            'SECTION' => array_keys(Sections::MAP),
            'SECTION_DESC' => array_keys(Sections::MAP),
            'MENU' => array_merge(...array_map(static fn (array $s): array => array_keys($s['tabs']), array_values(Sections::MAP))),
            'MENU_HELP' => array_keys(array_merge(...array_map(static fn (array $s): array => array_filter($s['tabs'], static fn (array $t): bool => !isset($t['soon'])),
                array_values(Sections::MAP)))),
            'SEGMENT' => ['review', 'samples', 'pending', 'reorder', 'brands', 'anomalies', 'sales_history', 'receiving', 'bench', 'incidents', 'transfers', 'releases',
                'accounts', 'settings', 'series',
                'integrity',
                'counts', 'count_sheets', 'count_differences',
                'sales_orders', 'sales_dispatch', 'sales_returns', // the last three: pack D
                // Pack F (PF5): Approvals › From the stores
                'queue_suspense', 'queue_corrections', 'queue_modes', 'queue_oversold',
                // --- Pack G
                'reorder_suppliers', 'low_stock_levels',
                // --- end of Pack G
                ],
            'NEW' => ['orders', 'receiving', 'suppliers', 'people', 'warehouses', 'reasons', 'stock_in', 'stock_out', 'transfers', 'releases', 'adjustments',
                'counts'],
            'DOC_TYPE' => array_values(\CW\StockOps\StockOpHandler::KINDS),
            'DOC_TYPES' => array_values(\CW\StockOps\StockOpHandler::KINDS),
            'STOCK_FILE' => array_keys(\CW\StockOps\StockOps::FILE_ROLES),
            'RESV_STATE' => ReservationViews::STATES,
            'RESV_UNIT' => ReservationViews::UNIT_STATES,
            'RESV_GROUP' => ReservationViews::GROUPS,
            'RESV_CHIP' => ReservationViews::GROUPS,
            'RESV_ORIGIN' => \CW\Reservations::ORIGINS,
            // ---- Pack D (docs/decisions.md PD3-PD7) ----
            'SALE_STATE' => \CW\Ui\SalesViews::STATES,
            'SALE_ORIGIN' => \CW\Ui\SalesViews::ORIGINS,
            'SALE_RETURN_KIND' => \CW\Ui\SalesViews::KINDS,
            'DISPATCH_CHIP' => ['late', 'on_time'],
            'SALE_RESTOCK' => ['yes', 'no', 'none'],
            'SALE_RESTOCK_TEXT' => ['returned', 'cancelled', 'cancelled_held', 'verify_open', 'verify_resolved', 'verify_dismissed', 'unlinked', 'unknown'],
            // ---- end of pack D ----
            'COMING_LATER' => array_column(Permissions::COMING_LATER, 'key'),
            'BADGE' => [...Sections::badgeNames(), 'section'],
            'TAB' => ['more'],
            'ERROR_TITLE' => ['400', '403', '404', '405', '409', '413', '422', '429', '500', '503'],
            'ERROR' => ['csrf', 'lead_only', 'not_found', 'method_not_allowed', 'too_large', 'form_truncated', 'bad_form_key', 'idempotency_key_reused',
                'unavailable', 'busy', 'internal', 'unknown_staff', 'unknown_queue'],
            // Pack F (PF1-PF9): the stores' queues and the relay
            'QUEUE_STATE' => \CW\Queues\QueueRules::STATUSES,
            'RELAY_KIND' => \CW\Relay\RelayLines::KINDS,
            'OVERSELL_RESOLUTION' => \CW\Queues\Oversells::RESOLUTIONS,
        ];
    }

    public function testEveryCodeOfTheSystemHasAWord(): void
    {
        foreach (self::codes() as $group => $codes) {
            self::assertNotSame([], $codes, $group);
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, $code), "{$group}: no word for `{$code}`");
                self::assertNotSame('', trim(Words::of($group, $code)), "{$group} {$code}");
            }
        }
        self::assertSame(count(Permissions::ROLES), count(array_unique(Words::JOB_ORDER)));
        self::assertEqualsCanonicalizing(Permissions::ROLES, Words::JOB_ORDER, 'every job has its place in a sentence');
    }

    public function testEveryToneIsKnownAndNamesAWordOfItsGroup(): void
    {
        foreach (Words::TONE as $group => $tones) {
            foreach ($tones as $code => $tone) {
                self::assertContains($tone, Words::TONES, "{$group} {$code}");
                self::assertTrue(Words::has($group, (string) $code), "{$group} {$code}: a tone for a code without a word");
            }
        }
        self::assertSame('done', Words::tone('PO_STATE', 'received'));
        self::assertSame('info', Words::tone('PO_STATE', 'nonsense'));
        self::assertSame('info', Words::tone('NOT_A_GROUP', 'x'));
    }

    public function testOfFallsBackToTheCodeMadeReadableAndRefusesAnUnknownGroup(): void
    {
        self::assertSame('Strong match', Words::of('BAND', 'Key'));
        self::assertSame('Brand new flag', Words::of('FLAG', 'brand_new_flag'));
        self::assertSame('', Words::of('FLAG', null));
        self::assertSame('', Words::of('FLAG', ''));
        self::assertFalse(Words::has('FLAG', 'brand_new_flag'));
        $this->expectException(\InvalidArgumentException::class);
        Words::of('THING_THAT_IS_NOT_A_GROUP', 'x');
    }

    /** The owner's corrections of the plan (7 Oct 2026). */
    public function testTheOwnersCorrectionsHold(): void
    {
        // a: the owner's account keeps Admin + Reviewer + Matching lead, with the strip; never a second account.
        self::assertSame('Your Reviewer and Matching lead jobs are switched off because this account also has Admin. Ask Fazil to take Admin off this account.',
            Words::switchedOffNote(['admin', 'mapping_lead', 'reviewer']));
        self::assertNull(Words::switchedOffNote(['mapping_lead', 'reviewer']));
        self::assertSame('Your Buyer job is switched off because this account also has Admin. Ask Fazil to take Admin off this account.',
            Words::switchedOffNote(['admin', 'buyer']));
        // b: "count" only for counting the shelves.
        self::assertSame('Website uses its own stock (not linked yet)', Words::POLICY['legacy']);
        self::assertSame('Website sells warehouse stock only', Words::POLICY['strict']);
        self::assertSame('Website already sells warehouse stock', Words::NEEDS_SECOND['protected_sku']);
        self::assertSame('Stock was counted in the warehouse', Words::NEEDS_SECOND['counted_item']);
        foreach ([Words::POLICY, Words::BAND, Words::BAND_HELP, Words::LISTING_STATUS, Words::HELP] as $group) {
            foreach ($group as $code => $text) {
                self::assertDoesNotMatchRegularExpression('/\bcount(ed|s)?\b/i', (string) $text, "{$code}: 'count' is only for shelf counts");
            }
        }
        // c: Strong match, and Clues disagree covers a rule against the barcode's product (veto_on_key).
        self::assertStringStartsWith('Same barcode, or copied from the other website, and the AI agrees', Words::BAND_HELP['Key']);
        self::assertStringContainsString('a rule says cannot be right', Words::BAND_HELP['Conflict']);
        self::assertArrayHasKey('veto_on_key', Words::BAND_REASON);
        self::assertStringContainsStringIgnoringCase('Same barcode, or copied from the other website, and the AI agrees', Words::HELP['how_sure']);
        // d, e, f
        self::assertSame('Blocked: a person must confirm its product card. It is still on sale on the website: take it off by hand.', Words::REORDER_FLAG['card_blocked']);
        self::assertSame('It is worked out again each time new sales are loaded. If it looks old, ask Fazil.', Words::REORDER['demand']);
        self::assertNotSame(Words::PO['confirm'], Words::PO['confirm_over_limit'], 'over the approval limit the button does not say "Confirm order"');
        self::assertStringContainsString('reviewer', Words::PO['confirm_over_limit']);
        self::assertStringContainsString('reviewer', Words::PAGE_INTRO['order_draft'][1]);
        // The plan's provisional band names and the strip text.
        self::assertSame(['Strong match', 'Likely match – check it', 'New product', 'Not sure – you choose', 'Clues disagree', 'Renamed range'],
            array_values(Words::BAND));
        self::assertSame('TEST SYSTEM: nothing here is real', Words::UI['test_system']);
        // compare.md §2.1: "Not a match" in a spot check has a second step that says it stops the bulk link for good.
        self::assertSame('This stops the bulk link for good.', Words::SPOT['no_confirm_title']);
    }

    /** Writing rules 2, 10, 12 (plan §7): no codes, no "UTC", no phase codes, no server commands or docs in any word. */
    public function testNoWordBreaksTheWritingRules(): void
    {
        $all = [];
        foreach ((new \ReflectionClass(Words::class))->getReflectionConstants() as $c) {
            if (in_array($c->getName(), ['TONE', 'TONES', 'JOB_ORDER', 'SITE'], true)) {
                continue;
            }
            $v = $c->getValue();
            if (is_string($v)) {
                $all[$c->getName()] = $v;
                continue;
            }
            if (is_array($v)) {
                array_walk_recursive($v, static function (mixed $text, mixed $key) use (&$all, $c): void {
                    if (is_string($text)) {
                        $all[$c->getName() . ' ' . $key . ' ' . count($all)] = $text;
                    }
                });
            }
        }
        self::assertGreaterThan(400, count($all));
        foreach ($all as $where => $text) {
            self::assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $text, "{$where}: a code on the screen");
            self::assertStringNotContainsString('UTC', $text, $where);
            self::assertDoesNotMatchRegularExpression('/Phase I-|\bbin\/|docs\/|\.php\b/', $text, $where);
            self::assertDoesNotMatchRegularExpression('/second account|two accounts|second login/i', $text, "{$where}: correction a");
            self::assertSame(trim($text), $text, $where);
        }
        foreach (Words::PAGE_INTRO as $page => [$what, $todo]) {
            self::assertMatchesRegularExpression('/[.?]$/', $what, $page);
            self::assertTrue($todo === '' || preg_match('/[.:]$/', $todo) === 1, $page);
        }
        foreach (['how_sure', 'set_aside', 'stock', 'join_undo', 'second_ok', 'admin_off', 'spot_check', 'sale_uses', 'stock_rule'] as $help) {
            self::assertArrayHasKey($help, Words::HELP, 'the plan\'s "?" texts H1-H8');
        }
    }

    public function testJobsAndWhoMayOpenAPage(): void
    {
        self::assertSame('Admin · Matching lead (off) · Reviewer (off)', Words::roles(['admin', 'mapping_lead', 'reviewer']));
        self::assertSame('Buyer · Matcher', Words::roles(['buyer', 'mapper']));
        self::assertSame('no job yet', Words::roles([]));
        self::assertSame('Reviewers', Words::whoCan('documents.review'));
        self::assertSame('Buyers and Purchasing managers', Words::whoCan('doc.PO.post'));
        self::assertSame('Admins and Auditors', Words::whoCan('staff.view'));
        self::assertSame('everyone with a job', Words::whoCan('catalogue.view'));
        self::assertSame('a, b and c', Words::andList(['a', 'b', 'c']));
        self::assertSame('a', Words::andList(['a']));
        self::assertSame('', Words::andList([]));
        self::assertSame('', Words::comingLater(['purchasing_desk']), 'receiving is built (IM6), supplier invoices and returns (pack C), hand-overs (pack H)');
        self::assertSame('', Words::comingLater(['goods_in']), 'the goods-in bench is built: nothing comes later for goods in');
        self::assertSame('', Words::comingLater(['buyer']));
        self::assertSame('Our name, numbers and addresses as printed on every purchase order. You can look; Reviewers change this.', Words::intro('company', 'Reviewers'));
        self::assertSame('', Words::intro('no-such-page'));
        self::assertSame('Waiting for me', Words::title('reviews'));
        self::assertSame('Purchase Orders', Words::title('orders'));
    }

    public function testErrorWords(): void
    {
        self::assertSame('Page not found', Words::errorTitle(404));
        self::assertSame('Something went wrong', Words::errorTitle(502));
        self::assertSame('This did not work', Words::errorTitle(418));
        self::assertSame('You cannot do this', Words::errorTitle(403, true), 'a refused button press, not a page');
        self::assertSame('Page not found', Words::errorTitle(404, true));
        self::assertSame(Words::ERROR['csrf'], Words::error('csrf', 'the service text'));
        self::assertSame('the service text', Words::error('some_service_code', 'the service text'), 'service messages pass through (they are the API\'s)');
        self::assertStringContainsString('(over 2 MB)', Words::error('too_large', '', 2));
        self::assertSame('kept', Words::error('too_large', 'kept'), 'a text with numbers is filled in where it is made');
        foreach (Words::ERROR as $code => $text) {
            self::assertMatchesRegularExpression('/^[A-Z]/', $text, "{$code}: a capital letter (writing rule 7)");
            self::assertMatchesRegularExpression('/\.$/', $text, "{$code}: a full sentence");
        }
    }

    /** UK time and money (writing rules 9 and 10): "7 Oct 2026, 10:26", never "UTC"; "£10,500.00". */
    public function testUkTimeAndMoney(): void
    {
        self::assertSame('7 Oct 2026, 10:26', Html::when('2026-10-07 09:26:31.123456'), 'British Summer Time: UTC + 1');
        self::assertSame('7 Dec 2026, 09:26', Html::when('2026-12-07 09:26:00'), 'winter: UK time is UTC');
        self::assertSame('8 Oct 2026', Html::day('2026-10-07 23:30:00'), 'the UK date, not the UTC one');
        self::assertSame('31 Oct 2026', Html::day('2026-10-31 23:30:00'), 'after the clocks go back (25 Oct 2026) UK time is UTC');
        self::assertSame('7 Oct 2026', Html::day('2026-10-07'), 'a DATE is shown as it is');
        self::assertSame('7 Oct 2026', Html::when('2026-10-07'));
        self::assertSame('7 Oct 2026, 10:26', Html::when('2026-10-07 09:26'));
        self::assertSame('', Html::when(null));
        self::assertSame('', Html::day(''));
        self::assertSame('', Html::when('not a date'));
        self::assertSame('£10,500.00', Html::money('10500'));
        self::assertSame('£0.10', Html::money('0.1000'));
        self::assertSame('-£5.00', Html::money(-5));
        self::assertSame('£1,234,567.89', Html::money(1234567.891));
        self::assertSame('', Html::money(null));
        self::assertSame('', Html::money(''));
        self::assertSame('', Html::money('abc'));
    }

    /**
     * The start and settings pages (plan §6.3-6.8, 6.13, 6.31-6.36): every kind of record, review state, reason use and
     * direction, setting and refusal code the system has gets its words.
     */
    public function testEveryCodeOfTheStartAndSettingsPagesHasAWord(): void
    {
        $sql = static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . $file);
        self::assertGreaterThan(0, preg_match_all("/^ \\('([A-Z]+)','[A-Z]+','/m", $sql('0008_documents.sql'), $types));
        self::assertCount(8, $types[1]);
        foreach ($types[1] as $code) {
            self::assertTrue(Words::has('DOC_TYPE', $code), "DOC_TYPE {$code}");
            self::assertTrue(Words::has('DOC_TYPES', $code), "DOC_TYPES {$code}");
        }
        foreach (Document::REVIEW_STATES as $state) {
            self::assertTrue(Words::has('CHECK_STATE', $state), $state);
        }
        self::assertSame(1, preg_match("/applies_to\s+SET\(([^)]*)\)/", $sql('0008_documents.sql'), $set));
        self::assertSame(1, preg_match("/direction\s+ENUM\(([^)]*)\)/", $sql('0008_documents.sql'), $dir));
        foreach ([...explode(',', $set[1]), ...explode(',', $dir[1])] as $use) {
            self::assertTrue(Words::has('REASON_USE', trim($use, " '")), $use);
        }
        $keys = [];
        foreach (['0009_suppliers.sql', '0010_purchase_orders.sql', '0011_reorder.sql', '0017_receiving.sql', '0018_site_writer.sql', '0019_set_it_yourself.sql',
            '0021_mapping_bulk.sql', '0025_by_item_bulk.sql', '0026_new_variants.sql', '0027_arrival_check.sql', '0028_arrival_site_state.sql',
            '0029_stock_counts.sql',
            '0033_sales_dispatch_views.sql', // pack D
            '0031_items_families_repack.sql', // pack B
            '0032_supplier_invoices.sql', // pack C
            '0036_golive_queues_alerts.sql', // pack F
            '0034_reports_roles.sql', // Pack E
            // --- Pack G
            '0037_reorder_engine.sql',
            // --- end of Pack G
            '0038_handovers_company_duty.sql', // Pack H
            '0040_invoice_price_wins.sql', // pack C follow-up (PC19)
            '0041_incoming_stock.sql', // Pack J
            '0046_rollback_export.sql', // Pack RB
        ] as $file) {
            preg_match_all("/^ \\('([a-z_]+\.[a-z_.]+)','/m", $sql($file), $m);
            $keys = [...$keys, ...$m[1]];
        }
        self::assertGreaterThan(20, count($keys));
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, Words::SETTING, "the setting {$key} has a plain name");
            if (!str_starts_with($key, 'company.')) { // 0013 moved the company details to their own screen
                self::assertArrayHasKey($key, Words::SETTING_HELP, "the setting {$key} says what it does");
                self::assertNotSame('Other', Words::settingTopic($key), "the setting {$key} is listed under a topic");
            }
        }
        foreach (['own_document', 'own_supplier', 'own_change', 'admin_cannot_review', 'role_not_allowed'] as $code) {
            self::assertTrue(Words::has('REFUSAL', $code), $code);
        }
        foreach ([Words::RECORD_NOTICE, Words::COMPANY_NOTICE, Words::STAFF_NOTICE, Words::REFUSAL] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertMatchesRegularExpression('/^[A-Z"].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
    }

    /**
     * The matching pages (plan §6.9-6.12, 6.14-6.18): every kind of product, nicotine type, stock change, product origin,
     * identity field, action and spot-check problem has its words, and every notice and refusal is a full sentence.
     */
    public function testEveryCodeOfTheMatchingPagesHasAWord(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0004_matching.sql');
        self::assertSame(1, preg_match("/origin ENUM\\(([^)]*)\\)/", $sql, $origin));
        foreach ([
            'FORM_VALUE' => Form::ALL,
            'NIC_TYPE' => ['salt', 'freebase', 'zero', 'shortfill', 'nic_shot'],
            'MOVEMENT' => [...Movements::TYPES, ...Movements::DOCUMENT_TYPES, 'reserve', 'release', 'expire', 'commit', 'cancel', 'ship', 'unship', 'return', 'opening', 'trade_sale',
                'merge_in', 'merge_out', 'split_in', 'split_out'],
            'ACTION_DONE' => DecisionService::ACTIONS,
            'FIELD' => [...DecisionService::CARD_FIELDS, ...array_keys(Compare::LABELS), 'barcodes'],
            'ORIGIN' => array_map(static fn (string $v): string => trim($v, " '"), explode(',', $origin[1])),
            'SAMPLE_FIT' => ['sample_too_small', 'draw_not_reproducible', 'stratum_short'],
            'SAMPLE_RESULT' => ['waiting', 'passed', 'failed', 'unusable'],
            'FIELD_STATE' => ['same', 'differs', 'alike', 'unknown'],
            'MATCH_ERROR' => ['map_version_conflict', 'proposal_changed', 'proposal_closed', 'proposal_mismatch', 'lead_required', 'same_person', 'not_pending',
                'pending_second_exists', 'already_linked', 'no_change', 'name_required', 'bad_card', 'unknown_sku', 'sku_merged', 'reject_current_link',
                'no_open_proposal', 'bad_units', 'unknown_decision'],
            'DUP_ERROR' => ['map_version_conflict', 'proposal_changed', 'pending_second_exists', 'rejected_pair', 'protected', 'counted_meanwhile', 'not_merged',
                'former_merged_elsewhere', 'lead_required', 'idempotency_key_reused', 'split_chain', 'keeper_changed', 'form_incomplete', 'nothing_chosen',
                'elsewhere', 'confirm_needed'],
            'ERROR' => ['unknown_listing', 'unknown_item', 'unknown_group', 'unknown_sample', 'unknown_store', 'item_joined'],
            // By Item (U113-U122): a cell's states, the coverage filters, the picker's barcode marks, and the words its two pages
            // take by name.
            'PRODUCT_STORE' => ['matched', 'waiting', 'suggested', 'rejected', 'none'],
            'BY_PRODUCT' => [...Queries::COVERAGE, 'all', 'pick_barcode_same', 'pick_barcode_differs', 'pick_barcode_unknown', 'item', 'parent', 'more_one', 'more_many',
                'stores_matched', 'summary', 'map', 'pick_title', 'pick_suggested', 'pick_search', 'pick_search_first', 'pick_variant', 'pick_choose', 'rows', 'note', 'note_look'],
            // Bulk action (M46-M58): every reason a row is skipped for has its words, in the lists' words and in By Item's; By Item's
            // bar, preview and button have a word for each of its actions.
            'BULK_SKIP' => BulkDecisions::SKIPS,
            'BY_ITEM_SKIP' => BulkDecisions::SKIPS,
            'BY_ITEM_BULK' => [...BulkDecisions::ITEM_ACTIONS, ...array_merge(...array_map(static fn (string $a): array => ["title_{$a}", "summary_{$a}", "text_{$a}", "button_{$a}", "will_{$a}"],
                BulkDecisions::ITEM_ACTIONS)), 'summary_pending', 'summary_skipped', 'will_wait', 'will_skip', 'nothing_ticked', 'nothing_on', 'too_many_rows', 'undo_note', 'limit',
                'row_too_many', 'manual_note'],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, $code), "{$group}: no word for `{$code}`");
            }
        }
        foreach (array_keys(ReviewController::NOTICES) as $key) {
            self::assertTrue(Words::has('MATCH_NOTICE', $key), $key);
        }
        foreach ([Words::MATCH_NOTICE, Words::DUP_NOTICE, Words::MATCH_ERROR, Words::DUP_ERROR] as $texts) {
            foreach ($texts as $code => $text) {
                if (in_array($code, ['the_product', 'the_group'], true)) {
                    continue; // words inside a sentence
                }
                self::assertMatchesRegularExpression('/^[A-Z"%].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        foreach ([Words::FORM_VALUE, Words::POLICY, Words::NEEDS_SECOND, Words::LISTING, Words::DUPS, Words::QUEUE, Words::SPOT, Words::SAMPLE, Words::ITEM] as $texts) {
            foreach ($texts as $code => $text) {
                // A shelf count is the one meaning "count" keeps (correction b).
                $other = (string) preg_replace('/shelf counts?|stock (has been |was |not |last )?counted|stock counts/i', '', $text);
                self::assertDoesNotMatchRegularExpression('/\\bcount(ed|s)?\\b/i', $other, "{$code}: 'count' is only for shelf counts (correction b)");
            }
        }
        // compare.md §2.1: "Not a match" has a second step that says it stops the bulk link for good, and "Not sure" saves nothing.
        self::assertStringContainsString('for good', Words::SPOT['no_sub']);
        self::assertStringContainsString('Nothing is saved', Words::SPOT['unsure_sub']);
        self::assertStringContainsString('for good', Words::SPOT['does_no']);
    }

    /**
     * Stock › Reservations (docs/decisions.md RS4): the screen's states are the engine's own (0001_core.sql), each with its plain
     * words and its colour; the board's groups cover them; every part of "what became of it" has its words.
     */
    public function testByItemSpeaksOfItemsAndVariantsAsTheOwnerDoes(): void
    {
        // docs/decisions.md U122, the owner on 10 Oct 2026: "actually we need variant mapping not product ... each variant we have
        // treating item". On this screen and its picker a store's sellable option is a VARIANT, the warehouse record it is matched
        // to is an ITEM, and "product" names only the parent page of a variant ("Product: …"). The other screens keep their words.
        self::assertSame('By Item', Words::MENU['by_product']);
        self::assertSame('Every warehouse item, and the variant it is matched to on each store.', Words::MENU_HELP['by_product']);
        self::assertSame('Every warehouse item and the variant it is matched to on each store.', Words::PAGE_INTRO['by_product'][0]);
        self::assertSame(['Item', 'Stores matched', 'Match…', 'Choose', 'Variant'],
            [Words::BY_PRODUCT['item'], Words::BY_PRODUCT['stores_matched'], Words::BY_PRODUCT['map'], Words::BY_PRODUCT['pick_choose'], Words::BY_PRODUCT['pick_variant']]);
        // (`Marked not a match`, M59: a suggested cell whose every suggestion was marked wrong for the item before)
        self::assertSame(['Matched', 'Waiting for a second OK', 'Suggested', 'Marked not a match', 'Not on this store'], array_values(Words::PRODUCT_STORE));
        self::assertSame(['Product: Elux Legend 3500', '2 of 3', '+1 more variant', '+4 more variants'], [Words::say('BY_PRODUCT', 'parent', 'Elux Legend 3500'),
            Words::say('BY_PRODUCT', 'summary', 2, 3), Words::BY_PRODUCT['more_one'], Words::say('BY_PRODUCT', 'more_many', 4)]);
        self::assertSame(['Match CW-000041 on Alt Store', 'Suggested for this item', 'Other variants of Alt Store still waiting for a match'],
            [Words::say('BY_PRODUCT', 'pick_title', 'CW-000041', 'Alt Store'), Words::BY_PRODUCT['pick_suggested'], Words::say('BY_PRODUCT', 'pick_search', 'Alt Store')]);
        self::assertSame('See where it is matched', Words::ITEM['coverage_link']);
        $texts = array_merge(array_values(Words::BY_PRODUCT), array_values(Words::PRODUCT_STORE), Words::PAGE_INTRO['by_product'], Words::PAGE_INTRO['by_product_map'],
            [Words::MENU['by_product'], Words::MENU_HELP['by_product'], Words::ERROR['unknown_store'], Words::ERROR['item_joined'], Words::ITEM['coverage_link']]);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/website products?|warehouse products?|By Product/i', $text);
        }
        foreach (Words::BY_PRODUCT as $code => $text) {
            if ($code !== 'parent') {
                self::assertDoesNotMatchRegularExpression('/\bproducts?\b/i', $text, "{$code}: \"product\" is only the parent page of a variant");
            }
        }
        // The bulk action of this screen (U123-U130; the owner, 10 Oct 2026: "bulk preview and update option"): the same words. Its
        // bar, its preview, its button, its refusals and every reason a variant is skipped speak of items and variants only.
        self::assertSame(['Confirm suggested matches', 'Not a match…', 'Send back for matching…'], [Words::BY_ITEM_BULK['link'], Words::BY_ITEM_BULK['reject'], Words::BY_ITEM_BULK['send_back']]);
        self::assertSame(['Preview: confirm suggested matches', 'Yes, do these 12', 'Yes, these 3 are not a match'],
            [Words::say('BY_ITEM_BULK', 'title', Words::BY_ITEM_BULK['title_link']), Words::say('BY_ITEM_BULK', 'button_link', 12), Words::say('BY_ITEM_BULK', 'button_reject', 3)]);
        self::assertSame(['On all stores · 1 item ticked · 1 suggested variant', 'On Alt Store · 12 items ticked · 1,200 suggested variants'],
            [Words::say('BY_ITEM_BULK', 'scope', Words::BY_ITEM_BULK['all_stores_lower'], Words::BY_ITEM_BULK['selected_one'], Words::BY_ITEM_BULK['variants_one']),
                Words::say('BY_ITEM_BULK', 'scope', 'Alt Store', Words::say('BY_ITEM_BULK', 'selected_many', 12), Words::say('BY_ITEM_BULK', 'variants_many', 1200))],
            'the preview says one item and one variant in the singular');
        self::assertSame('12 will be matched · 3 will wait for a second OK · 5 will be skipped', implode(' · ', [Words::say('BY_ITEM_BULK', 'summary_link', 12),
            Words::say('BY_ITEM_BULK', 'summary_pending', 3), Words::say('BY_ITEM_BULK', 'summary_skipped', 5)]));
        self::assertSame(array_keys(Words::BULK_SKIP), array_keys(Words::BY_ITEM_SKIP), 'the same reasons, in the same order');
        // What a confirm from By Item leaves to a person (M59), in the reviewer's words; and a refusal's number is filled in.
        self::assertSame(['Elux Legend Blue Razz already has a variant on Alt Store: open it and decide.', 'It was marked not a match for this item before: open it and decide.'],
            [\CW\Ui\Controller\BulkController::why('item_on_store', ['item' => 'Elux Legend Blue Razz', 'store' => 'Alt Store'], 'BY_ITEM_SKIP'),
                \CW\Ui\Controller\BulkController::why('rejected_before', [], 'BY_ITEM_SKIP')]);
        foreach (['BULK_SKIP', 'BY_ITEM_SKIP'] as $group) {
            $said = \CW\Ui\Controller\BulkController::why('refused', ['code' => 'bad_units', 'message' => 'units_per_item must be 1..1000'], $group);
            self::assertStringContainsString('from 1 to 1,000', $said, $group);
            self::assertStringNotContainsString('%', $said, "{$group}: no text that still wants a value");
            // A refusal whose words want a value this page does not have is said in the service's own words.
            self::assertSame('Not changed: The name is too long.', \CW\Ui\Controller\BulkController::why('refused', ['code' => 'bad_card', 'message' => 'the name is too long'], $group));
        }
        foreach ([...array_values(Words::BY_ITEM_BULK), ...array_values(Words::BY_ITEM_SKIP), ...Words::PAGE_INTRO['by_item_preview'], ...Words::PAGE_INTRO['by_item_result'],
            Words::SETTING['mapping.by_item_page_size']] as $text) {
            self::assertDoesNotMatchRegularExpression('/\bproducts?\b|website|By Product/i', $text, 'items and variants, as the owner says');
        }
        foreach (Words::BY_ITEM_SKIP as $code => $text) {
            self::assertSame(substr_count(Words::BULK_SKIP[$code], '%s'), substr_count($text, '%s'), "{$code}: filled in from the same record");
        }
    }

    /**
     * The rows no bulk action takes, and what a match-strength list shows (docs/decisions.md M63, U139-U141): a word for each choice
     * of "Show" and for each kind of such a row; the line under the row says what it is and what to do: for a held-back one the
     * day and the name, and that its own page says why, never the hold's note (the stored notes are long rule texts; a build
     * decision of 12 Oct 2026); the notice above the list says how many of the page have no tick box, in the singular and the
     * plural, and leads to the ones a bulk action can take with how many there are. The same line is shown on New Variants, so it
     * names no "product". After the last row of a narrowed list the notice does not call the list done.
     */
    public function testTheListsSayWhichRowsNoBulkActionTakesAndWhatAListShows(): void
    {
        self::assertSame(Queries::SHOWS, array_keys(Words::QUEUE_SHOW), 'a word for each choice, in the filter\'s order');
        self::assertSame(['All', 'Ready for a bulk action', 'Held back', 'In a spot check', 'Found on arrival'], array_values(Words::QUEUE_SHOW));
        self::assertSame('Show', Words::QUEUE['show']);
        self::assertSame(['held', 'in_spot_check', 'on_arrival'], OneAtATime::KINDS,
            'the three kinds of such a row (the third since the check on arrival, M64); each has its own choice of "Show" (held, spot, arrival)');
        foreach (OneAtATime::KINDS as $kind) {
            self::assertTrue(Words::has('ONE_AT_A_TIME', $kind), "ONE_AT_A_TIME: no word for `{$kind}`");
            self::assertContains($kind, BulkDecisions::SKIPS, 'the kinds are the reasons a bulk action skips such a row for');
        }
        // The line under the row. A held-back one: the day and the name, and where to see why; the note itself is not printed (a
        // stored note is a rule text of some hundred characters), whatever it holds.
        $note = 'rule: [MEDIUM] model_number_differs: model/line numbers: EF - vs VPG 25000 (only EF: -; only VPG: 25000) | rule: [MEDIUM] norm_line_numbers_differ';
        $held = ['code' => 'held', 'detail' => ['reason' => $note, 'by' => 'Business owner', 'at' => '2026-10-06 09:15:00.000000'], 'sample_id' => 4, 'sample' => 'owner-1'];
        self::assertSame(['code' => 'held', 'link' => null, 'text' => 'Held back on 6 Oct 2026 (set aside by Business owner). Open it to see why and to decide it.'],
            ReviewController::oneAtATime($held));
        self::assertSame(ReviewController::oneAtATime($held), ReviewController::oneAtATime(['detail' => ['reason' => str_repeat('x ', 240)] + $held['detail']] + $held),
            'the same line whatever the note says and however long it is');
        self::assertStringContainsString('"' . $note . '" (set aside by Business owner on 6 Oct 2026)', Words::say('LISTING', 'held', $note, 'Business owner', '6 Oct 2026'),
            'the row\'s own page still says the note in full, with the same name and day');
        self::assertSame('Held back on 6 Oct 2026 (set aside by Matching lead). Open it to see why and to decide it.',
            ReviewController::oneAtATime(['detail' => ['by' => null] + $held['detail']] + $held)['text'] ?? null, 'nobody named: the job, as on the row\'s page');
        self::assertSame(['code' => 'in_spot_check', 'text' => 'In spot check owner-1: it is answered in the spot check.', 'link' => ['/ui/review/samples/4', 'Open the spot check']],
            ReviewController::oneAtATime(['code' => 'in_spot_check', 'detail' => ['sample' => 'owner-1'], 'sample_id' => 4, 'sample' => 'owner-1']));
        // Found by the check on arrival (U144): that no AI has looked at it and what to do; it belongs to no spot check, so no link.
        self::assertSame(['code' => 'on_arrival', 'link' => null, 'text' => 'Found by the check on arrival: no AI has looked at it. Open it to check and decide.'],
            ReviewController::oneAtATime(['code' => 'on_arrival', 'detail' => [], 'sample_id' => 0, 'sample' => '']));
        self::assertSame(['Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.',
            'Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.'],
            [\CW\Ui\Controller\BulkController::why('on_arrival', []), \CW\Ui\Controller\BulkController::why('on_arrival', [], 'BY_ITEM_SKIP')]);
        self::assertNull(ReviewController::oneAtATime(null), 'a row a bulk action can take says nothing');
        // The notice above the list.
        $say = static fn (int $held, int $spot, int $ready, ?string $to = '/list'): ?array => ReviewController::noBoxNotice(['held' => $held, 'in_spot_check' => $spot], $ready, $to);
        self::assertNull($say(0, 0, 900), 'every row has its box: no notice');
        self::assertSame(['text' => '47 on this page are held back and 3 are in a spot check: they are decided one at a time and have no tick box.',
            'link' => ['/list', 'Show only the ones a bulk action can take (863)'], 'none' => null], $say(47, 3, 863));
        self::assertSame(['1 on this page is held back: it is decided on its own and has no tick box.', '1 on this page is in a spot check: it is decided on its own and has no tick box.',
            '50 on this page are held back: they are decided one at a time and have no tick box.', '2 on this page are in a spot check: they are decided one at a time and have no tick box.',
            '1 on this page is held back and 1 is in a spot check: they are decided one at a time and have no tick box.',
            '2 on this page are held back and 1 is in a spot check: they are decided one at a time and have no tick box.'],
            array_map(static fn (array $n): string => $say(...$n)['text'] ?? '', [[1, 0, 5], [0, 1, 5], [50, 0, 5], [0, 2, 5], [1, 1, 5], [2, 1, 5]]));
        self::assertSame(['/list', 'Show only the ones a bulk action can take (1,234)'], $say(50, 0, 1234)['link'] ?? null);
        self::assertSame([null, 'Nothing in this list is ready for a bulk action.'], [$say(2, 0, 0)['link'] ?? null, $say(2, 0, 0)['none'] ?? null],
            'none to show: a sentence, not a link to an empty list');
        self::assertSame([null, null], [$say(2, 0, 9, null)['link'] ?? null, $say(2, 0, 9, null)['none'] ?? null], 'on the list of the ready ones itself: no link to itself');
        // The third kind (U144) in the same notice: alone, after one of the others, after both. Without it the notice reads as before.
        $say3 = static fn (int $held, int $spot, int $arrival): string => ReviewController::noBoxNotice(['held' => $held, 'in_spot_check' => $spot, 'on_arrival' => $arrival], 5, '/list')['text'] ?? '';
        self::assertSame(['1 on this page was found by the check on arrival: it is decided on its own and has no tick box.',
            '50 on this page were found by the check on arrival: they are decided one at a time and have no tick box.',
            '2 on this page are held back and 1 was found by the check on arrival: they are decided one at a time and have no tick box.',
            '1 on this page is in a spot check and 12 were found by the check on arrival: they are decided one at a time and have no tick box.',
            '2 on this page are held back, 1 is in a spot check and 3 were found by the check on arrival: they are decided one at a time and have no tick box.',
            '47 on this page are held back and 3 are in a spot check: they are decided one at a time and have no tick box.'],
            [$say3(0, 0, 1), $say3(0, 0, 50), $say3(2, 0, 1), $say3(0, 1, 12), $say3(2, 1, 3), $say3(47, 3, 0)]);
        // After the last row of a narrowed list: words of their own, never "This list is done." (the list may hold more).
        self::assertSame(['This list is done.', 'That was the last one of what this list is showing.'], [Words::MATCH_NOTICE['queue_done'], Words::MATCH_NOTICE['shown_done']]);
        self::assertSame(Words::MATCH_NOTICE['shown_done'], ReviewController::NOTICES['shown_done'] ?? null, 'a notice the address may name');
        // New Variants shows the same line: variants and items there, never "product".
        foreach ([...array_values(Words::ONE_AT_A_TIME), ...array_values(Words::QUEUE_SHOW)] as $text) {
            self::assertDoesNotMatchRegularExpression('/website products?|warehouse products?|\bproducts?\b/i', $text, $text);
        }
        foreach ([Words::QUEUE_SHOW, Words::ONE_AT_A_TIME] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertDoesNotMatchRegularExpression('/\\bcount(ed|s)?\\b/i', $text, "{$code}: 'count' is only for shelf counts (correction b)");
            }
        }
    }

    /**
     * Products › New Variants (docs/decisions.md U137): the owner's words, "variant" and "item", and the seed's button "Create as new
     * items"; what a row is and the filter in words; every skip of a bulk action has its words (the seed's own say what the computer
     * or the website found); no store is named.
     */
    public function testNewVariantsSpeaksOfVariantsAndItemsAsTheOwnerDoes(): void
    {
        self::assertSame('New Variants', Words::MENU['new_variants']);
        self::assertSame('Create as new items', Words::NEW_VARIANT_ACTION['new_item']);
        self::assertSame(\CW\Mapping\BulkDecisions::NEW_ACTIONS, array_keys(Words::NEW_VARIANT_ACTION));
        self::assertSame(\CW\Mapping\BulkDecisions::NEW_ACTIONS, array_keys(Words::NEW_VARIANT_DONE));
        self::assertSame('Done: 2 new items created and matched, 0 sent to Second approval, 1 skipped.',
            Words::say('BULK', 'summary', Words::say('NEW_VARIANT_DONE', 'new_item', 2), 0, 1));
        self::assertSame('The computer suggests it is CW-000041: open it and decide.', Words::say('BULK_SKIP', 'suggested_match', 'CW-000041'));
        self::assertSame(['Nothing is suggested for this variant. Open it and decide.', 'It is a page heading on the website, not something that is sold. Open it and decide.',
            'It is not published on the website (Bin). Open it and decide.'],
            [Words::BULK_SKIP['not_checked'], Words::BULK_SKIP['page_heading'], Words::say('BULK_SKIP', 'not_published', 'Bin')]);
        // Round 2b's reasons, each in plain words: an item was made from it before, it changed after the check, its name is taken
        // (by an item, or by another ticked row), and a suggestion that is neither "new" nor a match.
        self::assertSame(['An item was already made from this variant (CW-000101): open it and decide.', 'It changed on the website after the computer checked it: open it and decide.',
            'An item with the same name is already in the warehouse (CW-000201): open it and decide.',
            'Another row you ticked has the same name, so the two may be one item: open each and decide.',
            'The computer could not say what it is (Not sure – you choose): open it and decide.'],
            [Words::say('BULK_SKIP', 'made_before', 'CW-000101'), Words::BULK_SKIP['changed_since_check'], Words::say('BULK_SKIP', 'name_elsewhere', 'CW-000201'),
                Words::BULK_SKIP['name_twice'], Words::say('BULK_SKIP', 'not_new', Words::BAND["Can't tell"])]);
        self::assertSame(['made_before', 'not_new', 'changed_since_check', 'name_elsewhere', 'name_twice', 'match_no_item'], array_slice(\CW\Mapping\BulkDecisions::SKIPS, -6));
        // A match that names no item is not "could not say what it is": its own reason, on the result page and on the row.
        self::assertSame(['The computer matched it to a variant of the main store that has no item yet: open it and decide.',
            'The computer matched it to a variant of the main store that has no item yet: open it and decide.',
            'Strong match, to a variant of the main store that has no item yet: open it to decide.'],
            [\CW\Ui\Controller\BulkController::why('match_no_item', ['band' => 'Key']), \CW\Ui\Controller\BulkController::why('match_no_item', ['band' => 'Check'], 'BY_ITEM_SKIP'),
                Words::say('NEW_VARIANTS', 'match_no_item', Words::BAND['Key'])]);
        // The row says the same before it is ticked, and a store no check has marked a variant New on is told so.
        self::assertSame(['When the computer checked it, it found nothing like it.', 'An item was already made from this variant (CW-000101): open it to decide.',
            'It changed on the website after the computer checked it: open it to decide.'],
            [Words::NEW_VARIANTS['is_new'], Words::say('NEW_VARIANTS', 'made_before', 'CW-000101'), Words::NEW_VARIANTS['changed']]);
        self::assertStringContainsString('has not marked any variant of Some Store as New so far', Words::say('NEW_VARIANTS', 'never_new', 'Some Store'));
        self::assertStringContainsString('create the item on its own page', Words::NEW_VARIANTS['never_new']);
        foreach (['no suggestion', 'a suggested match', 'a variant of the main store that has no item yet', 'one the computer could not decide', 'a page heading', 'not published on the website', 'changed on the website after the check',
            'an item already made from it', 'a name that an item already has or that two ticked rows share', 'a barcode that is already on an item or on another variant'] as $reason) {
            self::assertStringContainsString($reason, Words::NEW_VARIANTS['seed_note'], 'the line beside the bar names every reason a ticked row is skipped for');
        }
        // What a row is (the chip) and the filter: "New" is the one kind the seed takes.
        self::assertSame(Queries::NEW_KINDS, array_keys(Words::NEW_VARIANT_STATE));
        self::assertSame([...Queries::NEW_FILTERS, 'all'], array_keys(Words::NEW_VARIANT_FILTER));
        // "No suggestion" since the check on arrival (U144): "Not checked yet" stopped being true the day a check could look and find nothing.
        self::assertSame(['All not matched', 'New: the computer found nothing like it', 'Suggested match', 'No suggestion', 'All'], array_values(Words::NEW_VARIANT_FILTER));
        self::assertSame(['New', 'Suggested match', 'No suggestion'], array_slice(array_values(Words::NEW_VARIANT_STATE), 0, 3));
        self::assertStringContainsString('takes only the rows marked New', Words::NEW_VARIANTS['seed_note']);
        foreach (\CW\Mapping\BulkDecisions::SKIPS as $code) {
            self::assertTrue(Words::has('BULK_SKIP', $code), "BULK_SKIP: no word for `{$code}`");
        }
        self::assertSame(['Last 7 days', 'Last day', 'Any time'], [Words::say('NEW_VARIANTS', 'period_days', 7), Words::NEW_VARIANTS['period_day'],
            Words::NEW_VARIANTS['period_all']]);
        $texts = array_merge(array_values(Words::NEW_VARIANTS), array_values(Words::NEW_VARIANT_ACTION), array_values(Words::NEW_VARIANT_DONE),
            array_values(Words::NEW_VARIANT_PENDING), array_values(Words::NEW_VARIANT_STATE), array_values(Words::NEW_VARIANT_FILTER), array_values(Words::ARRIVAL_LEFT),
            Words::PAGE_INTRO['new_variants'],
            [Words::MENU['new_variants'], Words::MENU_HELP['new_variants'], Words::ERROR['unknown_variants_store'], Words::BULK_SKIP['suggested_match'], Words::BULK_SKIP['not_checked'],
                Words::BULK_SKIP['page_heading'], Words::BULK_SKIP['not_published'], Words::BULK_SKIP['made_before'], Words::BULK_SKIP['not_new'], Words::BULK_SKIP['changed_since_check'],
                Words::BULK_SKIP['name_elsewhere'], Words::BULK_SKIP['name_twice'], Words::BULK_SKIP['match_no_item'], Words::SETTING['mapping.new_variants_days'],
                Words::SETTING_HELP['mapping.new_variants_days']]);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/website products?|warehouse products?|\bproducts?\b/i', $text, $text);
            foreach (Words::SITE as $name) {
                self::assertStringNotContainsStringIgnoringCase($name, $text, 'no store is named: the stores are the channel table\'s rows');
            }
        }
        self::assertStringContainsString('Nothing here is made up', Words::NEW_VARIANTS['where_from']);
    }

    /**
     * The check on arrival's words (docs/decisions.md U144, U145): every strength it can write has its own help (no "the AI thinks"),
     * every reason of its rules its words, both of its flags theirs; a row with no suggestion says why it has none, whatever the
     * check left on it; the box at the top of New Variants says whether the check is on, what waits and what the last run did; its
     * six settings have a plain name and say what they do; and all of it speaks of variants and items, names no store and prints no
     * setting's number.
     */
    public function testTheCheckOnArrivalSaysWhatItFoundInVariantsAndItems(): void
    {
        self::assertSame(\CW\Matching\ArrivalRules::BANDS, array_keys(Words::BAND_HELP_RULES), 'a help text for each strength the check can write, and no other');
        self::assertSame('A barcode, or a web address copied from the main store, points to this item. No AI has looked at it: check the details, then confirm.',
            Words::BAND_HELP_RULES['Check']);
        foreach (Words::BAND_HELP_RULES as $band => $text) {
            self::assertStringContainsString('No AI has looked at it', $text, $band);
            self::assertStringNotContainsString('The AI', $text, $band);
        }
        self::assertSame(['Found by the check on arrival: no AI has looked at it', 'A detail needs a look: open it'], [Words::FLAG['rules_only'], Words::FLAG['arrival_warned']]);
        foreach (\CW\Matching\ArrivalRules::REASONS as $reason) {
            self::assertTrue(Words::has('BAND_REASON', $reason), "BAND_REASON: no word for `{$reason}`");
        }
        self::assertSame(['Its barcode is on this item', 'A rule says the item the clue points to cannot be right; its web address was copied from this item\'s variant on the main store',
            'The item already has a variant of this store, or is suggested for one; its barcode is on this item'],
            array_map(static fn (array $r): ?string => (new \ReflectionMethod(ReviewController::class, 'bandReasons'))->invoke(null, $r),
                [['arrival_barcode'], ['veto_on_key', 'arrival_copy'], ['arrival_item_taken', 'arrival_barcode']]), 'the reasons as the variant\'s page says them');
        // A row with no suggestion: why, from the mark the check left (state, outcome) and whether the check is on for the store.
        $left = static fn (?string $state, ?string $outcome, bool $on): string => \CW\Ui\Controller\NewVariantsController::left($state, $outcome, $on);
        self::assertSame(['The check on arrival is switched off for this store: open it to decide.', 'The check on arrival is switched off for this store: open it to decide.',
            'It was here before the check on arrival, which has not looked at it: open it to decide.', 'Waiting for the check on arrival.',
            'The check on arrival found no barcode and no copied web address for it: open it to decide.',
            'A person or an earlier computer check has dealt with it, so the check on arrival leaves it: open it to decide.',
            'The check on arrival suggested something for it before, and that suggestion is closed: open it to decide.',
            'The check on arrival found no barcode and no copied web address for it: open it to decide.'],
            [$left(null, null, false), $left('queued', null, false), $left(null, null, true), $left('queued', 'no_clue', true), $left('done', 'no_clue', true),
                $left('done', 'has_history', true), $left('done', 'arrival_barcode', true), $left('done', 'no_clue', false)]);
        foreach ([\CW\Matching\ArrivalRules::NO_CLUE, ...\CW\Matching\ArrivalRules::REFUSALS] as $outcome) {
            self::assertArrayHasKey($outcome, Words::ARRIVAL_LEFT, 'every outcome that leaves a variant with no suggestion says why');
            self::assertSame(Words::ARRIVAL_LEFT[$outcome], $left('done', $outcome, true));
        }
        self::assertSame(Words::ARRIVAL_LEFT['other'], $left('done', null, true));
        // A suggestion of the check on a New Variants row: its strength, the item or the closest one, and why.
        self::assertSame(['Likely match – check it: CW-000041 (its barcode is on this item)', 'Not sure – you choose, closest CW-000041 (its barcode is recorded as a pack of more than one)',
            'Clues disagree (its barcode is on more than one item)'],
            [Words::say('NEW_VARIANTS', 'arrival_item', Words::BAND['Check'], 'CW-000041', Words::BAND_REASON['arrival_barcode']),
                Words::say('NEW_VARIANTS', 'arrival_closest', Words::BAND["Can't tell"], 'CW-000041', Words::BAND_REASON['arrival_barcode_pack']),
                Words::say('NEW_VARIANTS', 'arrival_none', Words::BAND['Conflict'], Words::BAND_REASON['arrival_barcode_two_items'])]);
        // The box at the top of New Variants.
        $lines = static fn (bool $on, int $waiting, ?array $last): array => \CW\Ui\Controller\NewVariantsController::arrivalLines(['on' => $on, 'waiting' => $waiting, 'last' => $last], 'Some Store');
        self::assertSame(['The check on arrival is switched off for Some Store, so its new variants get no suggestion by themselves (it is switched on for a store on the Settings page).'],
            $lines(false, 7, null), 'off: nothing about what waits');
        self::assertSame(['The check on arrival is on for Some Store: a new variant whose barcode, or whose web address copied from the main store, points to an item gets a '
            . 'suggestion. No AI looks at it.'], $lines(true, 0, null));
        self::assertSame(['1 variant is waiting for the check.', '1,204 variants are waiting for the check.'], [$lines(true, 1, null)[1], $lines(true, 1204, null)[1]]);
        self::assertSame('Its last run on 12 Oct 2026 looked at 10 here: 3 suggested as a match, 1 where the clues disagree, 2 not sure, 4 left with no suggestion.',
            $lines(true, 0, ['at' => '2026-10-12 06:30:00.000000', 'looked' => 10, 'match' => 3, 'conflict' => 1, 'not_sure' => 2, 'left' => 4])[1]);
        // The settings: a plain name, what it does, under Matching; and the audit log's word for a run.
        $settings = ['mapping.arrival_stores' => 'Stores whose new variants the computer checks', 'mapping.arrival_main_store' => 'The main store',
            'mapping.arrival_bulk_confirm' => 'Matches from the check on arrival may be confirmed from a ticked list',
            'mapping.arrival_max_per_run' => 'Most variants one run of the check looks at', 'mapping.arrival_max_seconds' => 'Longest one run of the check may take, in seconds',
            'mapping.arrival_queue_alarm' => 'Most waiting variants the timer takes by itself'];
        foreach ($settings as $key => $name) {
            self::assertSame($name, Words::SETTING[$key]);
            self::assertArrayHasKey($key, Words::SETTING_HELP);
            self::assertSame($key !== 'mapping.arrival_bulk_confirm', isset(\CW\Settings::RULES[$key]), "{$key}: what it may hold is checked (a switch needs no rule)");
            self::assertDoesNotMatchRegularExpression('/\d/', $name . Words::SETTING_HELP[$key], "{$key}: no number of a setting is written into a text");
        }
        self::assertSame('The check on arrival ran', Words::AUDIT_ACTION['mapping.arrival_check']);
        // Variants and items, no store named, and a sent-back variant is no longer promised a new suggestion by the next check.
        $texts = [...array_values(Words::BAND_HELP_RULES), ...array_values(Words::ARRIVAL_LEFT), Words::FLAG['rules_only'], Words::FLAG['arrival_warned'], Words::ONE_AT_A_TIME['on_arrival'],
            Words::BULK_SKIP['on_arrival'], Words::BY_ITEM_SKIP['on_arrival'], Words::QUEUE_SHOW['arrival'], ...array_map(static fn (string $r): string => Words::BAND_REASON[$r],
                array_values(array_diff(\CW\Matching\ArrivalRules::REASONS, ['veto_on_key']))), Words::BAND_REASON['veto_on_key'], ...array_values($settings),
            ...array_map(static fn (string $k): string => Words::SETTING_HELP[$k], array_keys($settings)), Words::HOME['not_checked_help'], Words::BY_ITEM_BULK['text_send_back']];
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/website products?|warehouse products?|\bproducts?\b|\blistings?\b|\bSKU\b/i', $text, $text);
            foreach (Words::SITE as $name) {
                self::assertStringNotContainsStringIgnoringCase($name, $text, 'no store is named');
            }
        }
        foreach ([Words::BULK_CONFIRM['send_back_text'], Words::BY_ITEM_BULK['text_send_back'], Words::HOME['not_checked_help']] as $text) {
            self::assertStringNotContainsString('which suggests again', $text);
            self::assertStringNotContainsString('Nothing to do now', $text);
        }
        self::assertStringContainsString('the check on arrival leaves a variant that was sent back', Words::BY_ITEM_BULK['text_send_back']);
    }

    /**
     * The website state (docs/decisions.md M66, U146): where the warehouse does not know whether a variant is on sale, the screens
     * say so in words: the skip of a bulk "create as new" (in the lists' words and in By Item's), the line under a row marked New,
     * the line beside the bar. A variant whose product is not published is "not published". The setting has a plain name and says
     * what it does; the audit log names the load of the state. Variants and items, no store named, no code and no number of a setting.
     */
    public function testTheWebsiteStateSaysNotKnownInWordsOfVariantsAndItems(): void
    {
        self::assertContains('state_not_known', BulkDecisions::SKIPS);
        self::assertSame(['The warehouse does not know if it is on sale on the website now. Open it and decide.',
            'The warehouse does not know if it is on sale on its store now. Open it and decide.'],
            [\CW\Ui\Controller\BulkController::why('state_not_known', ['state' => 'too_old', 'as_of' => '2026-01-01 00:00:00.000000']),
                \CW\Ui\Controller\BulkController::why('state_not_known', ['state' => 'not_known'], 'BY_ITEM_SKIP')],
            'one sentence whether the state was never known or is too old: either way the warehouse does not know it NOW');
        self::assertSame('The warehouse does not know if it is on sale on the website now: open it to decide.', Words::NEW_VARIANTS['site_unknown']);
        self::assertStringContainsString('one the warehouse does not know to be on sale on the website now', Words::NEW_VARIANTS['seed_note'],
            'the line beside the bar names this reason with the others');
        self::assertSame('It is not published on the website (Draft). Open it and decide.', \CW\Ui\Controller\BulkController::why('not_published', ['site_status' => 'Draft', 'of' => 'product']),
            'a variant whose product is not published: the same words, with the product\'s state');
        // The setting, under Matching products; and the audit log's word for the load.
        $key = 'mapping.arrival_state_max_days';
        self::assertSame('How old the website\'s state may be', Words::SETTING[$key]);
        self::assertStringContainsString('In days.', Words::SETTING_HELP[$key]);
        self::assertDoesNotMatchRegularExpression('/\d/', Words::SETTING[$key] . Words::SETTING_HELP[$key], 'no number of a setting is written into a text');
        self::assertSame(['min' => 1, 'max' => 365], \CW\Settings::RULES[$key]);
        self::assertSame(Words::settingTopic('mapping.arrival_stores'), Words::settingTopic($key));
        self::assertSame('Website state of a store\'s variants loaded', Words::AUDIT_ACTION['listing.website_state']);
        $texts = [Words::BULK_SKIP['state_not_known'], Words::BY_ITEM_SKIP['state_not_known'], Words::NEW_VARIANTS['site_unknown'], Words::SETTING[$key], Words::SETTING_HELP[$key],
            Words::AUDIT_ACTION['listing.website_state']];
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/website products?|warehouse products?|\bproducts?\b|\blistings?\b|\bSKU\b/i', $text, $text);
            foreach (Words::SITE as $name) {
                self::assertStringNotContainsStringIgnoringCase($name, $text, 'no store is named');
            }
        }
        self::assertDoesNotMatchRegularExpression('/website/i', Words::BY_ITEM_SKIP['state_not_known'], 'By Item says "its store"');
    }

    public function testEveryStateOfAReservationHasItsWords(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0001_core.sql');
        $enum = static function (string $table, string $column) use ($sql): array {
            self::assertSame(1, preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $sql, $t), $table);
            self::assertSame(1, preg_match('/^\s+' . $column . '\s+ENUM\(([^)]*)\)/m', $t[1], $m), "{$table}.{$column}");
            return array_map(static fn (string $v): string => trim($v, " '"), explode(',', $m[1]));
        };
        self::assertSame($enum('reservation', 'status'), ReservationViews::STATES, 'the state filter is the engine\'s four states');
        self::assertSame($enum('reservation_unit', 'state'), ReservationViews::UNIT_STATES);
        self::assertSame($enum('reservation', 'origin'), \CW\Reservations::ORIGINS);
        self::assertSame(ReservationViews::STATES, array_keys(Words::RESV_STATE));
        self::assertSame(ReservationViews::UNIT_STATES, array_keys(Words::RESV_UNIT));
        self::assertSame(ReservationViews::GROUPS, array_keys(Words::RESV_GROUP));
        self::assertSame(ReservationViews::GROUPS, array_keys(Words::RESV_CHIP));
        self::assertSame(['held', 'to_ship', 'closed', 'released', 'expired'], ReservationViews::GROUPS,
            'the engine\'s states, a paid order split by whether an item still waits to ship');
        self::assertSame([], array_diff(ReservationViews::OPEN_UNITS, ReservationViews::UNIT_STATES));
        foreach (ReservationViews::UNIT_STATES as $state) {
            self::assertTrue(Words::has('RESV', 'out_part_' . $state), "what became of it: some items {$state}");
            self::assertArrayHasKey($state, Words::TONE['RESV_UNIT'], "{$state}: its colour on a reservation's page");
        }
        foreach (ReservationViews::GROUPS as $group) {
            self::assertArrayHasKey($group, Words::TONE['RESV_GROUP'], "{$group}: its colour on the board");
        }
        foreach (['reserve', 'commit', 'commit_release', 'release', 'expire', 'cancel', 'uncancel', 'ship', 'unship', 'return', 'adopt', 'transfer_out', 'transfer_in'] as $type) {
            self::assertTrue(Words::has('MOVEMENT', $type), "a reservation's history: {$type}");
        }
        foreach ([Words::RESV['none'], Words::RESV['none_text'], Words::RESV['none_filtered'], Words::RESV['product_search'], Words::RESV['to_ship_all'], Words::RESV['foot']] as $text) {
            self::assertMatchesRegularExpression('/^[A-Z].*\.$/s', $text, 'a full sentence (writing rule 7)');
        }
        self::assertSame('No reservations yet.', Words::RESV['none']);
    }

    /** The matching pages' small helpers: "1 sale = N products", a quoted name, a rule's detail in words, a refusal by its code. */
    public function testTheMatchingHelpers(): void
    {
        self::assertSame('1 sale = 1 product', Words::saleUses(1));
        self::assertSame('1 sale = 10 products', Words::saleUses('10'));
        self::assertSame('1 sale = 1,000 products', Words::saleUses(1000));
        self::assertSame('1 sale = 1 product', Words::saleUses(null));
        self::assertSame('"Elux Legend Blue Razz"', Words::quoted("  Elux  Legend\nBlue Razz ", 'the website product'));
        self::assertSame('the website product', Words::quoted('  ', 'the website product'));
        self::assertSame('the website product', Words::quoted(null, 'the website product'));
        self::assertSame(80, mb_strlen(Words::quoted(str_repeat('a', 200), '')), 'a long name is cut');

        // A rule's detail (plan F125).
        self::assertSame('extra word: lemonade', Duplicates::readableDetail('listing + / item +lemonade'));
        self::assertSame('extra words: ice, lemonade', Duplicates::readableDetail('listing +ice / item +lemonade'));
        self::assertSame('only on one page: kit, mesh', Duplicates::readableDetail('+kit / +mesh'));
        self::assertSame('Pod kit (prefilled) vs Refill pods or cartridges', Duplicates::readableDetail('pod_kit/prefilled vs refill_pod_cartridge'));
        self::assertSame('not stated vs 20', Duplicates::readableDetail('- vs 20'));
        self::assertSame('', Duplicates::readableDetail('- vs -'), '"not stated vs not stated" adds nothing to the reason');
        self::assertSame('coil, glass', Duplicates::readableDetail('coil+glass'));
        self::assertSame('50 vs 70', Duplicates::readableDetail('50 vs 70'));
        self::assertSame('', Duplicates::readableDetail(''));

        // A refusal of DecisionService in the page's words, by its code; the service's own message stays the API's (plan F214).
        $e = static fn (string $code, array $detail = []): CwException => new CwException($code, 'the service text', 409, $detail);
        self::assertSame(Words::MATCH_ERROR['lead_required'], ReviewController::plain($e('lead_required'), 'decide'));
        self::assertSame(Words::MATCH_ERROR['lead_required_approve'], ReviewController::plain($e('lead_required'), 'approve'));
        self::assertSame(Words::MATCH_ERROR['lead_required_withdraw'], ReviewController::plain($e('lead_required'), 'withdraw'));
        self::assertSame(Words::MATCH_ERROR['map_version_conflict'], ReviewController::plain($e('map_version_conflict'), 'decide'));
        self::assertSame(Words::MATCH_ERROR['map_version_conflict_settle'], ReviewController::plain($e('map_version_conflict'), 'approve'));
        self::assertSame(Words::say('MATCH_ERROR', 'bad_card_number', 'Strength (mg)', '20'), ReviewController::plain($e('bad_card', ['field' => 'card.strength_mg']), 'decide'));
        self::assertSame(Words::say('MATCH_ERROR', 'bad_card', 'Brand'), ReviewController::plain($e('bad_card', ['field' => 'card.brand']), 'decide'));
        self::assertSame(Words::MATCH_ERROR['not_allowed'], ReviewController::plain($e('role_not_allowed'), 'decide'));
        self::assertSame('The service text. Nothing was saved.', ReviewController::plain($e('some_new_code'), 'decide'), 'a code without words: the service\'s message');
        self::assertSame(Words::MATCH_ERROR['same_person'], ReviewController::plain($e('same_person'), 'approve'));
    }

    public function testSayDocTitlesSettingsAndRefusals(): void
    {
        self::assertSame('2 records, newest first.', Words::say('RECORDS', 'total_many', 2));
        self::assertSame('1,234 items', Words::say('CHECKS', 'items', 1234), 'a whole number gets thousands separators');
        self::assertSame('Over £10,000 (no VAT)', Words::say('SETTINGS_PAGE', 'over_value', 10000));
        self::assertSame(Words::RECORDS['none'], Words::say('RECORDS', 'none'), 'no %s: the text as it is');
        // A record as the screens name it (plan F099, F408); Document::label() stays for file names and service messages.
        self::assertSame('PO-000001 – Elux Wholesale', Words::docTitle('PO', 'PO-000001', 'Elux Wholesale'));
        self::assertSame('Order for Elux Wholesale (no number yet)', Words::docTitle('PO', null, 'Elux Wholesale'));
        self::assertSame('Cancellation of PO-000003 – Elux Wholesale', Words::docTitle('PO', 'PO-000004', 'Elux Wholesale', 'PO-000003'));
        self::assertSame('Purchase order PO-000001', Words::docTitle('PO', 'PO-000001'));
        self::assertSame('Adjustment ADJ-000001', Words::docTitle('ADJ', 'ADJ-000001'));
        self::assertSame('Adjustment (no number yet)', Words::docTitle('ADJ', null));
        self::assertSame('New kind (no number yet)', Words::docTitle('NEW', null, null, null, 'New kind'), 'a kind without a word: its database name');
        self::assertSame('Deliveries', Words::docType('GRN', true));
        self::assertSame('XYZ', Words::docType('XYZ'));
        // Settings: the plain name, what it does, its topic; a key without a word is made readable.
        self::assertSame('Spare days of stock', Words::settingName('reorder.default_safety_days'));
        self::assertSame('Brand new thing', Words::settingName('reorder.brand_new_thing'));
        self::assertSame('Plain', Words::settingName('plain'));
        self::assertSame('the raw text', Words::settingHelp('reorder.brand_new_thing', 'the raw text'));
        self::assertSame('Reorder', Words::settingTopic('reorder.default_safety_days'));
        self::assertSame('Other', Words::settingTopic('nothing.known'));
        self::assertStringNotContainsString('Phase', Words::settingHelp('costs.site_writeback', 'NOT built in Phase I-2'), 'F420');
        // Refusals: by code, else the service's message; none for none.
        self::assertSame(Words::REFUSAL['own_document'], Words::refusal(['code' => 'own_document', 'message' => 'You posted this document: ...']));
        self::assertSame('the service text', Words::refusal(['code' => 'some_new_code', 'message' => 'the service text']));
        self::assertNull(Words::refusal(null));
        // File sizes people read.
        self::assertSame('640 bytes', Html::size(640));
        self::assertSame('12 KB', Html::size(12 * 1024));
        self::assertSame('1.5 MB', Html::size((int) (1.5 * 1024 * 1024)));
        self::assertSame('2 MB', Html::size(2 * 1024 * 1024));
    }

    /**
     * The set-it-yourself pack (0019; docs/decisions.md Y36): every permission, approval rule, kind of change, reason a warehouse is
     * not empty, website mode and server command has its words; every refusal and notice is a full sentence; no word names a
     * setting key or a server command.
     */
    public function testEveryCodeOfTheSetItYourselfPagesHasAWord(): void
    {
        foreach ([
            'PERMISSION' => array_keys(Permissions::MAP),
            'RULE' => [...array_keys(\CW\Admin\ApprovalRules::SWITCHES), ...array_keys(\CW\Admin\ApprovalRules::NUMBERS)],
            'CONFIG_ACTION' => ['baseline', 'add', 'change', 'agree', 'unagree', 'rename', 'switch_off', 'switch_on', 'sellable', 'owner', 'uses'],
            'WHY_NOT_EMPTY' => ['stock', 'website', 'records', 'counts'],
            'MODE' => \CW\ChannelAdmin::MODES,
            'SITE_COMMAND' => array_column(\CW\Admin\Sites::commands(['code' => 'vapeandgo', 'mode' => 'off', 'writer' => false]), 'what'),
            'CONFIG_ERROR' => ['bad_reason', 'changed_meanwhile', 'role_not_allowed', 'bad_value', 'bad_limit', 'bad_days', 'bad_rule', 'limit_required',
                'no_approval_rule', 'bad_reject_action', 'bad_code_reason', 'bad_code_warehouse', 'bad_code_place', 'bad_label', 'bad_name', 'bad_note', 'bad_owner',
                'bad_uses', 'bad_direction', 'reason_exists', 'reason_locked', 'warehouse_exists', 'place_exists', 'system_warehouse', 'warehouse_off',
                'other_not_sellable', 'confirm_needed', 'warehouse_in_use', 'sellable_warehouse', 'warehouse_not_empty', 'place_in_use', 'owner_not_empty',
                'loosen_needs_reviewer', 'reject_record_only', 'unconfirmed'],
            'STAFF' => ['request_open', 'bad_name', 'bad_email', 'staff_exists', 'bad_session', 'not_set_up', 'already_set_up', 'code_refused_open', 'code_reset_open'],
            'STAFF_REQUESTS' => ['own_request', 'own_account', 'request_closed', 'note_required', 'role_not_allowed'],
            'ERROR' => ['no_setting', 'no_reason', 'no_warehouse', 'no_request'],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, (string) $code), "{$group}: no word for `{$code}`");
            }
        }
        foreach (\CW\Admin\ApprovalRules::SWITCHES as $key => $default) {
            self::assertSame(['title', 'on', 'off'], array_keys(Words::RULE[$key]), $key);
        }
        foreach (\CW\Admin\ApprovalRules::NUMBERS as $key => $default) {
            self::assertStringContainsString('%s', Words::RULE[$key]['now'], $key);
        }
        foreach ([Words::CONFIG_ERROR, Words::SETTING_NOTICE, Words::REASON_NOTICE, Words::WAREHOUSE_NOTICE, Words::STAFF_REQUESTS] as $texts) {
            foreach ($texts as $code => $text) {
                if (in_array($code, ['none', 'person', 'now', 'asked', 'by', 'on', 'ok', 'note', 'not_ok', 'yours', 'what', 'email', 'made', 'set_up', 'set_up_at',
                    'set_up_not_yet', 'set_up_server', 'ok_reset', 'not_ok_reset'], true)) {
                    continue; // labels, not refusals
                }
                self::assertMatchesRegularExpression('/^[A-Z"].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        // Writing rule 12: the commands of the Websites page are not words: they come from Admin\Sites, folded for Fazil only.
        foreach (\CW\Admin\Sites::commands(['code' => 'vapeandgo', 'mode' => 'shadow', 'writer' => true]) as $c) {
            self::assertStringStartsWith('php bin/', $c['command']);
        }
        // The fixed 20 left the spot check's words: the size is the Approval rules page's (approvals.spot_check_size).
        foreach ([Words::MENU_HELP['mapping'], Words::PAGE_INTRO['samples'][0], Words::PAGE_INTRO['sample'][0], Words::HELP['spot_check']] as $text) {
            self::assertStringNotContainsString('20', $text);
        }
    }
}
