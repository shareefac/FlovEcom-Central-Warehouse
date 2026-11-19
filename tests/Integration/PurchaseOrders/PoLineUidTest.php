<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Items\HandMade;
use CW\Tests\Support\LineUids;

/**
 * A purchase order's lines through its own service (PurchaseOrders::saveDraft -> Documents::setLines; 0070, docs/decisions.md LV8): a line
 * sent without an id gets the order's next free one, the order's own lines (po_line) are as before, and the confirmed order's fingerprint
 * covers no id. Since step 3b (who is here on the screen, docs/decisions.md LV18) a line keeps its id through every whole save of the order:
 * PurchaseOrders::lines() names each line's id and every path that saves the lines it read (a save of the page, Add, Add multiple, the file
 * appended, a supplier changed, a new supplier) passes it on; a new line, a file that replaces the lines, a copy and an amendment get new ones.
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

    /** @return list<?int> the ids PurchaseOrders::lines() names, line order */
    private function lineIds(int $id): array
    {
        return array_map(static fn (array $l): ?int => $l['uid'], $this->pos->lines($id));
    }

    public function testALineKeepsItsIdThroughEveryWholeSaveOfTheOrder(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Kept Ids Ltd']);
        $sid = (int) $s['id'];
        $skus = [];
        $si = [];
        foreach (['Kept pod', 'Kept liquid', 'Kept coil', 'Kept tank'] as $name) {
            $skus[] = $sku = self::makeSku($name);
            $si[] = (int) $this->supplierItem($buyer, $sid, $sku, 1, '2.0000', ['supplier_code' => 'K-' . $sku])['id'];
        }
        $d = $this->draftPo($buyer, $sid, [['supplier_item_id' => $si[0], 'packs' => 2], ['supplier_item_id' => $si[1], 'packs' => 3],
            ['supplier_item_id' => $si[2], 'packs' => 4]]);
        self::assertSame([1, 2, 3], self::uidsOf($d->id));
        self::assertSame([1, 2, 3], $this->lineIds($d->id), 'the service\'s lines name their ids');

        // A whole save of the lines as read (the page's save: packs changed on line 2): every line keeps its id.
        $lines = $this->pos->lines($d->id);
        $lines[1]['packs'] = 7;
        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, ['note' => 'kept'], $lines);
        self::assertSame([1, 2, 3], self::uidsOf($d->id));
        self::assertSame(7, $this->pos->lines($d->id)[1]['packs']);
        self::assertSame(4, self::nextUidOf($d->id));

        // Add: a new line gets the next id; the same pack again adds to its line, which keeps its id.
        $r = $this->pos->addSupplierItem($buyer, $d->id, $d->version, $si[3], 1);
        self::assertSame([1, 2, 3, 4], self::uidsOf($d->id));
        $r = $this->pos->addSupplierItem($buyer, $d->id, $r['document']->version, $si[0], 1);
        self::assertSame(['incremented', [1, 2, 3, 4]], [$r['status'], self::uidsOf($d->id)]);
        $r = $this->pos->addLine($buyer, $d->id, $r['document']->version, 'K-' . $skus[1], 2);
        self::assertSame(['incremented', [1, 2, 3, 4]], [$r['status'], self::uidsOf($d->id)]);
        $d = $r['document'];

        // Another line removed: the others keep theirs (and move up); a new line never takes a removed line's id.
        $lines = $this->pos->lines($d->id);
        array_splice($lines, 1, 1);
        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, [], $lines);
        self::assertSame([1, 3, 4], self::uidsOf($d->id));
        $lines = $this->pos->lines($d->id);
        $lines[] = ['supplier_item_id' => $si[1], 'packs' => 1];
        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, [], $lines);
        self::assertSame([1, 3, 4, 5], self::uidsOf($d->id));
        self::assertSame([[1, $si[0]], [2, $si[2]], [3, $si[3]], [4, $si[1]]], array_map(static fn (array $r): array => [(int) $r['line_no'], (int) $r['supplier_item_id']],
            self::$db->all('SELECT line_no, supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id])), 'the order\'s own lines as before');

        // A line of another draft, or one id twice: refused (Documents::setLines), nothing saved.
        $other = $this->draftPo($buyer, $sid, [['supplier_item_id' => $si[0], 'packs' => 1]]);
        $lines = $this->pos->lines($d->id);
        $lines[0]['uid'] = 99;
        self::refused(400, 'bad_lines', fn () => $this->pos->saveDraft($buyer, $d->id, $d->version, [], $lines));
        $lines = $this->pos->lines($d->id);
        $lines[1]['uid'] = $lines[0]['uid'];
        self::refused(400, 'bad_lines', fn () => $this->pos->saveDraft($buyer, $d->id, $d->version, [], $lines));
        self::refused(400, 'bad_line', fn () => $this->pos->saveDraft($buyer, $d->id, $d->version, [], [['supplier_item_id' => $si[0], 'packs' => 1, 'uid' => 'x']]));
        self::assertSame([1, 3, 4, 5], self::uidsOf($d->id));
        self::assertSame([1], self::uidsOf($other->id));
    }

    public function testTheFileTheSupplierAndANewSupplierKeepTheIdsAndACopyStartsAfresh(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'File Ids Ltd']);
        $t = $this->activeSupplier($buyer, ['name' => 'Moved Ids Ltd']);
        $sid = (int) $s['id'];
        $skus = [];
        $si = [];
        foreach (['File pod', 'File liquid', 'File coil'] as $name) {
            $skus[] = $sku = self::makeSku($name);
            $si[] = (int) $this->supplierItem($buyer, $sid, $sku, 1, '3.0000', ['supplier_code' => 'F-' . $sku])['id'];
            $this->supplierItem($buyer, (int) $t['id'], $sku, 1, '3.5000', ['supplier_code' => 'M-' . $sku]);
        }
        $d = $this->draftPo($buyer, $sid, [['supplier_item_id' => $si[0], 'packs' => 2], ['supplier_item_id' => $si[1], 'packs' => 3]]);
        self::assertSame([1, 2], self::uidsOf($d->id));

        // The file appended (Add multiple and the file): the lines there keep theirs, a line of the file already there adds to it, a new one is new.
        $dir = sys_get_temp_dir() . '/cw_po_uid_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $path = "{$dir}/more.csv";
            file_put_contents($path, "supplier_code,packs\r\nF-{$skus[1]},1\r\nF-{$skus[2]},5\r\n");
            $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $path, 'more.csv', 'append');
            self::assertSame([], $r['errors']);
            self::assertSame([1, 2, 3], self::uidsOf($d->id));
            self::assertSame([2, 4, 5], array_column($this->pos->lines($d->id), 'packs'));
            // A file that replaces the lines: every line is new.
            file_put_contents($path, "supplier_code,packs\r\nF-{$skus[0]},1\r\n");
            $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $path, 'more.csv', 'replace');
            self::assertSame([], $r['errors']);
            self::assertSame([4], self::uidsOf($d->id));
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
        $lines = $this->pos->lines($d->id);
        $lines[] = ['supplier_item_id' => $si[1], 'packs' => 1];
        $lines[] = ['sku_id' => $skus[2], 'packs' => 2, 'pack_price' => '4.00'];
        $d = $this->pos->saveDraft($buyer, $d->id, $this->docs->get($d->id)->version, [], $lines);
        self::assertSame([4, 5, 6], self::uidsOf($d->id));

        // Another supplier (each line moved to its packs, KG30): the lines keep their ids.
        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, ['supplier_id' => (string) $t['id']], $this->pos->lines($d->id));
        self::assertSame((int) $t['id'], (int) $this->poRow($d->id)['supplier_id']);
        self::assertSame([4, 5, 6], self::uidsOf($d->id));
        // A new supplier made on the draft (its lines as they are, or as the page sent them): the same.
        $r = $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $d->version, ['name' => 'Brand New Ids Ltd']);
        self::assertSame([4, 5, 6], self::uidsOf($d->id));
        $lines = $this->pos->lines($d->id);
        $r = $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $r['document']->version, ['name' => 'Newer Ids Ltd'], [], array_slice($lines, 1));
        self::assertSame([5, 6], self::uidsOf($d->id));

        // A new item made on a line (addNewItem: a new line, its own id).
        $r = $this->pos->addNewItem($this->staffUser('purchasing_manager'), $d->id, $r['document']->version, new HandMade(self::$db), ['card' => ['name' => 'Ids new item']],
            ['units_per_pack' => 1], 2);
        self::assertSame([5, 6, 7], self::uidsOf($d->id));

        // A copy is another order: its lines start at 1 (nothing of the original's ids is carried, nothing refused).
        $copy = $this->pos->copy($buyer, $d->id);
        self::assertSame([1, 2, 3], self::uidsOf($copy->id));
        self::assertSame([5, 6, 7], self::uidsOf($d->id));
    }
}
