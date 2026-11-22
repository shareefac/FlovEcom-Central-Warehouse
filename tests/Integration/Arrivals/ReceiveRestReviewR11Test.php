<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

/**
 * CW-1155: the final Codex check of 3431461 (cw_work/receive_rest_20261122/codex_r11.txt; docs/decisions.md RR31), shown failing first on
 * 3431461: a receipt of the rest is received against its order line only while that line still has room (another delivery may have filled
 * it; the lines of a receipt of the rest are frozen, so a full line would block it for good).
 */
final class ReceiveRestReviewR11Test extends ArrivalsTestCase
{
    use RestFixtures;

    public function testAnOrderLineFilledMeanwhileLeavesTheRestNotAgainstTheOrder(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Order Filled Ltd');
        $a = self::makeSku('Order line filled');
        $b = self::makeSku('Order line pending');
        $sa = $this->supplierItem($buyer, (int) $s['id'], $a, 1, '2.0000', ['supplier_code' => 'SA-' . $a]);
        $sb = $this->supplierItem($buyer, (int) $s['id'], $b, 1, '3.0000', ['supplier_code' => 'SB-' . $b]);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $sa['id'], 'packs' => 5], ['supplier_item_id' => (int) $sb['id'], 'packs' => 5]]);
        $po = $this->pos->approve($buyer, $po->id, $po->version);
        // The invoice of line A only, counted on arrival; B is still to come on the order.
        $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'RR-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [['kind' => 'goods', 'sku_id' => $a, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '2.0000',
            'po_document_id' => $po->id, 'po_line_no' => 1]]);
        $this->invoices->attach($desk, $d->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $d = $this->invoices->saveHeader($desk, $d->id, $this->docs->get($d->id)->version, ['net_total' => '10.00', 'vat_total' => '0.00']);
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        // Another delivery of the order fills line A meanwhile (the order stays part received: B is still to come).
        $this->delivered($desk, (int) $s['id'], [['sku_id' => $a, 'supplier_item_id' => (int) $sa['id'], 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '2.0000',
            'po_line_no' => 1]], $po->id);
        self::assertSame('part_received', (string) self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$po->id]));
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([0], array_map(static fn (array $l): int => (int) $l['po_line_no'], $this->grns->lines($r->id)), 'not against the full order line');
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame([10, 'matched'], [$this->onHand($a), $this->stored($p->id)['match_state']]);
    }
}
