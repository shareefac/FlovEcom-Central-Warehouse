<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Documents\Document;
use CW\Mapping\DecisionService;
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
use CW\Ui\Tabs;
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
            'REASON' => array_values(array_unique([...PurchaseOrders::CANCEL_REASONS, ...PurchaseOrders::AMEND_REASONS])),
            'SUPPLIER_STATUS' => Suppliers::STATUSES,
            'CHECK_REASON' => ['all_documents', 'over_limit', 'positive_without_supplier_doc', ...Suppliers::APPROVAL_REASONS, 'import_route', 'supplier_changed',
                'company_changed'],
            'CHECK_KIND' => $migration('0008_documents.sql', 'kind'),
            'REVIEW_STATE' => $migration('0008_documents.sql', 'review_state'),
            'TASK_STATE' => ['open', 'approved', 'rejected', 'withdrawn'],
            'SAMPLE_STATE' => array_keys(SamplesController::STATE_LABELS),
            'ACTION' => DecisionService::ACTIONS,
            'DECISION_STATE' => $migration('0004_matching.sql', 'state'),
            'LISTING_STATUS' => $migration('0004_matching.sql', 'prev_status'),
            'NEEDS_SECOND' => ['protected_sku', 'units_per_item', 'previously_rejected', 'merge', 'counted_item'],
            'POLICY' => Stock::POLICIES,
            'STOCK' => Stock::BUCKETS,
            'LANE' => Queries::LANES,
            'AI' => Band::OUTCOMES,
            'FLAG' => [...Veto::SOFT_FLAGS, ...Band::CONFLICT_FLAGS],
            'VETO' => Veto::CODES,
            'REORDER_FLAG' => array_keys(ReorderController::FLAG_TEXT),
            'SECTION' => array_column(Permissions::MENU, 'section'),
            'MENU' => array_merge(...array_map(static fn (array $s): array => array_column($s['items'], 'key'), Permissions::MENU)),
            'MENU_HELP' => array_merge(...array_map(static fn (array $s): array => array_column($s['items'], 'key'), Permissions::MENU)),
            'COMING_LATER' => array_column(Permissions::COMING_LATER, 'key'),
            'BADGE' => ['linking_pending', 'linking_duplicates', 'reviews_open', 'barcodes_open'],
            'TAB' => ['home', ...Tabs::PRIORITY, 'more'],
            'ERROR_TITLE' => ['400', '403', '404', '405', '409', '413', '422', '429', '500', '503'],
            'ERROR' => ['csrf', 'lead_only', 'not_found', 'method_not_allowed', 'too_large', 'form_truncated', 'bad_form_key', 'idempotency_key_reused',
                'unavailable', 'busy', 'internal', 'unknown_staff', 'unknown_queue'],
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
        self::assertSame('receiving deliveries, supplier invoices, returns to suppliers, trade sales', Words::comingLater(['purchasing_desk']));
        self::assertSame('', Words::comingLater(['buyer']));
        self::assertSame('Our name, numbers and addresses as printed on every purchase order. You can look; Reviewers change this.', Words::intro('company', 'Reviewers'));
        self::assertSame('', Words::intro('no-such-page'));
        self::assertSame('Things to check', Words::title('reviews'));
        self::assertSame('Purchase orders', Words::title('orders'));
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
        foreach (['0009_suppliers.sql', '0010_purchase_orders.sql', '0011_reorder.sql'] as $file) {
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
            'MOVEMENT' => [...Movements::TYPES, 'reserve', 'release', 'expire', 'commit', 'cancel', 'ship', 'unship', 'return', 'opening', 'trade_sale',
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
            'ERROR' => ['unknown_listing', 'unknown_item', 'unknown_group', 'unknown_sample'],
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
        self::assertSame('Stock correction ADJ-000001', Words::docTitle('ADJ', 'ADJ-000001'));
        self::assertSame('Stock correction (no number yet)', Words::docTitle('ADJ', null));
        self::assertSame('New kind (no number yet)', Words::docTitle('NEW', null, null, null, 'New kind'), 'a kind without a word: its database name');
        self::assertSame('Deliveries', Words::docType('GRN', true));
        self::assertSame('XYZ', Words::docType('XYZ'));
        // Settings: the plain name, what it does, its topic; a key without a word is made readable.
        self::assertSame('Spare days of stock', Words::settingName('reorder.default_safety_days'));
        self::assertSame('Brand new thing', Words::settingName('reorder.brand_new_thing'));
        self::assertSame('Plain', Words::settingName('plain'));
        self::assertSame('the raw text', Words::settingHelp('reorder.brand_new_thing', 'the raw text'));
        self::assertSame('What to buy', Words::settingTopic('reorder.default_safety_days'));
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
}
