<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\DraftPos;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderRefresh;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * E10, pack sizes (pack G; docs/decisions.md PG10, I129): the reorder list suggests in the main supplier item's packs, the draft order
 * keeps packs and units, and Receive turns packs into single items (packs × units per pack), never "boxes for units". After the
 * delivery the stored list counts the stock and nothing on order.
 */
final class ReorderToReceiptTest extends ReceivingTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /** The receiving tests' own setting() (it also rebuilds the services), not the reorder fixtures'. */
    protected function setting(string $key, string $json): void
    {
        parent::setting($key, $json);
    }

    public function testPacksBecomeSingleItemsFromTheSuggestionToTheShelf(): void
    {
        $s = $this->listScenario();
        $this->dry($s['a']);
        $line = (new ReorderLines(self::$db))->lines([$s['a']])[0];
        self::assertSame(['box', 24, 5, 120], [$line['purchase_unit'], $line['upp'], $line['packs'], $line['units']], 'need 120: 5 boxes of 24');
        $list = new ReorderList(self::$db, $this->pos);
        $r = (new DraftPos(self::$db, $this->pos, $list, new ReorderRefresh(self::$db, $this->pos)))->create($s['buyer'], [['sku_id' => $s['a'], 'packs' => 5]]);
        $po = $this->docs->get($r['drafts'][0]['document_id']);
        self::assertSame([[5, 24, 120, 120]], array_map(static fn (array $x): array => array_map('intval', array_values($x)), self::$db->all(
            'SELECT pl.packs, pl.units_per_pack, dl.qty, pl.suggested_units FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ?', [$po->id])), 'the order: 5 packs = 120 single items');
        $po = $this->pos->approve($s['buyer'], $po->id, $po->version);
        $po = $this->pos->markSent($s['buyer'], $po->id, $po->version, 'email', null, true);
        $this->refreshLines();
        self::assertSame([120, 0], [(new ReorderLines(self::$db))->lines([$s['a']])[0]['on_order'], (new ReorderLines(self::$db))->lines([$s['a']])[0]['packs']]);

        $desk = $this->staffUser('purchasing_desk');
        $d = $this->grns->createDraft($desk, (int) $s['s1']['id'], ['invoice_number' => 'ELX-5501', 'invoice_date' => self::day('-1 day')], $po->id, true);
        $lines = $this->grns->lines($d->id);
        self::assertSame([5, 24], [$lines[0]['packs'], $lines[0]['units_per_pack']], 'Receive keys the delivery in the supplier\'s packs');
        self::assertSame(120, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = 1', [$d->id]), '5 boxes × 24 = 120 single items');
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame(60 + 120, $this->onHand($s['a']), '120 single items on the shelf, not 5');
        self::assertSame(120, (int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ?', [$po->id]));
        $this->refreshLines();
        $after = (new ReorderLines(self::$db))->lines([$s['a']])[0];
        self::assertSame([180, 0, 0, 0, false], [$after['available'], $after['on_order'], $after['need'], $after['packs'], $after['below_safety']],
            'the stored list counts the delivery: nothing more to buy');
    }
}
