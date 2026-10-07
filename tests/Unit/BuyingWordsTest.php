<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\BarcodeSync;
use CW\Catalogue\ItemBarcodes;
use CW\Catalogue\ItemCardList;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Documents\Document;
use CW\Mapping\BarcodeSeeder;
use CW\Mapping\Reband;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Ui\Controller\ItemCardsController;
use CW\Ui\Controller\ItemController;
use CW\Ui\Controller\PurchaseOrdersController;
use CW\Ui\Controller\ReorderController;
use CW\Ui\Controller\SupplierItemsController;
use CW\Ui\Controller\SuppliersController;
use CW\Ui\PoWarnings;
use CW\Ui\ReorderWhy;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The buying pages and the product pages in plain words (plan §6.19-6.30, IM3 glossary pass; the owner's corrections d, e, f):
 * every code of these pages has its words, notices and refusals are sentences, "count" stays a shelf count, and the helpers
 * that put the services' facts into words (Ui\PoWarnings, Ui\ReorderWhy, the controllers' plain()) say what they should.
 */
final class BuyingWordsTest extends TestCase
{
    public function testEveryCodeOfTheBuyingAndProductPagesHasAWord(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0010_purchase_orders.sql');
        self::assertSame(1, preg_match("/source\\s+ENUM\\(([^)]*)\\)/", $sql, $source));
        $decisions = array_keys(BarcodeReviews::RECORDED);
        foreach (BarcodeReviews::DECISIONS as $byReason) {
            $decisions = [...$decisions, ...array_keys($byReason)];
        }
        foreach ([
            'PO_FILTER' => ['draft', 'awaiting_approval', ...PurchaseOrders::STATES],
            'PO_SOURCE' => array_map(static fn (string $v): string => trim($v, " '"), explode(',', $source[1])),
            'SUPPLIER_FIELD' => [...array_keys(Suppliers::LABELS), ...array_keys(Suppliers::FIELDS)],
            'CARD_FIELD' => array_keys(ItemCards::FIELDS),
            'CARD_STATE' => [...array_keys(ItemCardList::STATES), 'warned', 'blocked'],
            'BARCODE_REASON' => array_keys(BarcodeReviews::REASONS),
            'BARCODE_DECISION' => $decisions,
            'BARCODE_SOURCE' => [ItemBarcodes::SOURCE_MANUAL, BarcodeSync::SOURCE, BarcodeSeeder::SOURCE, Reband::SOURCE, 'review'],
            'SEND_VIA' => array_keys(PurchaseOrders::SEND_VIA),
            'REASON' => [...PurchaseOrders::CANCEL_REASONS, ...PurchaseOrders::AMEND_REASONS],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, $code), "{$group}: no word for `{$code}`");
            }
        }
        // The controllers' notices are the Words groups (one place for the words).
        self::assertSame(Words::PO_NOTICE, PurchaseOrdersController::NOTICES);
        self::assertSame(Words::REORDER_NOTICE, ReorderController::NOTICES);
        self::assertSame(Words::SUPPLIER_NOTICE, SuppliersController::NOTICES);
        self::assertSame(Words::SI_NOTICE, SupplierItemsController::NOTICES);
        self::assertSame(Words::CARD_NOTICE, ItemController::NOTICES);
        self::assertSame(Words::REORDER_FLAG, ReorderController::FLAG_TEXT);
    }

    public function testNoticesAndRefusalsAreSentencesAndCountIsAShelfCount(): void
    {
        foreach ([Words::PO_NOTICE, Words::REORDER_NOTICE, Words::SUPPLIER_NOTICE, Words::SI_NOTICE, Words::CARD_NOTICE, Words::BUY_ERROR, Words::CARD_ERROR] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertMatchesRegularExpression('/^[A-Z"%].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        foreach ([Words::ORDERS, Words::ORDER, Words::PO_WARN, Words::REORDER, Words::WHY, Words::REORDER_ITEM, Words::BRANDS, Words::ANOMALIES, Words::SALES,
            Words::SUPPLIERS, Words::SUPPLIER, Words::SUPPLIER_FORM, Words::SUPPLIER_ITEMS, Words::CARDS, Words::CARD, Words::CARD_IMPORT, Words::BARCODE,
            Words::REORDER_NOTICE, Words::BUY_ERROR] as $texts) {
            foreach ($texts as $code => $text) {
                $other = (string) preg_replace('/stock counts?/i', '', $text);
                self::assertDoesNotMatchRegularExpression('/\\bcount(ed|s)?\\b/i', $other, "{$code}: 'count' is only for shelf counts (correction b)");
            }
        }
        // The owner's corrections on these pages.
        self::assertStringContainsString('It is still on sale on the website: take it off by hand.', Words::CARD['blocked'], 'correction d');
        self::assertStringContainsString(Words::REORDER['demand'], Words::BUY_ERROR['rebuild_on_server'], 'correction e');
        self::assertStringContainsString('reviewer', Words::ORDER['confirm_over_does'], 'correction f: over the limit the order goes to a reviewer first');
        self::assertStringContainsString('reviewer', Words::PO['confirm_over_limit_note']);
        self::assertStringContainsString('If your changes take it over', Words::ORDER['confirm_note_limit'], 'true when typed changes cross the limit');
    }

    /**
     * PHP keeps the last of two equal keys in a constant array without a word, so a group that names a key twice silently loses a
     * text (REORDER_ITEM once had two `days`). Read the source: no group of Words repeats a key.
     */
    public function testNoGroupOfWordsRepeatsAKey(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Ui/Words.php');
        self::assertGreaterThan(50, preg_match_all('/public const (\w+) = \[(.*?)\n    \];/s', $src, $groups, PREG_SET_ORDER));
        foreach ($groups as $g) {
            preg_match_all("/^ {8}'([^']+)' =>/m", $g[2], $keys);
            $twice = array_keys(array_filter(array_count_values($keys[1]), static fn (int $n): bool => $n > 1));
            self::assertSame([], $twice, "Words::{$g[1]} names a key twice");
        }
    }

    /** The services' warnings, said again with the glossary's words and the names people know (F262, F263, F267, F270, F284). */
    public function testPoWarningsInWords(): void
    {
        $products = ['CW-000004' => 'Elux Legend Mint'];
        $w = static fn (string $s): string => PoWarnings::one($s, 'Northern Vape Supplies', $products);
        self::assertSame(Words::say('PO_WARN', 'supplier_not_approved', 'Northern Vape Supplies'),
            $w('Supplier NORTHERNVAPE is pending approval: the order can be drafted, but it is approved only once a second person has approved the supplier.'));
        self::assertSame(Words::say('PO_WARN', 'supplier_stopped', 'Northern Vape Supplies'),
            $w('Supplier NORTHERNVAPE is inactive: the order can be drafted, but it is approved only once a second person has approved the supplier.'));
        self::assertSame(Words::say('PO_WARN', 'route', 'Northern Vape Supplies'),
            $w('Supplier NORTHERNVAPE is overseas and its import route is not approved: the order cannot be approved until it is.'));
        self::assertSame(Words::say('PO_WARN', 'route_change', 'Northern Vape Supplies'),
            $w("A change of NORTHERNVAPE's import route or overseas status waits for a second person: the order cannot be approved until it is approved."));
        self::assertSame(Words::say('PO_WARN', 'dd_due', 'Northern Vape Supplies', '1 Oct 2026'), $w('Due diligence of NORTHERNVAPE is overdue: the next review was due on 2026-10-01.'));
        self::assertSame('The total before VAT, £60.00, is below the smallest order Northern Vape Supplies takes (£100.00).',
            $w("The net total £60.00 is below NORTHERNVAPE's minimum order of £100.00."));
        self::assertSame('Line 3 (Elux Legend Mint) has no price. Type the price per pack, or the PDF shows £0.00.', $w('Line 3 (CW-000004) has a price of £0.'));
        self::assertSame(Words::say('PO_WARN', 'merged', 2, 'Elux Legend Mint', 'CW-000009'),
            $w('Line 2: item CW-000004 was merged into CW-000009: replace the line (the order cannot be saved or approved with it).'));
        self::assertSame(Words::say('PO_WARN', 'switched_off', 2, 'Elux Legend Mint'),
            $w('Line 2: the supplier item of CW-000004 was switched off: remove the line or switch it on again.'));
        self::assertSame(Words::say('PO_WARN', 'blocked', 1, 'Elux Legend Mint', 'tank or pod over 2 ml'),
            $w('Line 1: CW-000004 is blocked by its item card (tank or pod over 2 ml): the order cannot be approved with it.'));
        self::assertSame(Words::say('PO_WARN', 'card_warning', 1, 'Elux Legend Mint', 'nicotine over 20 mg/ml'),
            $w("Line 1: CW-000004's item card, not confirmed yet, says nicotine over 20 mg/ml: check it before ordering (once confirmed, it blocks the item)."));
        self::assertSame(Words::say('PO_WARN', 'card_warning_changed', 1, 'CW-000005', 'nicotine over 20 mg/ml'),
            $w("Line 1: CW-000005's item card, not confirmed since it changed, says nicotine over 20 mg/ml: check it before ordering (once confirmed, it blocks the item)."),
            'a product without a name in the map is named by its CW number');
        self::assertSame(Words::say('PO_WARN', 'discontinued', 4, 'Elux Legend Mint'), $w('Line 4: CW-000004 is marked discontinued on its item card.'));
        self::assertSame('A sentence the screens do not know yet.', $w('A sentence the screens do not know yet.'), 'an unknown warning is never lost');

        $send = PoWarnings::send(['Its review was rejected: cancel or amend it rather than send it.',
            'The company details it was approved with are not confirmed: its PDF says "company details not confirmed - do not send".']);
        self::assertSame([Words::PO_WARN['send_rejected'], Words::PO_WARN['send_company']], $send['texts']);
        self::assertTrue($send['company']);
        self::assertFalse(PoWarnings::send(['Its review was rejected: cancel or amend it rather than send it.'])['company']);

        self::assertSame('Row 3: no product given. Fill in the CW number (cw_code), their product code (supplier_code) or the barcode (barcode).',
            PoWarnings::fileRow('row 3, cw_code: give cw_code, supplier_code or barcode'));
        self::assertSame('Row 2 (packs): Required: a whole number of packs from 1 to 1,000,000.',
            PoWarnings::fileRow('row 2, packs: required: a whole number of packs from 1 to 1,000,000'));
        self::assertSame('The file has no lines.', PoWarnings::fileRow('the file has no lines'));
    }

    /** "Why this amount?" in sentences (F308); the formula stays in "Show the maths". */
    public function testReorderWhyInSentences(): void
    {
        $line = ['rate_e4' => 20_000, 'd_e6' => 1_700_000, 'factor_e2' => 85, 'factor_source' => 'item', 'lead' => 2, 'review' => 7, 'safety' => 5, 'cover_days' => 14,
            'target' => 24, 'min_applied' => false, 'max_applied' => false, 'stock_source' => 'cw', 'available' => 0, 'on_order' => 0, 'need' => 24, 'packs' => 3, 'units' => 30,
            'upp' => 10, 'moq' => 1, 'mult' => 1, 'moq_applied' => false, 'mult_applied' => false, 'rounding' => 'up', 'never' => null, 'urgent' => true, 'in_drafts' => 0,
            'flags' => ['urgent'], 'demand_detail' => ['listings' => [['channel' => 'vapeandgo', 'excluded' => ['promo' => 2], 'anomalies' => [
                ['label' => 'Pre-duty stockpiling (+43% units/day)', 'from' => '2026-09-14', 'to' => '2026-09-22', 'short' => 9, 'long' => 9]]]]]];
        self::assertSame([
            'Sells about 2.0 a day. Left out: Pre-duty stockpiling 14–22 Sep, 2 promotion days.',
            'Expected to sell 15% fewer (this product\'s setting): about 1.7 a day.',
            'Delivery takes 2 days and you order every 7 days, plus 5 spare days = 14 days.',
            '14 × 1.7 = 24 to have.',
            'You have 0 and 0 on order.',
            'So buy 24 → 3 packs of 10 = 30.',
            'Urgent: you will run out before the next delivery.',
        ], ReorderWhy::sentences($line));
        $never = ReorderWhy::sentences(['never' => 'merged into CW-000009', 'flags' => ['merged'], 'need' => 5, 'demand_detail' => ['listings' => []], 'rate_e4' => 0]
            + $line);
        self::assertSame(Words::WHY['sells_none'], $never[0]);
        self::assertContains(Words::say('WHY', 'never', Words::say('WHY', 'never_merged', 'CW-000009')), $never);
        self::assertContains(Words::say('WHY', 'drafts', 72), ReorderWhy::sentences(['in_drafts' => 72] + $line));
        self::assertSame('no sales', ReorderWhy::daysLeft(null), 'never ∞ (F318)');
        self::assertSame('12.5', ReorderWhy::daysLeft(125));
    }

    /** The services' refusals, by their error code, in the pages' words (rule 18: the services' own messages stay the API's). */
    public function testTheBuyingPagesSayARefusalInWords(): void
    {
        $e = static fn (string $code, string $msg = 'the service text', array $detail = []): CwException => new CwException($code, $msg, 409, $detail);
        self::assertSame(Words::BUY_ERROR['supplier_not_active'], PurchaseOrdersController::plain($e('supplier_not_active')));
        self::assertSame(Words::BUY_ERROR['send_warnings'], PurchaseOrdersController::plain($e('send_warnings')));
        self::assertSame(Words::ERROR['bad_form_key'], PurchaseOrdersController::plain($e('bad_form_key')), 'the kernel\'s own words');
        self::assertSame('Line 2, packs: a whole number (0 removes the line). Nothing was saved.',
            PurchaseOrdersController::plain($e('bad_line', 'line 2, packs: a whole number (0 removes the line)')));
        self::assertSame('The service text. Nothing was saved.', PurchaseOrdersController::plain($e('some_new_code')), 'a code without words: the service\'s message');
        self::assertSame(Words::BUY_ERROR['nothing_picked'], ReorderController::plain($e('nothing_picked')));
        self::assertSame(Words::BUY_ERROR['version_conflict_settings'], ReorderController::plain($e('version_conflict')));
        self::assertSame('A window covers at most 93 days. Nothing was saved.', ReorderController::plain($e('bad_field', 'a window covers at most 93 days')));
        self::assertSame(Words::say('BUY_ERROR', 'supplier_incomplete', 'address, e-mail or phone, background check date'),
            SuppliersController::plain($e('supplier_incomplete', 'complete these', ['missing' => ['address_line1', 'email_or_phone', 'dd_checked_on']])));
        self::assertSame(['field' => 'email', 'text' => 'E-mail: is not an e-mail address.'],
            SuppliersController::fieldError($e('bad_field', 'e-mail: is not an e-mail address', ['field' => 'email'])));
        self::assertSame(['field' => 'country', 'text' => Words::SUPPLIER_FORM['e_country']],
            SuppliersController::fieldError($e('bad_field', 'country: a two-letter ISO country code (GB, CN, ...)', ['field' => 'country'])));
        self::assertSame('Smallest order: an amount in £ with at most 2 decimals.',
            SuppliersController::fieldError($e('bad_field', 'minimum order value: an amount in GBP with at most 2 decimals', ['field' => 'min_order_value']))['text']);
        self::assertSame(Words::BUY_ERROR['duplicate_pack'], SupplierItemsController::plain($e('duplicate_pack')));
        self::assertSame(Words::CARD_ERROR['card_changed_page'], ItemCardsController::plain($e('card_changed')));
        self::assertSame(Words::say('CARD_ERROR', 'card_breaches', 'tank or pod over 2 ml'),
            ItemCardsController::plain($e('card_breaches', 'These fields break the law', ['rules' => ['trpr_tank_ml']])));
        self::assertSame(Words::CARD_ERROR['no_card'], ItemCardsController::plain($e('card_incomplete')));
    }

    public function testOrdersAndProductsAsPeopleReadThem(): void
    {
        $doc = static fn (string $status, ?string $number): Document => Document::fromRow(['id' => 7, 'doc_type' => 'PO', 'number' => $number, 'status' => $status,
            'version' => 1, 'external_ref' => null, 'doc_date' => '2026-10-07', 'warehouse_id' => 1, 'reason_code' => null, 'note' => null, 'created_by' => 1,
            'created_actor' => 'staff:1', 'created_at' => '2026-10-07 09:00:00', 'updated_at' => '2026-10-07 09:00:00', 'submitted_by' => null, 'submitted_at' => null,
            'posted_by' => null, 'posted_actor' => null, 'posted_at' => null, 'posted_hash' => null, 'reverses_id' => null, 'cancelled_by' => null, 'cancelled_at' => null,
            'cancel_reason' => null, 'review_state' => null]);
        self::assertSame('New order for Elux Wholesale (draft, no PO number yet)', PurchaseOrdersController::title($doc('draft', null), 'Elux Wholesale'), 'F259');
        self::assertSame('Order for Elux Wholesale (no number yet)', PurchaseOrdersController::title($doc('awaiting_approval', null), 'Elux Wholesale'));
        self::assertSame('PO-000001 – Elux Wholesale', PurchaseOrdersController::title($doc('posted', 'PO-000001'), 'Elux Wholesale'));
        self::assertSame('draft #7', $doc('draft', null)->label(), 'Document::label() stays for file names and service messages');
        self::assertSame('each', PurchaseOrdersController::pack('each', 1));
        self::assertSame('box of 24', PurchaseOrdersController::pack('box', 24));

        $row = ['id' => 7, 'number' => null, 'status' => 'draft', 'state' => null, 'review_state' => null, 'reverses_id' => null, 'reverses_number' => null,
            'doc_date' => '2026-10-07', 'expected_date' => '2026-10-14', 'lines' => 1, 'units' => 48, 'net_total' => '10500.00', 'supplier_name' => 'Elux Wholesale',
            'cancelled_by' => null, 'cancelled_on' => null, 'sent_at' => null, 'sent_via' => null];
        $draft = PurchaseOrdersController::screenRow($row, true);
        self::assertSame(['Elux Wholesale', Words::ORDERS['no_number'], 'draft', Words::ORDERS['next_draft'], '£10,500.00', '1 product', '48 items'],
            [$draft['name'], $draft['number_line'], $draft['state_code'], $draft['next'], $draft['total'], $draft['size_line'], $draft['items_line']]);
        self::assertSame(Words::ORDERS['next_draft_look'], PurchaseOrdersController::screenRow($row, false)['next'], 'F305 in words: a draft is not ordered yet');
        $sent = PurchaseOrdersController::screenRow(['number' => 'PO-000001', 'status' => 'posted', 'state' => 'sent', 'review_state' => 'rejected',
            'sent_at' => '2026-10-07 09:26:00', 'sent_via' => 'email'] + $row, true);
        self::assertSame(['PO-000001', 'sent', Words::ORDERS['next_rejected'], 'Sent 7 Oct 2026, 10:26, by e-mail', Words::CHECK_STATE['rejected']],
            [$sent['number_line'], $sent['state_code'], $sent['next'], $sent['sent_line'], $sent['check']]);
        $cancelled = PurchaseOrdersController::screenRow(['number' => 'PO-000003', 'status' => 'reversed', 'state' => 'approved', 'cancelled_by' => 'PO-000004',
            'cancelled_on' => '2026-10-07 09:26:00'] + $row, true);
        self::assertSame(['cancelled', 'Cancelled on 7 Oct 2026 (cancellation record PO-000004).'], [$cancelled['state_code'], $cancelled['next']], 'F296');

        // Where a product card suggestion comes from, in words.
        $sites = ['vapeandgo' => 'Vape and Go'];
        self::assertSame(Words::CARD['src_identity'], ItemController::source("the item's identity card (filled by the matcher)", $sites));
        self::assertSame('website product "Elux Legend Cola" (Vape and Go, option 601)', ItemController::source('listing vapeandgo 601 "Elux Legend Cola"', $sites));
        self::assertSame('website product (Vape and Go, option 601)', ItemController::source('listing vapeandgo 601', $sites));
    }
}
