<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Tests\Support\LineUids;

/**
 * A delivery, a supplier invoice and a return to the supplier through their own services (GoodsReceipts, SupplierInvoices, SupplierReturns ->
 * Documents::setLines; 0070, docs/decisions.md LV8): each save gives the lines ids, and each posting's fingerprint covers none.
 */
final class LineUidsInvoicesTest extends InvoicesTestCase
{
    use LineUids;

    public function testADeliveryAnInvoiceAndAReturnGetLineIdsAndTheirFingerprintsCoverNone(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Uid Supplies');
        [$a, $b] = [self::makeSku('Uid delivered A'), self::makeSku('Uid delivered B')];
        $draft = $this->receipt($desk, (int) $s['id'], [['sku_id' => $a, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00'],
            ['sku_id' => $b, 'packs' => 4, 'units_per_pack' => 1, 'pack_price' => '3.00']]);
        self::assertSame('GRN', $draft->docType);
        self::assertSame([1, 2], self::uidsOf($draft->id));
        self::assertSame(3, self::nextUidOf($draft->id));
        $this->ready($desk, $draft->id);
        $grn = $this->post($desk, $draft->id);
        self::assertSame('posted', $grn->status);
        self::assertSame(2, count(self::uidsOf($grn->id)));
        self::assertNotContains(null, self::uidsOf($grn->id));
        self::assertPostingIgnoresUids($this->docs, $grn->id);

        $inv = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        self::assertSame('SINV', $inv->docType);
        $uids = self::uidsOf($inv->id);
        self::assertNotSame([], $uids);
        self::assertSame(range(1, count($uids)), $uids);
        self::assertSame(count($uids) + 1, self::nextUidOf($inv->id));
        $inv = $this->postWithTotals($desk, $inv);
        self::assertSame('posted', $inv->status);
        self::assertPostingIgnoresUids($this->docs, $inv->id);

        $r = $this->returns->createDraft($desk, (int) $s['id'], ['reason_code' => 'faulty']);
        $r = $this->returns->saveLines($desk, $r->id, $r->version, [['sku_id' => $a, 'qty' => 3, 'unit_cost' => '2.00', 'grn_document_id' => $grn->id, 'grn_line_no' => 1],
            ['amount' => '5.00', 'description' => 'Short on the last delivery']]);
        self::assertSame(['DN', [1, 2]], [$r->docType, self::uidsOf($r->id)]);
        $r = $this->returns->saveLines($desk, $r->id, $r->version, [['sku_id' => $a, 'qty' => 2, 'unit_cost' => '2.00', 'grn_document_id' => $grn->id, 'grn_line_no' => 1]]);
        self::assertSame([3], self::uidsOf($r->id));
        $p = $this->returns->post($desk, $r->id, $r->version);
        self::assertSame('posted', $p->status);
        self::assertPostingIgnoresUids($this->docs, $p->id);
    }
}
