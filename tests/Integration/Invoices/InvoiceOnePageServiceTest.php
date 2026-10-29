<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

/**
 * What the supplier invoice's one page needs from the service (docs/decisions.md PV): the invoice's due date (kept, checked, cleared,
 * never on a credit note), a line added as one of the supplier's own packs (the item box's choice) or at the items per pack typed (a
 * new item), and what the posted credit notes took off each listed invoice (the list's balance, one grouped read). Every rule of pack C
 * stays: a due date changes nothing in the match.
 */
final class InvoiceOnePageServiceTest extends InvoicesTestCase
{
    public function testTheDueDateIsKeptCheckedAndClearedAndChangesNothingElse(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Due Date Ltd');
        $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'DUE-1', 'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        self::assertSame('2026-10-31', (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$d->id]));
        self::assertSame('2026-10-31', (string) $this->invoices->record($d->id)['due_date']);
        $this->refusedCode('bad_field', fn () => $this->invoices->saveHeader($desk, $d->id, $d->version, ['due_date' => '31/10/2026']));
        $this->refusedCode('bad_field', fn () => $this->invoices->saveHeader($desk, $d->id, $d->version, ['due_date' => '2026-02-30']));
        $this->refusedCode('due_before_date', fn () => $this->invoices->saveHeader($desk, $d->id, $d->version, ['due_date' => '2026-09-30']),
            'a due date before the date on the paper');
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['vat_total' => '1.00']);
        self::assertSame('2026-10-31', (string) self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$d->id]), 'kept when not sent');
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['due_date' => '']);
        self::assertNull(self::$db->value('SELECT due_date FROM supplier_invoice WHERE document_id = ?', [$d->id]), 'cleared');
        $this->refusedCode('bad_field', fn () => $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['due_date' => '2026-10-31']),
            'a credit note is not paid: it has no due date');
        $listed = array_column($this->invoices->list('invoice', []), 'due_date', 'id');
        self::assertArrayHasKey($d->id, $listed);
    }

    public function testALineAsOneOfTheSuppliersPacksOrAtTheItemsPerPackTyped(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Pack Choice Ltd');
        $other = $this->activeSupplier($buyer, ['name' => 'Someone Else Ltd']);
        $sku = self::makeSku('Pack choice mint');
        $six = $this->items->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '6', 'supplier_code' => 'PC-6', 'is_preferred' => '1'], ['pack_price' => '9.0000']);
        $big = $this->items->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '24', 'supplier_code' => 'PC-24'], ['pack_price' => '30.0000']);
        $theirs = $this->items->create($buyer, (int) $other['id'], $sku, ['units_per_pack' => '12', 'supplier_code' => 'SE-12'], ['pack_price' => '15.0000']);
        $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'PACK-1']);
        $r = $this->invoices->addItem($desk, $d->id, $d->version, '', 2, null, null, (int) $big['id']);
        self::assertSame('added', $r['status']);
        $l = $this->invoices->lines($d->id)[0];
        self::assertSame([$sku, 2, 24, '30.0000', 'PC-24', 48], [$l['sku_id'], $l['packs'], $l['units_per_pack'], $l['pack_price'], $l['supplier_code'], $l['qty']],
            'the pack chosen, not the preferred one');
        $this->refusedCode('unknown_supplier_item', fn () => $this->invoices->addItem($desk, $d->id, $r['document']->version, '', 1, null, null, (int) $theirs['id']),
            'another supplier\'s pack');
        $r = $this->invoices->addItem($desk, $d->id, $r['document']->version, '', 3, '1.2500', $sku, null, 10);
        $l = $this->invoices->lines($d->id)[1];
        self::assertSame([3, 10, '1.2500', 30], [$l['packs'], $l['units_per_pack'], $l['pack_price'], $l['qty']], 'the items per pack typed with a new item');
        unset($six);
    }

    public function testTheBalanceIsWhatThePostedCreditNotesLeft(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Balance Ltd');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '24.00', '4.80');
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'CN-B-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400),
            'credits_id' => $inv->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 6);
        $this->postWithTotals($desk, $cn, '12.00', '2.40');
        self::assertSame([$inv->id => 1440], $this->invoices->creditedOn([$inv->id]), 'in pence: £14.40 credited of £28.80');
        self::assertSame([], $this->invoices->creditedOn([]));
    }
}
