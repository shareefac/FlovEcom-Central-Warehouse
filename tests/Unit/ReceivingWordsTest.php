<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Documents\Document;
use CW\Receiving\GoodsReceipts;
use CW\Receiving\Incidents;
use CW\Receiving\SellingModes;
use CW\Ui\Controller\IncidentsController;
use CW\Ui\Controller\ReceivingController;
use CW\Ui\ReceiptWords;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The delivery screens (IM6 Receive + invoice, the goods-in bench, incidents) and the product page's selling mode (IM10) in plain
 * words (U85-U90): every code of these pages has its words, notices and refusals are sentences, "count" stays a shelf count, no
 * word but the "where the items go" help names the stock places VERIFY, UNSTAMPED or MAIN, and the helpers that put the services'
 * facts into words (Ui\ReceiptWords, ReceivingController::plain and its row helpers, IncidentsController::screenRow) say what
 * they should.
 */
final class ReceivingWordsTest extends TestCase
{
    /** The goods-in plan's sentences as Receiving\ReceiptPlan and GoodsReceipts::scanned write them (their tests pin them). */
    private const PLAN = [
        'The receipt has no lines yet.',
        "Type the supplier's invoice number: it is compulsory, and a supplier's invoice is booked once.",
        "Attach the supplier's invoice (a PDF, or a photo of a paper invoice) before posting.",
        "The attached supplier invoice (inv.pdf) is also the invoice of GRN-000003: a supplier's invoice is received once. Attach the right invoice, or cancel one of the two receipts.",
        "The attached supplier invoice (inv.pdf) is also the invoice of draft receipt #12: a supplier's invoice is received once. Attach the right invoice, or cancel one of the two receipts.",
        'Supplier SBS is pending approval: goods are received only from an active supplier (a second person approves the supplier first).',
        'Supplier SBS is overseas and its import route (where UK duty stamps are applied) is not approved.',
        "Supplier SBS: a change of its import route or of its overseas status waits for a second person's approval.",
        'PO-000001 is not a purchase order of supplier SBS.',
        'PO-000001 is closed: goods are received only against an approved, sent or part-received order (set the lines to "not against the order", or ask the buyer).',
        'PO-000001 is part received: no line is received against it.',
        'The goods cannot have arrived after now: correct "received at".',
        'The goods arrived on 1 Sep 2026, more than 30 days before the receipt was keyed: a receipt is dated at most 30 days back. Ask the purchasing manager.',
        'The goods arrived on 6 Oct 2026, before the receipt was keyed (7 Oct 2026): say why it is keyed late (it arrived yesterday, a paper receiving sheet while CW was down, ...).',
        'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork are credible.',
        'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork are credible, nor counted lines 1-3.',
        'The bench found the supplier or the paperwork not credible: do not post. Refuse the delivery and cancel this receipt, or ask the purchasing manager.',
        'Line 2: PO-000001 has no item line 4.',
        'Line 2: PO-000001 line 1 is another item than CW-000003.',
        'Line 1: CW-000003 is blocked by its item card (nicotine over 20 mg/ml), so it cannot be received. A person confirmed these fields; if they are wrong, correct the item card and confirm it again. Refuse the goods otherwise.',
        'Line 1: CW-000003 gets its goods-in from the ERPNext relay of vapeandgo (one route per item, plan §9): book this delivery in ERPNext, or end the relay for that site first.',
        'Line 1 (CW-000003): the bench has not recorded the duty stamp check (duty-liable liquid must arrive stamped).',
        'Line 1 (CW-000003): the stamp is not on the outer retail pack, so the 15 units that arrived are unstamped: record them as unstamped.',
        'Line 1 (CW-000003): say which stamp it carries (digital or transitional).',
        'Line 1: CW-000003 needs no duty stamp (its item card: Coil): record no unstamped units.',
        'Line 1 (CW-000003): from 1 Jan 2027 an unstamped duty-liable delivery is refused at the door or quarantined: choose refuse or quarantine.',
        "Line 1 (CW-000003): accepting unstamped stock needs the supplier's evidence that it was made or imported before 1 Oct 2026 (what it is, in at least 10 characters).",
        'Waiting for the goods-in bench: lines 1-3 and 5 not counted yet (each line is counted, and its duty stamp checked, after it was keyed or last changed).',
        'The bench checked this delivery at 7 Oct 2026 09:00, before it arrived (7 Oct 2026 10:00): correct "received at", or have the bench check it again.',
        'CW-000003: lines 1 and 2 give it different selling modes (In-Stock, Out-Of-Stock): choose one.',
        'Line 1: 23 units of PO-000001 line 1 would be received against 20 ordered (0 before this receipt): more than the 10% over-delivery tolerance. Ask the buyer, or key the extra on a line not against the order.',
        'Line 1 (CW-000003) is not received against PO-000001.',
        'Line 1 (CW-000003): its item card breaks a rule no confirmation stands behind (nicotine over 20 mg/ml): a warning until someone confirms the card.',
        'Line 1 (CW-000003) is marked discontinued on its item card.',
        'Line 1 (CW-000003): no item card says whether it is duty-liable, so it is treated as duty-liable (its stamp is checked).',
        'Line 1 (CW-000003): its item card does not say whether it is duty-liable, so it is treated as duty-liable (its stamp is checked).',
        "Line 1 (CW-000003): 5 unstamped units accepted on the supplier's evidence of manufacture before 1 Oct 2026: they must be sold, returned or destroyed by 31 Mar 2027.",
        "Line 1 (CW-000003): its 2 damaged and 1 over units arrived unstamped too (the stamp is not on the pack, or the delivery's unstamped units are refused), so they are refused at the door, not put in VERIFY.",
        "Line 1 (CW-000003): its 2 damaged units arrived unstamped too (the stamp is not on the pack, or the delivery's unstamped units are quarantined), so they are quarantined in UNSTAMPED, not put in VERIFY.",
        "Line 1 (CW-000003): 40 units over on a line of 15 (the bench confirmed the count). They go to VERIFY at the line's cost until the supplier invoices or collects them.",
        'Line 1 (CW-000003): 2 short, 1 over, 3 damaged, 1 wrong item, 4 unstamped (an incident each when posted).',
        'Line 1 (CW-000003) costs £0: a free item? The cost is provisional until the supplier invoice is matched (IM7).',
        'CW-000003: nothing of it is accepted into MAIN, so its selling mode stays Out-Of-Stock (a receipt sets the mode only of what it accepts).',
        'CW-000003: nothing of it is accepted into MAIN, so its selling mode stays as it is (a receipt sets the mode only of what it accepts).',
        'PO-000001 line 1: 21 units received against 20 ordered (within the 10% tolerance).',
        'CW-000003: selling mode not known → From-Warehouse (no earlier mode known: the fallback mode for Out-Of-Stock items).',
        'CW-000003: selling mode In-Stock → From-Warehouse (chosen on the receipt).',
        'That barcode is one unit of CW-000003, but this supplier sells it in packs of 10 or packs of 5: add one of its packs, or single units?',
    ];

    public function testEveryCodeOfTheDeliveryPagesHasAWord(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0017_receiving.sql');
        $enum = static function (string $column, int $nth = 0) use ($sql): array {
            self::assertGreaterThan($nth, preg_match_all('/\b' . preg_quote($column, '/') . '\s+ENUM\(([^)]*)\)/', $sql, $m), $column);
            return array_map(static fn (string $v): string => trim($v, " '\n"), explode(',', $m[1][$nth]));
        };
        foreach ([
            'RECEIPT_STATE' => Document::STATUSES,
            'INCIDENT_KIND' => [...array_keys(Incidents::KINDS), ...$enum('kind')],
            'INCIDENT_WHERE' => [...array_keys(Incidents::DISPOSITIONS), ...$enum('disposition')],
            'INCIDENT_STATE' => [...Incidents::STATUSES, ...$enum('status')],
            'RECEIPT_FILE' => array_keys(GoodsReceipts::FILE_ROLES),
            'STAMP_TYPE' => [...GoodsReceipts::STAMP_TYPES, ...$enum('stamp_type')],
            'UNSTAMPED_ACTION' => [...GoodsReceipts::UNSTAMPED_ACTIONS, ...$enum('unstamped_action')],
            'MODE_SOURCE' => $enum('mode_source'),
            'MODE_MEANING' => SellingModes::MODES,
            'BENCH_STATE' => ['todo', 'part', 'done', 'refused'],
            'SELLING_ERROR' => ['bad_mode', 'bad_reason', 'bad_threshold', 'no_sites', 'unknown_site', 'protected_item', 'selling_mode_changed'],
            'PAGE_INTRO' => ['receiving', 'receipt_draft', 'receipt_other', 'receipt', 'bench', 'receipt_bench', 'incidents'],
            'HELP' => ['duty_stamp', 'unstamped_rule', 'where_units_go', 'posting', 'selling_mode'],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(isset(constant(Words::class . '::' . $group)[$code]), "{$group}: no word for `{$code}`");
            }
        }
        // The controllers' notices and labels are the Words groups (one place for the words).
        self::assertSame(Words::RECEIPT_NOTICE, ReceivingController::NOTICES);
        self::assertSame(['resolved' => Words::RECEIPT_NOTICE['resolved']], IncidentsController::NOTICES);
        self::assertSame(Words::RECEIPT_FILE, ReceivingController::FILE_ROLE_LABELS);
        self::assertSame(Words::MODE_SOURCE, ReceivingController::MODE_SOURCES);
        // Every problem the goods-in plan can raise has its words (the code of each $add(...) and line problem $lp[] in ReceiptPlan).
        preg_match_all("/(?:\\\$add\\(|\\\$lp\\[\\] = \\[)'([a-z_]+)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/src/Receiving/ReceiptPlan.php'), $m);
        self::assertGreaterThan(20, count(array_unique($m[1])));
        foreach (array_unique($m[1]) as $code) {
            self::assertTrue(Words::has('RECEIPT_PLAN', $code === 'import_route_not_approved' ? 'import_route' : $code), "RECEIPT_PLAN: no word for `{$code}`");
        }
    }

    public function testNoticesAndRefusalsAreSentencesCountIsAShelfCountAndTheStockPlacesHaveWords(): void
    {
        $fragments = ['bench_the_delivery', 'bench_except', 'mode_unknown', 'and', 'damaged_n', 'over_n', 'short_n', 'wrong_n', 'unstamped_n', 'items_word'];
        foreach ([Words::RECEIPT_NOTICE, Words::RECEIPT_ERROR, Words::SELLING_ERROR, Words::RECEIPT_PLAN, Words::SELLING_NOTICE] as $texts) {
            foreach ($texts as $code => $text) {
                if (in_array($code, $fragments, true)) {
                    continue; // words inside a sentence
                }
                self::assertMatchesRegularExpression('/^[A-Z"%].*[.:?]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        $groups = ['RECEIVING', 'RECEIPT', 'BENCH', 'INCIDENTS', 'RECEIPT_NOTICE', 'RECEIPT_ADDED', 'RECEIPT_ERROR', 'RECEIPT_PLAN', 'RECEIPT_STATE', 'BENCH_STATE',
            'INCIDENT_KIND', 'INCIDENT_WHERE', 'INCIDENT_STATE', 'RECEIPT_FILE', 'STAMP_TYPE', 'UNSTAMPED_ACTION', 'MODE_SOURCE', 'MODE_MEANING', 'SELLING_ERROR'];
        $help = array_intersect_key(Words::HELP, array_flip(['duty_stamp', 'unstamped_rule', 'where_units_go', 'posting', 'selling_mode']));
        foreach ([...array_map(static fn (string $g): array => Words::group($g), $groups), $help, Words::PAGE_INTRO] as $texts) {
            array_walk_recursive($texts, static function (mixed $text, mixed $code): void {
                $other = (string) preg_replace('/shelf counts?|stock counts|stock (has been |was |not |last |is )?counted/i', '', (string) $text);
                self::assertDoesNotMatchRegularExpression('/\bcount(ed|s)?\b/i', $other, "{$code}: 'count' is only for shelf counts (correction b)");
                if ($code !== 'where_units_go') {
                    self::assertDoesNotMatchRegularExpression('/\b(VERIFY|UNSTAMPED|MAIN|MiB|GRN|receipt)\b/', (string) $text, "{$code}: the stock places and the "
                        . 'receipt have plain words (set aside to check, the unstamped quarantine, into stock, delivery)');
                }
            });
        }
        self::assertStringContainsString('VERIFY and UNSTAMPED', Words::HELP['where_units_go'], 'the one place that names the codes, for older notes');
        self::assertStringContainsString('1 Jan 2027', Words::HELP['unstamped_rule'], 'the owner\'s decision 8: unstamped deliveries refused from 1 Jan 2027');
        self::assertStringContainsString('31 Mar 2027', Words::HELP['unstamped_rule']);
        foreach (SellingModes::MODES as $mode) {
            self::assertStringContainsString("**{$mode}:**", Words::HELP['selling_mode'], 'the websites\' own labels, each with what it means');
        }
    }

    public function testReceiptWordsSaysEveryPlanSentenceAgainInTheScreensWords(): void
    {
        $products = ['CW-000003' => 'Elux Mint 10ml'];
        foreach (self::PLAN as $m) {
            $said = ReceiptWords::one($m, $products, 'Screen Box');
            self::assertNotSame($m, $said, "not recognised: {$m}");
            self::assertDoesNotMatchRegularExpression('/\b(VERIFY|UNSTAMPED|MAIN|item card|receipt|posted|posting|units?|credible|IM7|plan §)\b/', $said, "{$m} -> {$said}");
            self::assertStringNotContainsString('CW-000003', $said, 'the product by its name');
        }
        self::assertSame('Something new.', ReceiptWords::one('Something new.'), 'a sentence it does not know is shown as the service wrote it');
        self::assertSame(['No products yet.'], ReceiptWords::plain(['The receipt has no lines yet.']));
        $w = static fn (string $key, string|int ...$args): string => Words::say('RECEIPT_PLAN', $key, ...$args);
        $one = static fn (int $i): string => ReceiptWords::one(self::PLAN[$i], $products, 'Screen Box');
        self::assertSame($w('invoice_file_required'), $one(2));
        self::assertSame($w('invoice_copy_elsewhere', 'inv.pdf', Words::say('RECEIPT', 'draft_ref', '12')), $one(4));
        self::assertSame($w('po_reference', 'PO-000001', 'partly delivered'), $one(10), 'the order\'s state in words');
        self::assertSame($w('bench_check_required_lines', 'lines 1-3'), $one(15));
        self::assertSame($w('relay_route', 1, 'Elux Mint 10ml', 'Vape and Go'), $one(20), 'the website by its name');
        self::assertSame($w('line_not_checked', 'lines 1-3 and 5'), $one(27));
        self::assertSame($w('checked_before_arrival', '7 Oct 2026, 09:00', '7 Oct 2026, 10:00'), $one(28), 'UK time in the screens\' one form');
        self::assertSame($w('over_tolerance', 'Line 1', 23, 'PO-000001', 1, 20, 0, 10), $one(30));
        self::assertStringContainsString('2 damaged and 1 extra items', $one(37));
        self::assertSame($w('findings', 1, 'Elux Mint 10ml', '2 short, 1 extra, 3 damaged, 1 wrong product, 4 without a duty stamp'), $one(40));
        self::assertSame($w('mode_change', 'Elux Mint 10ml', 'not known', 'From-Warehouse', Words::MODE_SOURCE['fallback']), $one(45));
    }

    public function testThePagesSayTheServicesFactsInWords(): void
    {
        // Refusals by code; the services' own messages are unchanged and shown only when a code has no words.
        $e = static fn (string $code, array $detail = [], string $msg = 'the service text'): CwException => new CwException($code, $msg, 409, $detail);
        self::assertSame(Words::RECEIPT_ERROR['not_creator'], ReceivingController::plain($e('not_creator')));
        self::assertSame(Words::RECEIPT_ERROR['not_ready_one'], ReceivingController::plain($e('invoice_file_required', ['problems' => [['code' => 'x']]])));
        self::assertSame(Words::say('RECEIPT_ERROR', 'not_ready', 3), ReceivingController::plain($e('no_lines', ['problems' => [[], [], []]])));
        self::assertSame(Words::RECEIPT_ERROR['version_conflict_choice'], ReceivingController::plain($e('version_conflict')));
        self::assertSame(Words::RECEIPT_ERROR['version_conflict'], ReceivingController::plain($e('version_conflict', [], Words::RECEIPT_ERROR['version_conflict'])));
        self::assertSame(Words::RECEIPT_ERROR['bad_status'], ReceivingController::plain($e('bad_field', ['field' => 'status'])));
        self::assertSame(Words::ERROR['task_closed'], ReceivingController::plain($e('task_closed')));
        self::assertSame('Line 3, short: too many. Nothing was saved.', ReceivingController::plain($e('bad_bench', [], 'line 3, short: too many')), 'no words: the service\'s sentence');
        self::assertSame(Words::say('RECEIPT_ERROR', 'too_large', 2), ReceivingController::plain($e('too_large')));

        // The list's rows, the bench's progress, where the items went, the packs, the title, which websites the mode reaches.
        $row = ['id' => 1, 'number' => null, 'status' => 'draft', 'review_state' => null, 'external_ref' => null, 'po_number' => 'PO-000004', 'lines' => 1, 'units' => 20,
            'checked_at' => null, 'paperwork_ok' => null, 'checked_lines' => 0, 'open_incidents' => 2];
        $r = ReceivingController::screenRow($row);
        self::assertSame(Words::RECEIVING['no_number'] . ' · ' . Words::RECEIVING['no_invoice'] . ' · ' . Words::say('RECEIVING', 'order_no', 'PO-000004'), $r['number_line']);
        self::assertSame(['needs', Words::RECEIPT_STATE['draft'], '1 line, 20 items', 'todo', '2 incidents open'],
            [$r['state_tone'], $r['state_word'], $r['size_line'], $r['bench_state'], $r['incidents_line']]);
        self::assertNull(ReceivingController::screenRow(['status' => 'posted', 'number' => 'GRN-000001'] + $row)['bench_state'], 'the bench is done with a booked-in delivery');
        self::assertSame('part', ReceivingController::benchState(['checked_at' => null, 'paperwork_ok' => null, 'lines' => 2, 'checked_lines' => 1]));
        self::assertSame('part', ReceivingController::benchState(['checked_at' => '2026-10-07 09:00:00', 'paperwork_ok' => 1, 'lines' => 2, 'checked_lines' => 1]));
        self::assertSame('done', ReceivingController::benchState(['checked_at' => '2026-10-07 09:00:00', 'paperwork_ok' => 1, 'lines' => 2, 'checked_lines' => 2]));
        self::assertSame('refused', ReceivingController::benchState(['checked_at' => '2026-10-07 09:00:00', 'paperwork_ok' => 0, 'lines' => 2, 'checked_lines' => 2]));
        self::assertSame('13 into stock, 2 set aside to check', ReceivingController::went(['accepted' => 13, 'verify' => 2, 'quarantine' => 0, 'refused' => 0, 'short' => 0]));
        self::assertSame('5 to the unstamped quarantine, 1 short (not delivered)', ReceivingController::went(['accepted' => 0, 'quarantine' => 5, 'short' => 1]));
        self::assertSame('0 into stock', ReceivingController::went([]));
        self::assertSame(['3 boxes of 5', '1 case of 10', '12 items', '1 item', '2 packs of 6'], [ReceivingController::packsText(3, 'box', 5),
            ReceivingController::packsText(1, 'case', 10), ReceivingController::packsText(12, 'each', 1), ReceivingController::packsText(1, 'unit', 1),
            ReceivingController::packsText(2, 'each', 6)]);
        self::assertSame('GRN-000001 – Elux Wholesale', ReceivingController::title('GRN-000001', 'posted', 'Elux Wholesale'));
        self::assertSame('Delivery from Elux Wholesale (not booked in yet)', ReceivingController::title(null, 'draft', 'Elux Wholesale'));
        self::assertSame('Delivery from Elux Wholesale', ReceivingController::title(null, 'cancelled', 'Elux Wholesale'));
        self::assertSame(Words::RECEIPT['reaches_none'], ReceivingController::reaches([]));
        self::assertSame(Words::say('RECEIPT', 'reaches', 'Vape and Go (once its stock link is on) and Electrofag'),
            ReceivingController::reaches([['code' => 'vapeandgo', 'name' => 'Vape and Go', 'writer' => false], ['code' => 'electrofag', 'name' => 'Electrofag', 'writer' => true]]));
        $files = ReceivingController::fileRows([['role' => 'evidence', 'mime' => 'image/jpeg', 'size_bytes' => 1536 * 1024]]);
        self::assertSame([Words::RECEIPT_FILE['evidence'], Words::RECEIPT['kind_jpeg'], '1.5 MB'], [$files[0]['role_word'], $files[0]['kind'], $files[0]['size']]);

        // One incident in the register.
        $i = IncidentsController::screenRow(['status' => 'resolved', 'kind' => 'wrong_item', 'units' => 2, 'sku_name' => 'Elux Mint', 'sku_code' => 'CW-000003', 'number' => 'GRN-000001',
            'line_no' => 3, 'external_ref' => null, 'supplier_name' => 'Elux Wholesale', 'disposition' => 'verify', 'opened_at' => '2026-10-07 09:00:00', 'opened_by_name' => 'Sam',
            'resolved_at' => '2026-12-07 09:00:00', 'resolved_by_name' => 'Kim', 'resolved_actor' => 'staff:3', 'resolution' => 'credit asked for']);
        self::assertSame('Wrong product: 2 items of Elux Mint', $i['title']);
        self::assertSame('GRN-000001 line 3 · no invoice number · Elux Wholesale', $i['from']);
        self::assertSame('Where the items are: set aside to check', $i['where']);
        self::assertSame('Found on 7 Oct 2026, 10:00 by Sam.', $i['opened'], 'UK time (British Summer Time)');
        self::assertSame('Dealt with on 7 Dec 2026, 09:00 by Kim: credit asked for', $i['closed']);
    }
}
