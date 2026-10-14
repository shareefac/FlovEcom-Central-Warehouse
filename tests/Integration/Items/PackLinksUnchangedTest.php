<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Items\Packs;
use CW\Items\Repacks;
use CW\Tests\Integration\StockOps\StockOpsTestCase;

/**
 * Pack B leaves the matching exactly as it was (docs/decisions.md PB8): with pack items defined and repacked, a pack link (a variant
 * matched with N units a sale) still waits for a second matching lead, still sells N units of its item a sale, and a change of such a
 * link still needs the second person. The same assertions as DecisionServiceTest's of before 0031, made again on a schema where pack
 * items exist and a repack was booked. (The check on arrival's "pack" answers, "not sure", are pure rules on facts pack B neither
 * reads nor writes: ArrivalRulesTest holds them, and runs with this class.)
 */
final class PackLinksUnchangedTest extends StockOpsTestCase
{
    public function testAPackLinkStillWaitsForASecondLeadAndSellsNUnitsWithPackItemsDefined(): void
    {
        $alt = $this->site('alt', 'live');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $shot = $this->item('legacy', 50, 'Shot 10ml');
        $box = $this->item('legacy', 2, 'Box 10 x 10ml');
        $def = (new Packs(self::$db))->create($lead, $box, $shot, 10)['id'];
        (new Repacks(self::$db, $this->docs))->book($this->staffUser('stock_controller'), ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def,
            'direction' => 'break', 'packs' => 1]);
        $this->assertBal(60, 0, 0, $shot);

        $e = $this->listing($alt, 'P10', null);
        $r = $this->decide($mapper, 'link', $e, ['sku_id' => $shot, 'units_per_item' => 10]);
        self::assertSame(['pending_second', ['units_per_item'], 0], [$r['state'], $r['needs_second'], $r['map_version']], 'as before: a second lead');
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 0], $this->link($e));
        $ok = $this->ds->approve($lead, $r['decision_id']);
        self::assertSame(['applied', 'mapped', $shot, 10], [$ok['state'], $ok['status'], $ok['sku_id'], $ok['units_per_item']]);
        // One sale of the pack link takes 10 singles' stock, as before; the pack item's own stock is untouched.
        $this->ok($this->commit($alt, 'A-1', [self::line('P10', 'u1')]));
        $this->assertBal(60, 10, 0, $shot);
        $this->assertBal(1, 0, 0, $box);
        self::assertSame(5, $this->view($alt, 'P10')['available'], '(60 - 10) / 10');

        // Moving the pack link to the pack item with 1 unit a sale is a change of a link with units <> 1: still a second person.
        $move = $this->decide($mapper, 'link', $e, ['sku_id' => $box, 'units_per_item' => 1]);
        self::assertSame(['pending_second', ['units_per_item']], [$move['state'], $move['needs_second']]);
        $this->ds->withdraw($lead, $move['decision_id']);
        self::assertSame(['sku_id' => $shot, 'units_per_item' => 10, 'status' => 'mapped', 'map_version' => 1], $this->link($e));
    }
}
