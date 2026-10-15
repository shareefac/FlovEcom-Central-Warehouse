<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\DraftPos;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderRefresh;
use CW\Suppliers\SupplierItems;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * Finding 4 of the Codex review of the PO module (pack PO, docs/decisions.md PO11), written first against 552851a and seen failing:
 * "create draft orders" orders what the list SHOWED. Each ticked line carries the supplier item and the pack size the buyer saw; when
 * the item's main supply changed meanwhile (another supplier, another pack) the line is not ordered: it is skipped and listed with the
 * reason (the owner's rule: skip anything doubtful, finish the rest), never silently bought in other packs from another supplier.
 */
final class ReorderDraftAsShownTest extends ReceivingTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    protected function setting(string $key, string $json): void
    {
        parent::setting($key, $json);
    }

    public function testALineWhoseSupplyChangedSinceItWasShownIsSkippedNotChanged(): void
    {
        $s = $this->listScenario();
        $list = new ReorderList(self::$db, $this->pos);
        $drafts = new DraftPos(self::$db, $this->pos, $list, new ReorderRefresh(self::$db, $this->pos));
        $shown = [];
        foreach ($list->lines(ReorderList::filters(['show' => 'all']), [$s['a'], $s['b']]) as $l) {
            $shown[(int) $l['sku_id']] = ['supplier_item_id' => (int) $l['supplier_item_id'], 'units_per_pack' => (int) $l['upp']];
        }
        self::assertSame(['supplier_item_id' => (int) $s['si']['a']['id'], 'units_per_pack' => 24], $shown[$s['a']], 'A: 5 boxes of 24 at Elux');

        // Meanwhile A's main supply becomes Mary Distribution's packs of 6.
        (new SupplierItems(self::$db))->create($s['buyer'], (int) $s['s2']['id'], $s['a'], ['units_per_pack' => '6', 'supplier_code' => 'MARY-A6', 'is_preferred' => '1'],
            ['pack_price' => '10.00']);
        $this->refreshLines();

        $r = $drafts->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 5] + $shown[$s['a']], ['sku_id' => $s['b'], 'packs' => 3] + $shown[$s['b']]]);
        self::assertSame([(int) $s['s1']['id']], array_column($r['drafts'], 'supplier_id'), 'B is ordered; nothing is ordered from Mary');
        self::assertSame(1, $r['drafts'][0]['lines']);
        self::assertSame([$s['a']], array_column($r['skipped'], 'sku_id'));
        self::assertSame('changed', $r['skipped'][0]['why'] ?? null);
        self::assertStringContainsString('changed', $r['skipped'][0]['reason']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM document_line WHERE sku_id = ?', [$s['a']]), 'A is on no order');

        // The same pack size changed on the same supplier item: skipped too.
        $si = (new SupplierItems(self::$db))->get((int) $s['si']['b']['id']);
        self::$db->exec('UPDATE supplier_item SET units_per_pack = 12 WHERE id = ?', [(int) $si['id']]); // as if changed before any order used it
        $this->refreshLines();
        $r = $drafts->create($s['buyer'], [['sku_id' => $s['b'], 'packs' => 3] + $shown[$s['b']]]);
        self::assertSame([], $r['drafts']);
        self::assertSame(['changed'], array_column($r['skipped'], 'why'));
        self::$db->exec('UPDATE supplier_item SET units_per_pack = ? WHERE id = ?', [(int) $si['units_per_pack'], (int) $si['id']]);
    }
}
