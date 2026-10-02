<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

/**
 * The receipt side the I-3 GRN will call (spec §6.3; I48-I59): openLines, applyReceipt / reverseReceipt inside the caller's
 * transaction and the state they move, close (part-received only), the cancellation refused once goods were received, and
 * the reorder figures onOrder / inDrafts.
 */
final class PurchaseOrderReceiptApiTest extends PurchaseOrderTestCase
{
    public function testReceiptsMoveTheState(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Receipts Ltd']);
        $a = self::makeSku('Receipt A');
        $b = self::makeSku('Receipt B');
        $siA = $this->supplierItem($buyer, (int) $s['id'], $a, 10, '10.0000', ['supplier_code' => 'RA']);
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $siA['id'], 'packs' => 3], ['sku_id' => $b, 'packs' => 5],
            ['kind' => 'charge', 'description' => 'Carriage', 'pack_price' => '4.00']]);
        $p = $this->pos->approve($buyer, $d->id, $d->version);
        $id = $p->id;

        $open = $this->pos->openLines($id);
        self::assertSame([[1, $a, 30, 0, 30, (int) $siA['id'], 10, 'RA'], [2, $b, 5, 0, 5, null, 1, null]],
            array_map(static fn (array $l): array => [$l['line_no'], $l['sku_id'], $l['units_ordered'], $l['units_received'], $l['units_outstanding'],
                $l['supplier_item_id'], $l['units_per_pack'], $l['supplier_code']], $open), 'item lines only');
        self::assertSame(['10.0000', '1.000000', 'S'], [$open[0]['pack_price'], $open[0]['unit_cost'], $open[0]['vat_code']]);

        try {
            $this->pos->applyReceipt($id, [1 => 10], 'GRN-x');
            self::fail('a receipt outside a transaction must be refused');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
        $rx = fn (array $units, int $sign = 1) => self::$db->transaction(fn () => $sign > 0 ? $this->pos->applyReceipt($id, $units, 'GRN-TEST')
            : $this->pos->reverseReceipt($id, $units, 'GRN-TEST'));

        $rx([1 => 10]);
        self::assertSame('part_received', $this->poRow($id)['state']);
        self::assertSame([20, 5], array_column($this->pos->openLines($id), 'units_outstanding'));
        self::refused(409, 'po_has_receipts', fn () => $this->pos->cancel($buyer, $id, $this->docs->get($id)->version, 'not_needed', null));
        self::refused(409, 'po_has_receipts', fn () => $this->pos->amend($buyer, $id, 'po_amended', null));
        self::refused(422, 'bad_receipt', fn () => $rx([3 => 1]), 'a charge line receives nothing');
        self::refused(422, 'bad_receipt', fn () => $rx([9 => 1]));
        $rx([1 => 25, 2 => 5]);
        self::assertSame('received', $this->poRow($id)['state'], 'over-delivery still counts as received (tolerance is I-3\'s)');
        self::assertSame([], $this->pos->openLines($id));
        self::refused(409, 'po_not_receivable', fn () => $rx([1 => 1]));
        self::refused(409, 'not_closable', fn () => $this->pos->close($buyer, $id, $this->docs->get($id)->version, 'nothing more'));
        self::refused(409, 'receipt_below_zero', fn () => $rx([2 => 6], -1));
        $rx([2 => 5], -1);
        self::assertSame('part_received', $this->poRow($id)['state'], 'reverse -> back');
        $rx([1 => 35], -1);
        self::assertSame('approved', $this->poRow($id)['state'], 'nothing received and never sent: approved again');
        self::assertSame([0, 0], array_map('intval', self::$db->column("SELECT received_units FROM po_line WHERE document_id = ? AND kind = 'item' ORDER BY line_no", [$id])));

        // Close: only from part_received.
        $doc = $this->pos->markSent($buyer, $id, $this->docs->get($id)->version, 'email', null, true);
        self::refused(409, 'not_closable', fn () => $this->pos->close($buyer, $id, $doc->version, 'too early'));
        $rx([2 => 2]);
        self::refused(400, 'reason_required', fn () => $this->pos->close($buyer, $id, $doc->version, 'x'));
        $closed = $this->pos->close($buyer, $id, $doc->version, 'supplier discontinued the rest');
        $po = $this->poRow($id);
        self::assertSame(['closed', 'supplier discontinued the rest', $buyer->staffUserId], [$po['state'], $po['close_reason'], (int) $po['closed_by']]);
        self::assertSame($doc->version + 1, $closed->version);
        self::assertSame([], $this->pos->openLines($id), 'a closed order expects nothing');
        self::assertCount(2, $this->pos->openLines($id, false));
        self::assertSame(5, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'po.receipt' AND entity_id = ?", [(string) $id]),
            'the five receipts that went through (refused ones roll back)');
    }

    public function testOnOrderAndInDrafts(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'On Order Ltd']);
        $a = self::makeSku('On order A');
        $b = self::makeSku('On order B');
        $c = self::makeSku('Never ordered');
        $siA = $this->supplierItem($buyer, (int) $s['id'], $a, 6, '6.0000');
        // posted (approved) 2 x 6 of A + 3 of B; sent 1 x 6 of A; cancelled 5 x 6 of A; a draft 4 x 6 of A; awaiting 1 of B.
        $p1 = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $siA['id'], 'packs' => 2], ['sku_id' => $b, 'packs' => 3]]);
        $p1 = $this->pos->approve($buyer, $p1->id, $p1->version);
        $p2 = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $siA['id'], 'packs' => 1]]);
        $p2 = $this->pos->approve($buyer, $p2->id, $p2->version);
        $p2 = $this->pos->markSent($buyer, $p2->id, $p2->version, 'portal', null, true);
        $p3 = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $siA['id'], 'packs' => 5]]);
        $p3 = $this->pos->approve($buyer, $p3->id, $p3->version);
        $this->pos->cancel($buyer, $p3->id, $p3->version, 'not_needed', null);
        $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $siA['id'], 'packs' => 4]]);
        $big = $this->supplierItem($buyer, (int) $s['id'], $b, 1, '20000.0000');
        $w = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $big['id'], 'packs' => 1]]);
        self::assertSame('awaiting_approval', $this->pos->approve($buyer, $w->id, $w->version)->status);
        self::$db->transaction(fn () => $this->pos->applyReceipt($p1->id, [1 => 5], 'GRN-1'));

        self::assertSame([$a => 12 - 5 + 6, $b => 3, $c => 0], $this->pos->onOrder([$a, $b, $c]), 'posted, open orders only; received units deducted');
        self::assertSame([$a => 24, $b => 1, $c => 0], $this->pos->inDrafts([$a, $b, $c]), 'drafts and waiting approvals');
        self::assertSame([], $this->pos->onOrder([]));
        // Over-received: never negative on order.
        self::$db->transaction(fn () => $this->pos->applyReceipt($p1->id, [1 => 20], 'GRN-2'));
        self::assertSame(6, $this->pos->onOrder([$a])[$a]);
    }
}
