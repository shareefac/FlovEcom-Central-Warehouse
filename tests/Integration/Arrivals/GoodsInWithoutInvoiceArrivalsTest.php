<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

/**
 * CW-1152 with goods on their way (docs/decisions.md NI9): a delivery received against a "count on arrival" invoice (its first receipt
 * included) keeps that invoice's supplier and supplier invoice number, also for a supplier ticked "Can book goods in without the invoice":
 * clearing or changing the number refuses the posting (the invoice's arrivals are never closed by another delivery).
 */
final class GoodsInWithoutInvoiceArrivalsTest extends ArrivalsTestCase
{
    public function testTheFirstReceiptOfAnInvoiceKeepsItsSupplierAndNumber(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Arrives Ticked Ltd');
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $s['version'], ['goods_in_without_invoice' => '1']);
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000', 'ARR-TK-1');
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        // The number cleared (the supplier may book without it): still the invoice's receipt, so refused.
        $g = $this->grns->saveDraft($desk, $g->id, $this->docs->get($g->id)->version, ['invoice_number' => '', 'delivery_note' => 'PAGE-ARR-1'], $this->grns->lines($g->id));
        $this->bench($this->staffUser('goods_in'), $g->id);
        $e = $this->refusedCode('invoice_link_changed', fn () => $this->post($desk, $g->id));
        self::assertStringContainsString('ARR-TK-1', $e->getMessage());
        // Another number: refused too; the invoice's own (written another way) posts.
        $g = $this->grns->saveDraft($desk, $g->id, $this->docs->get($g->id)->version, ['invoice_number' => 'ARR-TK-2'], $this->grns->lines($g->id));
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->refusedCode('invoice_link_changed', fn () => $this->post($desk, $g->id));
        $g = $this->grns->saveDraft($desk, $g->id, $this->docs->get($g->id)->version, ['invoice_number' => ' arr-tk-1'], $this->grns->lines($g->id));
        $this->bench($this->staffUser('goods_in'), $g->id);
        self::assertSame('posted', $this->post($desk, $g->id)->status);
        self::assertSame([['state' => 'received', 'units' => 10, 'sellable' => 1, 'grn' => $g->id]], $this->arrivalRows($p->id));
    }
}
