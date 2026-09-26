<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Tests\Support\StockTestCase;

/**
 * §4 "Residual windows, always flagged as oversell_event, never silent" beyond the commit path
 * (R6, R7). Found by the review of 26 Sep (slot review2): a count, an ERP sale or a late dispatch
 * reset could push a protected item below zero with nothing flagged; an unheld sale of a stopped
 * item or through a quarantined listing was flagged only when the item was also short.
 */
final class OversellFlagTest extends StockTestCase
{
    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return self::$db->all('SELECT kind, shortfall, available_after, order_ref FROM oversell_event ORDER BY id');
    }

    public function testACountBelowThePaidUnitsOfAProtectedItemIsFlagged(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c', 'd')]);
        $this->book('count', $sku, 2, 'MAIN', '2026-09-26T12:00:00Z');
        self::assertSame(-2, $this->view($site, 'V1')['available']);
        self::assertSame([['kind' => 'count_short', 'shortfall' => 2, 'available_after' => -2, 'order_ref' => null]], $this->events());
    }

    public function testAProtectedItemPushedBelowZeroByAnErpSaleIsFlagged(): void
    {
        $site = $this->site();
        $this->grant($site, 'erp_sale');
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a', 'b', 'c')]);
        $this->commit($site, '1', [self::line('V1', 'a', 'b', 'c')]);
        // an ERPNext trade sale takes 2 of the 3 paid-for units off the shelf (§9: fourth consumer)
        $req = ['type' => 'erp_sale', 'doc_ref' => 'SINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 2]]];
        $this->ok($this->moves->record($site, $req, 'erp-1'));
        self::assertSame(-2, $this->view($site, 'V1')['available']);
        self::assertSame([['kind' => 'movement_short', 'shortfall' => 2, 'available_after' => -2, 'order_ref' => null]], $this->events());
        $this->ok($this->moves->record($site, $req, 'erp-1')); // a replay flags nothing more
        self::assertCount(1, $this->events());
        $detail = json_decode((string) self::$db->value('SELECT detail FROM oversell_event'), true);
        self::assertSame(['erp_sale', 'SINV-1', 'channel:vpg'], [$detail['movement'], $detail['doc_ref'], $detail['actor']]);
    }

    public function testAProtectedItemPushedBelowZeroByALateResetIsFlagged(): void
    {
        $vpg = $this->site('vpg');
        $sku = $this->item('strict', 1);
        $this->listing($vpg, 'V1', $sku);
        $this->reserve($vpg, '1', [self::line('V1', 'a')]);
        $this->commit($vpg, '1', [self::line('V1', 'a')]);
        $this->ship($vpg, '1', ['a'], '2026-09-26T11:00:00Z');       // left at 11:00
        // 11:30 the dispatch is cleared and a goes back on the shelf (not reported yet);
        // 12:00 the counter finds 1
        $this->book('count', $sku, 1, 'MAIN', '2026-09-26T12:00:00Z');
        self::assertSame(1, $this->view($vpg, 'V1')['available']);
        // so the site sells it again, legitimately, to order 2
        self::assertSame(201, $this->reserve($vpg, '2', [self::line('V1', 'b')])->status);
        $this->commit($vpg, '2', [self::line('V1', 'b')]);
        // the 11:30 reset arrives: pre_count, allocated +1 only
        $r = $this->res->unship($vpg, '1', ['a'], '2026-09-26T11:30:00Z', $this->key('unship'));
        self::assertSame('unshipped_pre_count', $r->body['units'][0]['result']);
        self::assertSame(-1, $this->view($vpg, 'V1')['available'], 'two paid orders, one unit');
        self::assertSame([['kind' => 'reset_short', 'shortfall' => 1, 'available_after' => -1, 'order_ref' => '1']], $this->events());
    }

    public function testUnprotectedItemsAndRisesAreNotFlagged(): void
    {
        $site = $this->site();
        $legacy = $this->item('legacy', 1);
        $backorder = $this->item('backorder', 1);
        $strict = $this->item('strict', 5);
        foreach (['L' => $legacy, 'B' => $backorder, 'S' => $strict] as $v => $sku) {
            $this->listing($site, $v, $sku);
        }
        $this->commit($site, '1', [self::line('L', 'l1', 'l2'), self::line('B', 'b1', 'b2')]); // legacy/backorder: never flagged
        $this->book('write_off', $legacy, 1);
        $this->book('write_off', $backorder, 1);
        $this->book('write_off', $strict, 1);                   // still >= 0
        $this->book('count', $strict, 2, 'VERIFY', '2026-09-26T12:00:00Z'); // not sellable
        $this->book('adjustment', $strict, -4);                 // down to exactly 0
        self::assertSame(0, $this->view($site, 'S')['available']);
        $this->book('goods_in', $strict, 1);
        self::assertSame([], $this->events());
    }

    /** R7: CW always refuses these lines on a live site; a sale that got past the refusal is flagged. */
    public function testAnUnheldSaleOfAStoppedItemOrQuarantinedListingIsFlaggedWhateverTheStock(): void
    {
        $site = $this->site('vpg', 'live');
        $stopped = $this->item('stopped', 10);
        $strict = $this->item('strict', 10);
        $this->listing($site, 'ST', $stopped);
        $this->listing($site, 'Q', $strict, 1, 'quarantined');
        self::assertSame(409, $this->reserve($site, '1', [self::line('ST', 's1')])->status);
        self::assertSame(409, $this->reserve($site, '2', [self::line('Q', 'q1')])->status);

        $a = $this->commit($site, '3', [self::line('ST', 's2')], 'unreserved'); // outage order
        $b = $this->commit($site, '4', [self::line('Q', 'q2', 'q3')], 'unreserved');
        self::assertSame(['stopped_sale', 1], [$a->body['oversell'][0]['kind'], $a->body['oversell'][0]['shortfall']]);
        self::assertSame(['quarantined_sale', 2], [$b->body['oversell'][0]['kind'], $b->body['oversell'][0]['shortfall']]);
        self::assertSame(['stopped_sale', 'quarantined_sale'], array_column($this->events(), 'kind'));
    }

    public function testOnAShadowSiteOnlyAShortfallIsFlagged(): void
    {
        $site = $this->site('vpg', 'shadow');
        $stopped = $this->item('stopped', 10);
        $this->listing($site, 'ST', $stopped);
        $this->commit($site, '3', [self::line('ST', 's2')], 'unreserved');
        self::assertSame([], $this->events(), 'shadow: CW does not refuse, so nothing got past a refusal');
    }
}
