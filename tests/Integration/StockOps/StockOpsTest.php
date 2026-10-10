<?php

declare(strict_types=1);

namespace CW\Tests\Integration\StockOps;

use CW\Admin\ConfigInvariants;
use CW\Admin\DocumentRules;
use CW\Admin\ReasonCodes;
use CW\StockOps\CostHints;
use CW\StockOps\StockOpHandler;

/**
 * Stock In, Stock Out, Adjustments and Transfers (pack A1; docs/decisions.md SO1-SO16) through the service, against the real document
 * base: each kind drafted, posted and reversed with its ledger rows, balances and value sequence (the invariants after every test);
 * the "given to" of samples and staff use; never below zero for a protected product unless the reason allows it; the approvals (the
 * stock put back without a supplier document, the OK first for a big record, on and off); transfers that never change whose stock it
 * is; moves between places that book nothing; the number series; who may do what.
 */
final class StockOpsTest extends StockOpsTestCase
{
    public function testAStockInIsBookedAtItsCostAndItsReversalTakesItOffAgain(): void
    {
        $sku = $this->item('strict', 5);
        $other = $this->item('legacy', 0);
        $sc = $this->staffUser('stock_controller');
        $doc = $this->posted('in', $sc, ['reason_code' => 'found', 'external_ref' => 'shelf B'], [
            ['sku_id' => $sku, 'qty' => 10, 'unit_cost' => '1.25'],
            ['sku_id' => $other, 'qty' => 3],
        ]);
        self::assertSame(['posted', 'SIN-000001', 'not_required'], [$doc->status, $doc->number, $doc->reviewState], 'no reviewer check by default (extra checks off)');
        self::assertSame([['MAIN', 'adjustment', 10, '1.250000'], ['MAIN', 'adjustment', 3, null]], $this->ledgerOf($doc->id));
        self::assertSame([15, 3], [$this->onHand($sku), $this->onHand($other)]);
        self::assertSame('document', self::$db->value('SELECT cost_source FROM stock_ledger WHERE document_id = ? AND unit_cost IS NOT NULL', [$doc->id]));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE l.document_id = ?', [$doc->id]));
        self::assertSame(['1.250000'], array_values((new CostHints(self::$db))->average([$sku])), 'the average cost so far: the units that came in with a cost');

        $rev = $this->ops->reverse($sc, $doc->id, 'entered_in_error', null);
        self::assertSame(['posted', 'SIN-000002', $doc->id], [$rev->status, $rev->number, $rev->reversesId]);
        self::assertSame('reversed', $this->docs->get($doc->id)->status);
        self::assertSame([['MAIN', 'adjustment', -10, '1.250000'], ['MAIN', 'adjustment', -3, null]], $this->ledgerOf($rev->id));
        self::assertSame([5, 0], [$this->onHand($sku), $this->onHand($other)]);
        self::assertSame('in', self::$db->value('SELECT kind FROM stock_op WHERE document_id = ?', [$rev->id]), 'the cancellation says the same header');
    }

    public function testAStockInTakesOnlyItsOwnReasonsAndRefusesNegativeQuantities(): void
    {
        $sku = $this->item('strict', 0);
        $sc = $this->staffUser('stock_controller');
        self::refusedWith('reason_not_applicable', fn () => $this->draft('in', $sc, ['reason_code' => 'damaged'], []));
        self::refusedWith('qty_positive', fn () => $this->draft('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => -2]]));
        $d = $this->draft('in', $sc, [], [['sku_id' => $sku, 'qty' => 2]]);
        self::refusedWith('reason_required', fn () => $this->ops->post($sc, $d->id, $d->version));
        $d = $this->ops->saveHeader($sc, $d->id, $this->docs->get($d->id)->version, ['reason_code' => 'opening_stock']);
        self::assertSame('posted', $this->ops->post($sc, $d->id, $d->version)->status);
        self::assertSame(2, $this->onHand($sku));
    }

    public function testAStockOutNeedsGivenToForSamplesAndStaffUseAndNeverGoesBelowZeroUnlessTheReasonAllows(): void
    {
        $sku = $this->item('strict', 5);
        $desk = $this->staffUser('purchasing_desk');
        $d = $this->draft('out', $desk, ['reason_code' => 'sample'], [['sku_id' => $sku, 'qty' => 3]]);
        self::refusedWith('given_to_required', fn () => $this->ops->post($desk, $d->id, $d->version));
        $d = $this->ops->saveHeader($desk, $d->id, $d->version, ['given_to' => 'Ali at the trade show']);
        $d = $this->ops->saveLines($desk, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 7]]);
        $e = self::refusedWith('below_zero', fn () => $this->ops->post($desk, $d->id, $d->version));
        self::assertSame([1, 5, 7], [$e->detail['line'], $e->detail['have'], $e->detail['wanted']]);
        $d = $this->ops->saveLines($desk, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 3]]);
        $out = $this->ops->post($desk, $d->id, $d->version);
        self::assertSame(['posted', 'SOUT-000001'], [$out->status, $out->number]);
        self::assertSame([['MAIN', 'stock_out', -3, null]], $this->ledgerOf($out->id));
        self::assertSame('Ali at the trade show', self::$db->value('SELECT given_to FROM stock_op WHERE document_id = ?', [$out->id]));
        self::assertSame(2, $this->onHand($sku));

        // Staff use needs the name too (a new reason with the rule, 0022).
        self::refusedWith('given_to_required', fn () => $this->posted('out', $desk, ['reason_code' => 'staff_use'], [['sku_id' => $sku, 'qty' => 1]]));
        // A trade sale does not; past zero only when the Reasons page lets the reason do it.
        self::refusedWith('below_zero', fn () => $this->posted('out', $desk, ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 4]]));
        $this->reasonRule('trade_sale', ['below_zero' => 1]);
        $sold = $this->posted('out', $desk, ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 4]]);
        self::assertSame(-2, $this->onHand($sku));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE sku_id = ? AND source = 'negative_on_hand'", [$sku]), 'below zero opens a count review');
        // An unprotected product is not held to it (Stock.php: legacy is not protected yet).
        $legacy = $this->item('legacy', 1);
        $this->posted('out', $desk, ['reason_code' => 'repair_return'], [['sku_id' => $legacy, 'qty' => 2]]);
        self::assertSame(-1, $this->onHand($legacy));

        // A stock out's cancellation puts the units back; over the "stock put back" limit (ADJ: 10 units) it waits for an OK (I32).
        $rev = $this->ops->reverse($desk, $sold->id, 'entered_in_error', null);
        self::assertSame('posted', $rev->status);
        self::assertSame(2, $this->onHand($sku));
        $big = $this->posted('in', $this->staffUser('stock_controller'), ['reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 20]]);
        self::assertSame('posted', $big->status);
        $many = $this->posted('out', $desk, ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 15]]);
        $wait = $this->ops->reverse($desk, $many->id, 'entered_in_error', null);
        self::assertSame(['awaiting_approval', 15], [$wait->status, (int) self::$db->value('SELECT units FROM review_task WHERE subject_id = ?', [$wait->id])]);
    }

    public function testAStockOutNeverTakesAnotherAccountsStock(): void
    {
        $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $sku = $this->item('legacy', 0);
        $desk = $this->staffUser('purchasing_desk');
        self::refusedWith('not_own_stock', fn () => $this->draft('out', $desk, ['warehouse' => 'VPG2', 'reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 1]]));
    }

    public function testAnAdjustmentBooksWriteOffsAndCorrectionsByItsReasonsAndIsReversedByANewRecord(): void
    {
        $sku = $this->item('strict', 10);
        $sc = $this->staffUser('stock_controller');
        $adj = $this->posted('adjust', $sc, ['note' => 'shelf B recount'], [
            ['sku_id' => $sku, 'qty' => 2, 'reason_code' => 'found', 'unit_cost' => '3.00'],
            ['sku_id' => $sku, 'qty' => -3, 'reason_code' => 'damaged'],
            ['sku_id' => $sku, 'qty' => -1, 'reason_code' => 'data_correction'],
            ['sku_id' => $sku, 'qty' => -1, 'reason_code' => 'written_off'],
        ]);
        self::assertSame(['posted', 'ADJ-000001', 'pending'], [$adj->status, $adj->number, $adj->reviewState], 'ADJ keeps its reviewer check (every one)');
        self::assertSame([['MAIN', 'write_off', -3, null], ['MAIN', 'write_off', -1, null], ['MAIN', 'adjustment', 2, '3.000000'], ['MAIN', 'adjustment', -1, null]],
            $this->ledgerOf($adj->id), 'a decrease whose reason is also offered for write-offs is a write-off; a correction stays an adjustment');
        self::assertSame(7, $this->onHand($sku));
        $rev = $this->ops->reverse($sc, $adj->id, 'duplicate', null);
        self::assertSame(['posted', 'ADJ-000002'], [$rev->status, $rev->number]);
        self::assertSame(10, $this->onHand($sku));
        self::assertSame('withdrawn', self::$db->value("SELECT state FROM review_task WHERE subject_id = ? AND kind = 'review'", [$adj->id]), 'the cancelled record\'s check closes');

        self::refusedWith('reason_direction', fn () => $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => -1, 'reason_code' => 'found']]));
        self::refusedWith('reason_required', fn () => $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => 1]]));
        self::refusedWith('qty_nonzero', fn () => $this->draft('adjust', $sc, [], [['sku_id' => $sku, 'qty' => 0, 'reason_code' => 'found']]));
        self::refusedWith('cost_not_allowed', fn () => $this->draft('adjust', $sc, [], [['sku_id' => $sku, 'qty' => -1, 'reason_code' => 'damaged', 'unit_cost' => '1']]));
        self::refusedWith('below_zero', fn () => $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => -11, 'reason_code' => 'lost_theft']]));
    }

    public function testStockPutBackWithoutASupplierDocumentWaitsForAnOkAndASupplierDocumentLiftsIt(): void
    {
        $sku = $this->item('strict', 0);
        $sc = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $five = $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => 5, 'reason_code' => 'found']]);
        self::assertSame('posted', $five->status);
        $six = $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => 6, 'reason_code' => 'found']]);
        self::assertSame('awaiting_approval', $six->status, 'the same person\'s positive units of the day count together (5 + 6 > 10)');
        self::assertSame(['positive_without_supplier_doc', 11], array_values((array) self::$db->one('SELECT reason, units FROM review_task WHERE subject_id = ?', [$six->id])));
        $approved = $this->docs->approve($rev, $this->openTask($six->id), null);
        self::assertSame(['posted', 11], [$approved->status, $this->onHand($sku)]);

        // A stock in has the same rule, off unless switched on.
        $in = $this->posted('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 50]]);
        self::assertSame('posted', $in->status);
        $this->rule('SIN', ['approval_rule' => 'positive_without_supplier_doc']);
        $wait = $this->posted('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 50]]);
        self::assertSame('awaiting_approval', $wait->status);
        $this->ops->withdraw($sc, $wait->id);
        $file = $this->dir . '/note.pdf';
        file_put_contents($file, (new \CW\Output\PdfWriter('Delivery note ' . bin2hex(random_bytes(4)), 'test'))->output());
        $this->ops->attach($sc, $wait->id, $file, 'delivery-note.pdf', 'delivery_note');
        self::assertSame('posted', $this->ops->post($sc, $wait->id, $this->docs->get($wait->id)->version)->status, 'a delivery note is the supplier document');
    }

    public function testTheOkFirstForABigRecordIsOffByDefaultAndWorksOnUnitsAndPounds(): void
    {
        $sku = $this->item('strict', 0);
        $sc = $this->staffUser('stock_controller');
        $rev = $this->staffUser('reviewer');
        $this->posted('in', $sc, ['reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 200, 'unit_cost' => '2.00']]);
        self::assertSame('posted', $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => -50, 'reason_code' => 'damaged']])->status, 'off by default');

        // On, by units (the Approval Rules page's service, a reviewer).
        $this->rule('ADJ', []); // put back as it was in tearDown
        $r = (new DocumentRules(self::$db))->set($rev, 'ADJ', ['size' => true, 'size_units' => '20', 'size_value' => ''], 'big write-offs need a second person');
        self::assertTrue($r['changed']);
        $big = $this->posted('adjust', $sc, [], [['sku_id' => $sku, 'qty' => -30, 'reason_code' => 'damaged']]);
        self::assertSame('awaiting_approval', $big->status);
        self::assertSame(['over_size', 30], array_values((array) self::$db->one('SELECT reason, units FROM review_task WHERE subject_id = ?', [$big->id])));
        self::assertSame(150, $this->onHand($sku), 'nothing booked until the OK');
        $ok = $this->docs->approve($rev, $this->openTask($big->id), 'checked the bin');
        self::assertSame(['posted', 120], [$ok->status, $this->onHand($sku)]);

        // By pounds: 60 units at the average cost so far (2.00) is 120 pounds, over 100.
        $this->rule('SOUT', ['size_approval' => 1, 'size_units' => null, 'size_value' => 100]);
        $sale = $this->posted('out', $this->staffUser('purchasing_desk'), ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 60]]);
        self::assertSame('awaiting_approval', $sale->status);
        self::assertSame('posted', $this->posted('out', $this->staffUser('purchasing_desk'), ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 50]])->status);

        // Switching it off is looser: a reviewer may, an admin may not (I1).
        $admin = $this->staffUser('admin');
        self::refusedWith('loosen_needs_reviewer', fn () => (new DocumentRules(self::$db))->set($admin, 'ADJ', ['size' => false], 'too many checks'));
        self::refusedWith('size_required', fn () => (new DocumentRules(self::$db))->set($rev, 'TRF', ['size' => true, 'size_units' => '', 'size_value' => ''], 'switch it on'));
        self::refusedWith('no_size_rule', fn () => (new DocumentRules(self::$db))->set($rev, 'PO', ['size' => true, 'size_units' => '5'], 'switch it on'));
        self::assertTrue((new DocumentRules(self::$db))->set($rev, 'ADJ', ['size' => false], 'back to the default')['changed']);
        self::assertSame([], array_values(array_filter(ConfigInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, 'document_rule ADJ')
            || str_contains($v, 'document_rule SIN'))), 'every change is a version of the rule');
    }

    public function testATransferMovesStockBetweenWarehousesOfTheSameOwnerOnly(): void
    {
        $this->warehouse('SHOP');
        $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $sku = $this->item('strict', 10);
        $sc = $this->staffUser('stock_controller');
        $t = $this->posted('transfer', $sc, ['to_warehouse' => 'SHOP'], [['sku_id' => $sku, 'qty' => 4]]);
        self::assertSame(['posted', 'TRF-000001'], [$t->status, $t->number]);
        self::assertSame([['MAIN', 'transfer_out', -4, null], ['SHOP', 'transfer_in', 4, null]], $this->ledgerOf($t->id));
        self::assertSame([6, 4], [$this->onHand($sku), $this->onHand($sku, 'SHOP')]);
        self::assertSame(['own', 'own', 'other'], array_map('strval', self::$db->column("SELECT CAST(stock_owner AS CHAR) FROM warehouse WHERE code IN ('MAIN', 'SHOP', 'VPG2') ORDER BY code = 'VPG2', code")),
            'a transfer never changes whose stock it is');
        self::refusedWith('owner_differs', fn () => $this->draft('transfer', $sc, ['to_warehouse' => 'VPG2'], []));
        self::refusedWith('owner_differs', fn () => $this->draft('transfer', $sc, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN'], []));
        self::refusedWith('below_zero', fn () => $this->posted('transfer', $sc, ['to_warehouse' => 'SHOP'], [['sku_id' => $sku, 'qty' => 7]]));
        $d = $this->draft('transfer', $sc, [], [['sku_id' => $sku, 'qty' => 1]]);
        self::refusedWith('to_warehouse_required', fn () => $this->ops->post($sc, $d->id, $d->version));

        $rev = $this->ops->reverse($sc, $t->id, 'entered_in_error', null);
        self::assertSame('posted', $rev->status, 'a transfer nets to zero per item: its cancellation needs no OK (I32)');
        self::assertSame([10, 0], [$this->onHand($sku), $this->onHand($sku, 'SHOP')]);
    }

    public function testAMoveBetweenTwoPlacesOfOneWarehouseBooksNothing(): void
    {
        $shelf = $this->place('MAIN', 'A-01');
        $overflow = $this->place('MAIN', 'OVERFLOW');
        $sku = $this->item('strict', 10);
        $sc = $this->staffUser('warehouse');
        $before = $this->ledgerCount();
        $t = $this->posted('transfer', $sc, ['location' => $shelf, 'to_warehouse' => 'MAIN', 'to_location' => $overflow], [['sku_id' => $sku, 'qty' => 6]]);
        self::assertSame(['posted', 'TRF-000001'], [$t->status, $t->number]);
        self::assertSame([$before, 10], [$this->ledgerCount(), $this->onHand($sku)], 'stock is not split by place: the record says where it went');
        self::assertSame([$shelf, $overflow], array_map('intval', array_values((array) self::$db->one('SELECT location_id, to_location_id FROM stock_op WHERE document_id = ?', [$t->id]))));
        self::refusedWith('same_place', fn () => $this->posted('transfer', $sc, ['location' => $shelf, 'to_warehouse' => 'MAIN', 'to_location' => $shelf], [['sku_id' => $sku, 'qty' => 1]]));
        self::refusedWith('same_place', fn () => $this->posted('transfer', $sc, ['to_warehouse' => 'MAIN'], [['sku_id' => $sku, 'qty' => 1]]));
        $this->warehouse('SHOP');
        self::refusedWith('location_mismatch', fn () => $this->draft('transfer', $sc, ['location' => $shelf, 'warehouse' => 'SHOP'], []));
        $rev = $this->ops->reverse($sc, $t->id, 'entered_in_error', null);
        self::assertSame([$before, 'reversed'], [$this->ledgerCount(), $this->docs->get($t->id)->status]);
        self::assertSame('posted', $rev->status);
    }

    public function testAReleaseMovesTheOtherAccountsStockIntoOursAndTheBalanceOwedFollowsPaymentsAndCancellations(): void
    {
        $vpg2 = $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $sku = $this->item('strict', 3);
        $sc = $this->staffUser('stock_controller');
        $desk = $this->staffUser('purchasing_desk');
        // What the room holds is recorded first (a stock in into the other account's warehouse).
        $this->posted('in', $sc, ['warehouse' => 'VPG2', 'reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 50]]);
        self::assertSame(['50', '0.00'], [(string) $this->onHand($sku, 'VPG2'), $this->accounts->balance($vpg2)]);

        $rel = $this->posted('release', $desk, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN', 'external_ref' => 'VPG2-INV-7'],
            [['sku_id' => $sku, 'qty' => 20, 'unit_cost' => '1.555']]);
        self::assertSame(['posted', 'REL-000001'], [$rel->status, $rel->number]);
        self::assertSame([['VPG2', 'transfer_out', -20, null], ['MAIN', 'goods_in', 20, '1.555000']], $this->ledgerOf($rel->id),
            'out of the other account\'s room, into ours as a purchase at the agreed price');
        self::assertSame([30, 23], [$this->onHand($sku, 'VPG2'), $this->onHand($sku)]);
        self::assertSame('31.10', $this->accounts->balance($vpg2), '20 x 1.555 = 31.10 to the penny');
        self::assertSame('VPG Two Ltd', self::$db->value('SELECT account_name FROM stock_op WHERE document_id = ?', [$rel->id]));
        $acc = $this->accounts->accounts()[0];
        self::assertSame([30, 20, '31.10', '31.10'], [$acc['holds_units'], $acc['month_units'], $acc['month_amount'], $acc['balance']]);

        self::refusedWith('more_than_owed', fn () => $this->accounts->recordPayment($desk, $vpg2, '40', gmdate('Y-m-d'), 'BACS 1', null));
        self::refusedWith('role_not_allowed', fn () => $this->accounts->recordPayment($sc, $vpg2, '10', gmdate('Y-m-d'), 'BACS 1', null));
        self::refusedWith('bad_date', fn () => $this->accounts->recordPayment($desk, $vpg2, '10', '2999-01-01', 'BACS 1', null));
        $paid = $this->accounts->recordPayment($desk, $vpg2, '10.00', gmdate('Y-m-d', time() - 86400), 'BACS 1', 'first part');
        self::assertSame('21.10', $paid['balance']);
        $back = $this->accounts->reversePayment($desk, $paid['entry_id'], 'paid from the wrong account');
        self::assertSame('31.10', $back['balance']);
        self::refusedWith('already_reversed', fn () => $this->accounts->reversePayment($desk, $paid['entry_id'], 'again'));
        $this->accounts->recordPayment($desk, $vpg2, '31.10', gmdate('Y-m-d'), 'BACS 2', null);
        self::assertSame('0.00', $this->accounts->balance($vpg2));
        self::refusedWith('nothing_owed', fn () => $this->accounts->recordPayment($desk, $vpg2, '1', gmdate('Y-m-d'), 'BACS 3', null));

        // A cancellation of the release puts the stock back in the room and takes its amount off (the payment stays: -31.10 is a credit).
        $rev = $this->ops->reverse($desk, $rel->id, 'entered_in_error', null);
        self::assertSame('posted', $rev->status);
        self::assertSame([50, 3], [$this->onHand($sku, 'VPG2'), $this->onHand($sku)]);
        self::assertSame('-31.10', $this->accounts->balance($vpg2));
        self::assertSame(['release_reversal', '-31.10'], array_values((array) self::$db->one('SELECT kind, amount FROM other_account_entry WHERE document_id = ?', [$rev->id])));

        // Never more than the room holds, whatever the product.
        $legacy = $this->item('legacy', 0);
        self::refusedWith('not_enough_held', fn () => $this->posted('release', $desk, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN'], [['sku_id' => $legacy, 'qty' => 1, 'unit_cost' => '1']]));
        self::refusedWith('price_required', fn () => $this->posted('release', $desk, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN'], [['sku_id' => $sku, 'qty' => 1]]));
    }

    public function testAReleasesPriceDefaultsToTheSupplierPriceElseTheAverageCost(): void
    {
        $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $sku = $this->item('strict', 0);
        $sc = $this->staffUser('stock_controller');
        $this->posted('in', $sc, ['reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 10, 'unit_cost' => '2.50']]);
        $this->posted('in', $sc, ['warehouse' => 'VPG2', 'reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 10]]);
        $d = $this->draft('release', $sc, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN'], []);
        $added = $this->ops->addLine($sc, $d->id, $d->version, 'CW-' . $sku, 4);
        self::assertSame(['added', 4], [$added['status'], $added['units']]);
        self::assertSame('2.500000', (string) self::$db->value('SELECT unit_cost FROM document_line WHERE document_id = ?', [$d->id]), 'the average cost so far');
        $again = $this->ops->addLine($sc, $d->id, $added['document']->version, (string) $sku, 2);
        self::assertSame(['incremented', 6], [$again['status'], (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ?', [$d->id])]);
        self::assertSame('not_found', $this->ops->addLine($sc, $d->id, $again['document']->version, 'no such product anywhere', 1)['status']);
    }

    public function testATransferOrAReleaseNeverChangesOwnershipTheWrongWay(): void
    {
        $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $this->warehouse('VPG3', 'other', 'Another Ltd');
        $desk = $this->staffUser('purchasing_desk');
        self::refusedWith('release_from_own', fn () => $this->draft('release', $desk, ['warehouse' => 'MAIN', 'to_warehouse' => 'MAIN'], []));
        self::refusedWith('release_to_other', fn () => $this->draft('release', $desk, ['warehouse' => 'VPG2', 'to_warehouse' => 'VPG3'], []));
        self::refusedWith('owner_differs', fn () => $this->draft('transfer', $this->staffUser('stock_controller'), ['warehouse' => 'VPG2', 'to_warehouse' => 'VPG3'], []));
    }

    public function testEachKindHasItsOwnGaplessNumberSeries(): void
    {
        $sku = $this->item('strict', 100);
        $sc = $this->staffUser('stock_controller');
        $a = $this->posted('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 1]]);
        $stopped = $this->draft('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 1]]);
        $this->ops->cancel($sc, $stopped->id, $stopped->version, 'keyed twice');
        $b = $this->posted('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 1]]);
        $c = $this->posted('out', $sc, ['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 1]]);
        $this->warehouse('SHOP');
        $t = $this->posted('transfer', $sc, ['to_warehouse' => 'SHOP'], [['sku_id' => $sku, 'qty' => 1]]);
        self::assertSame(['SIN-000001', 'SIN-000002', 'SOUT-000001', 'TRF-000001'], [$a->number, $b->number, $c->number, $t->number],
            'a stopped draft takes no number');
        self::assertSame(['SIN' => 2, 'SOUT' => 1, 'TRF' => 1, 'REL' => 0], array_map('intval',
            array_column(self::$db->all("SELECT prefix, last_no FROM number_series WHERE prefix IN ('SIN', 'SOUT', 'TRF', 'REL') ORDER BY FIELD(prefix, 'SIN', 'SOUT', 'TRF', 'REL')"),
                'last_no', 'prefix')));
        self::assertSame('SIN-000003', $this->ops->reverse($sc, $a->id, 'entered_in_error', null)->number, 'a cancellation takes the next number of its kind');
    }

    public function testWhoMayKeepWhichRecord(): void
    {
        $sku = $this->item('strict', 10);
        self::refusedWith('role_not_allowed', fn () => $this->draft('in', $this->staffUser('buyer'), ['reason_code' => 'found'], []));
        self::refusedWith('admin_cannot_post', fn () => $this->draft('out', $this->staffUser(['admin', 'auditor']), ['reason_code' => 'trade_sale'], []));
        self::refusedWith('role_not_allowed', fn () => $this->draft('adjust', $this->staffUser('purchasing_desk'), [], []));
        self::refusedWith('role_not_allowed', fn () => $this->draft('in', $this->staffUser('warehouse'), ['reason_code' => 'found'], []));
        $this->warehouse('SHOP');
        self::assertSame('posted', $this->posted('transfer', $this->staffUser('warehouse'), ['to_warehouse' => 'SHOP'], [['sku_id' => $sku, 'qty' => 1]])->status);
        self::refusedWith('role_not_allowed', fn () => $this->draft('release', $this->staffUser('warehouse'), [], []));
        $mine = $this->draft('in', $this->staffUser('stock_controller'), ['reason_code' => 'found'], []);
        self::refusedWith('not_creator', fn () => $this->ops->saveLines($this->staffUser('stock_controller'), $mine->id, $mine->version, [['sku_id' => $sku, 'qty' => 1]]));
    }

    public function testTheMigrationSeedsTheKindsReasonsAndTheirHistory(): void
    {
        self::assertSame(['ADJ' => [0, 100, 500], 'REL' => [0, 500, 2000], 'SIN' => [0, 100, 500], 'SOUT' => [0, 100, 500], 'TRF' => [0, 500, 2000]],
            array_map(static fn (array $r): array => [(int) $r['size_approval'], (int) $r['size_units'], (int) $r['size_value']],
                array_column(self::$db->all("SELECT code, size_approval, size_units, size_value FROM document_type WHERE code IN ('ADJ', 'SIN', 'SOUT', 'TRF', 'REL') ORDER BY code"), null, 'code')));
        self::assertSame(['none', 'none', 'none', 'none'], array_map('strval', self::$db->column("SELECT CAST(review_rule AS CHAR) FROM document_type WHERE code IN ('SIN', 'SOUT', 'TRF', 'REL')")));
        $reasons = new ReasonCodes(self::$db);
        self::assertTrue($reasons->get('sample')['needs_given_to']);
        self::assertTrue($reasons->get('staff_use')['needs_given_to']);
        self::assertContains('stock_in', $reasons->get('found')['uses']);
        self::assertContains('stock_out', $reasons->get('other')['uses']);
        self::assertSame(['stock_in'], $reasons->get('opening_stock')['uses']);
        self::assertSame([2, 3, 2, 2], [(int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'reason' AND subject_key = 'found'"),
            (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'reason' AND subject_key = 'other'"),
            (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'reason' AND subject_key = 'sample'"),
            (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'ADJ'")]);
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4 hold: every new row has its baseline, every changed row its next version');
        self::assertSame(['SIN', 'SOUT', 'TRF', 'REL'], array_values(array_intersect(['SIN', 'SOUT', 'TRF', 'REL'], self::$db->column('SELECT prefix FROM number_series'))));
        self::assertSame(StockOpHandler::KINDS, ['in' => 'SIN', 'out' => 'SOUT', 'adjust' => 'ADJ', 'transfer' => 'TRF', 'release' => 'REL']);
    }

    public function testTheReasonsPageSetsTheStockOutRules(): void
    {
        $rev = $this->staffUser('reviewer');
        $this->reasonRule('trade_sale', []); // put back as it was in tearDown
        $r = (new ReasonCodes(self::$db))->setRules($rev, 'trade_sale', true, true, 'trade sales name the customer');
        self::assertTrue($r['changed']);
        self::assertSame([true, true], [$r['reason']['needs_given_to'], $r['reason']['below_zero']]);
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'reason' AND subject_key = 'trade_sale'"));
        self::refusedWith('role_not_allowed', fn () => (new ReasonCodes(self::$db))->setRules($this->staffUser('stock_controller'), 'trade_sale', false, false, 'not allowed to'));
        $added = (new ReasonCodes(self::$db))->add($rev, 'gift_to_charity', 'Given to a charity', ['stock_out'], 'decrease', false, false, 'a new kind of gift', true, false);
        self::assertSame([['stock_out'], true, false], [$added['uses'], $added['needs_given_to'], $added['below_zero']]);
        self::assertSame([], array_values(array_filter(ConfigInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, 'trade_sale')
            || str_contains($v, 'gift_to_charity'))));
    }
}
