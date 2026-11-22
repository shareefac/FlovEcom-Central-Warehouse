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
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 5, 1, '2.0000');
        $this->invoices->attach($desk, $x['doc']->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $p = $this->invoices->submit($desk, $x['doc']->id, $this->docs->get($x['doc']->id)->version);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        // Another delivery of the order fills its line meanwhile.
        $si = (int) self::$db->value('SELECT id FROM supplier_item WHERE supplier_id = ? AND sku_id = ?', [(int) $s['id'], $x['sku']]);
        $this->delivered($desk, (int) $s['id'], [['sku_id' => $x['sku'], 'supplier_item_id' => $si, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '2.0000',
            'po_line_no' => 1]], $x['po']->id);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([0], array_map(static fn (array $l): int => (int) $l['po_line_no'], $this->grns->lines($r->id)), 'not against the full order line');
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame([10, 'matched'], [$this->onHand($x['sku']), $this->stored($p->id)['match_state']]);
    }
}
