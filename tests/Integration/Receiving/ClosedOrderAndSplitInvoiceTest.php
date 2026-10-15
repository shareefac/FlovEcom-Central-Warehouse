<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

/**
 * Two findings of the Codex review of the PO module about deliveries (pack PO, docs/decisions.md PO8, PO10), each written first against
 * 552851a and seen failing:
 *   finding 2  a delivery of an order that was closed afterwards is taken back (reversed, or rejected at review): the order keeps its
 *              "the rest is not coming" while something is still delivered, and is open again when nothing is;
 *   finding 6  one supplier invoice covers two deliveries of the same order (40 today, 60 tomorrow): the second delivery says so and
 *              uses the same number and the same invoice copy; a real duplicate (another order, no order, the same delivery note, or
 *              not said) is still refused.
 */
final class ClosedOrderAndSplitInvoiceTest extends ReceivingTestCase
{
    // ---- finding 2 ------------------------------------------------------------------------------------------------------

    public function testADeliveryOfAClosedOrderIsTakenBackAndRejectedAtReview(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $this->dry($sku);
        $a = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 4]], [], $po->id);
        $this->ready($desk, $a->id);
        $this->post($desk, $a->id);
        $b = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 2]], [], $po->id);
        $this->ready($desk, $b->id);
        $this->post($desk, $b->id);
        self::assertSame(['part_received', 6], [$this->poRow($po->id)['state'], $this->received($po->id)]);
        $this->pos->close($buyer, $po->id, $this->docs->get($po->id)->version, 'the supplier cannot send the rest');

        // Reversed by the desk: the order stays closed (4 still delivered, the rest still not coming).
        $this->docs->reverse($desk, $b->id, 'entered_in_error', null);
        self::assertSame('reversed', $this->docs->get($b->id)->status);
        $row = $this->poRow($po->id);
        self::assertSame(['closed', 4, 'the supplier cannot send the rest'], [$row['state'], $this->received($po->id), $row['close_reason']]);
        self::assertSame(4, $this->onHand($sku));

        // Rejected at review: the receipt is reversed; with nothing delivered the order cannot stay "closed after a part delivery": it is
        // open again (approved: it was never sent), and says so in its audit.
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'review' AND state = 'open'", [$a->id]);
        self::assertGreaterThan(0, $task);
        $this->docs->reject($this->staffUser('reviewer'), $task, 'counted the wrong item');
        self::assertSame('reversed', $this->docs->get($a->id)->status);
        $row = $this->poRow($po->id);
        self::assertSame(['approved', 0, null, null], [$row['state'], $this->received($po->id), $row['closed_at'], $row['close_reason']]);
        self::assertSame(0, $this->onHand($sku));
        $last = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'po.receipt' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [(string) $po->id]), true);
        self::assertSame(['back', 'approved', 'the supplier cannot send the rest'], [$last['direction'], $last['state'], $last['reopened_from_closed'] ?? null]);
        self::assertSame(10, $this->pos->openLines($po->id)[0]['units_outstanding'], 'all 10 expected again');
    }

    private function received(int $poId): int
    {
        return (int) self::$db->value('SELECT COALESCE(SUM(received_units), 0) FROM po_line WHERE document_id = ?', [$poId]);
    }

    // ---- finding 6 ------------------------------------------------------------------------------------------------------

    public function testOneInvoiceCoversTwoDeliveriesOfTheSameOrder(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $this->dry($sku);
        $copy = $this->pdf('INV-100');
        // 4 today, on invoice INV-100.
        $a = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-100', 'delivery_note' => 'DN-1'], $po->id);
        $a = $this->grns->saveDraft($desk, $a->id, $a->version, [], [['sku_id' => $sku, 'packs' => 4]]);
        $this->grns->attach($desk, $a->id, $copy, 'INV-100.pdf', 'supplier_invoice');
        $this->bench($this->staffUser('goods_in'), $a->id);
        $this->post($desk, $a->id);

        // 6 tomorrow, on the same invoice: the number alone is refused, and the refusal says the order's deliveries may share it.
        $b = $this->grns->createDraft($desk, (int) $s['id'], ['delivery_note' => 'DN-2'], $po->id);
        $b = $this->grns->saveDraft($desk, $b->id, $b->version, [], [['sku_id' => $sku, 'packs' => 6]]);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $b->id, $this->docs->get($b->id)->version, ['invoice_number' => 'INV-100']));
        self::assertSame((string) $po->number, $e->detail['split_po'] ?? null, 'a later delivery of the same order may say it shares the invoice');
        $b = $this->grns->setInvoice($desk, $b->id, $this->docs->get($b->id)->version, ['invoice_number' => 'inv-100 ', 'invoice_split' => true]);
        self::assertSame([1, 2], array_map('intval', self::$db->column('SELECT invoice_part FROM goods_receipt WHERE document_id IN (?, ?) ORDER BY document_id', [$a->id, $b->id])));
        // The same invoice copy is accepted on the second delivery of the invoice.
        $att = $this->grns->attach($desk, $b->id, $copy, 'INV-100.pdf', 'supplier_invoice');
        self::assertTrue($att['deduped'], 'the same file');
        $this->bench($this->staffUser('goods_in'), $b->id);
        $this->post($desk, $b->id);
        self::assertSame('received', $this->poRow($po->id)['state']);
        self::assertSame(10, $this->onHand($sku));
        self::assertSame('posted', $this->docs->get($b->id)->status);
    }

    public function testARealDuplicateOfAnInvoiceIsStillRefused(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        ['doc' => $po1, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        ['doc' => $po2] = $this->approvedPoFor($buyer, (int) $s['id'], 10, 1);
        $a = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-200', 'delivery_note' => 'DN-7'], $po1->id);
        $this->grns->saveDraft($desk, $a->id, $a->version, [], [['sku_id' => $sku, 'packs' => 4]]);

        $split = ['invoice_number' => 'INV-200', 'invoice_split' => true];
        // Another order of the supplier: refused, and no split offered.
        $c = $this->grns->createDraft($desk, (int) $s['id'], [], $po2->id);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $c->id, $c->version, $split));
        self::assertNull($e->detail['split_po'] ?? null);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $c->id, $c->version, ['invoice_number' => 'INV-200']));
        self::assertNull($e->detail['split_po'] ?? null, 'another order: not a split delivery');
        // No order at all: refused.
        $d = $this->grns->createDraft($desk, (int) $s['id'], []);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $d->id, $d->version, $split));
        // The same order with the same delivery note: the same delivery keyed twice, refused.
        $f = $this->grns->createDraft($desk, (int) $s['id'], ['delivery_note' => 'dn-7'], $po1->id);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $f->id, $f->version, $split));
        self::assertSame('same_delivery_note', $e->detail['why'] ?? null);
        // The same order and another delivery note, but not said: refused (the protection stays the default).
        $g = $this->grns->createDraft($desk, (int) $s['id'], ['delivery_note' => 'DN-8'], $po1->id);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->setInvoice($desk, $g->id, $g->version, ['invoice_number' => 'INV-200']));
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->saveDraft($desk, $g->id, $g->version, ['invoice_number' => 'INV-200'], []));
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'INV-200'], $po1->id));
        // A second part changes its order only after its invoice number is changed.
        $g = $this->grns->setInvoice($desk, $g->id, $g->version, $split);
        self::assertSame(2, (int) self::$db->value('SELECT invoice_part FROM goods_receipt WHERE document_id = ?', [$g->id]));
        $this->refusedCode('invoice_shared', fn () => $this->grns->saveDraft($desk, $g->id, $this->docs->get($g->id)->version, ['po_id' => (string) $po2->id], []));
        $g = $this->grns->saveDraft($desk, $g->id, $this->docs->get($g->id)->version, ['invoice_number' => 'INV-201'], []);
        self::assertSame(1, (int) self::$db->value('SELECT invoice_part FROM goods_receipt WHERE document_id = ?', [$g->id]), 'its own invoice now');
        // The copy of one invoice on a receipt of another invoice is still refused.
        $copy = $this->pdf('INV-200');
        $this->grns->attach($desk, $a->id, $copy, 'INV-200.pdf', 'supplier_invoice');
        $this->refusedCode('invoice_copy_elsewhere', fn () => $this->grns->attach($desk, $g->id, $copy, 'INV-200.pdf', 'supplier_invoice'));
    }
}
