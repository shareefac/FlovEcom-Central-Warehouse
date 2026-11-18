<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Tests\Support\LineUids;

/**
 * A purchase order's lines through its own service (PurchaseOrders::saveDraft -> Documents::setLines; 0070, docs/decisions.md LV8): each save
 * gives the lines ids from the order's next free one (the order's form sends none yet: carrying them is the page's step), the order's own
 * lines (po_line) are as before, and the confirmed order's fingerprint covers no id.
 */
final class PoLineUidTest extends PurchaseOrderTestCase
{
    use LineUids;

    public function testAnOrdersSavesGiveIdsAndItsConfirmationsFingerprintCoversNone(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Uid Supplies']);
        $si = [];
        foreach (['Uid pod', 'Uid liquid', 'Uid coil'] as $name) {
            $si[] = (int) $this->supplierItem($buyer, (int) $s['id'], self::makeSku($name), 6, '12.0000')['id'];
        }
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => $si[0], 'packs' => 2], ['supplier_item_id' => $si[1], 'packs' => 3]]);
        self::assertSame([1, 2], self::uidsOf($d->id));
        self::assertSame(3, self::nextUidOf($d->id));
        $poLines = self::$db->all('SELECT line_no, supplier_item_id, packs FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id]);
        self::assertSame([[1, $si[0], 2], [2, $si[1], 3]], array_map(static fn (array $r): array => [(int) $r['line_no'], (int) $r['supplier_item_id'], (int) $r['packs']], $poLines));

        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, [], [['supplier_item_id' => $si[1], 'packs' => 3], ['supplier_item_id' => $si[2], 'packs' => 1]]);
        self::assertSame([3, 4], self::uidsOf($d->id), 'a whole save without ids: new ones, never one given before');
        self::assertSame(5, self::nextUidOf($d->id));
        self::assertSame([[1, $si[1]], [2, $si[2]]], array_map(static fn (array $r): array => [(int) $r['line_no'], (int) $r['supplier_item_id']],
            self::$db->all('SELECT line_no, supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id])));

        $p = $this->pos->approve($buyer, $d->id, $d->version);
        self::assertSame(['posted', 'PO-000001'], [$p->status, $p->number]);
        self::assertSame([3, 4], self::uidsOf($p->id));
        self::assertPostingIgnoresUids($this->docs, $p->id);
    }
}
