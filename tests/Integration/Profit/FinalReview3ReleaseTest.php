<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Profit\CostEngine;
use CW\Tests\Integration\StockOps\StockOpsTestCase;

/**
 * The third final review of bc7e248 (build/A/codex_final3_astra_c.txt, c-M3; final2/FIX_BRIEF_A4.md ALSO FIX; docs/decisions.md PR154), shown
 * failing first on bc7e248: a priced release of another account's stock into ours (a REL document: its goods_in at the agreed price, a
 * delivery to the cost job) whose booking became visible after it left the job's window is found by the look back for documents not read
 * (it looked for receipts, invoices and credit notes only: the item kept the price it had, for good, even through the nightly check).
 */
final class FinalReview3ReleaseTest extends StockOpsTestCase
{
    private const EMPTY_BIN = "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n";

    private function engine(int $ahead = 0): CostEngine
    {
        return new CostEngine(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60 + $ahead) . ' seconds'));
    }

    public function testAPricedReleaseThatCameLateIsReadByTheLookBack(): void
    {
        $this->site('vapeandgo', 'live');
        $this->warehouse('VPG2', 'other', 'VPG Two Ltd');
        $sku = $this->item('strict', 0);
        $sc = $this->staffUser('stock_controller');
        $desk = $this->staffUser('purchasing_desk');
        $start = Clock::db(Clock::now()->modify('-3 hours'));
        $plan = $this->engine()->plan(self::EMPTY_BIN, 'vapeandgo', $start);
        $this->engine()->seed($plan['prices'], $start);
        $this->engine()->run(); // the job's mark is past now: the release below is read only by the look back
        $in = $this->posted('in', $sc, ['warehouse' => 'VPG2', 'reason_code' => 'opening_stock'], [['sku_id' => $sku, 'qty' => 100]]);
        $rel = $this->posted('release', $desk, ['warehouse' => 'VPG2', 'to_warehouse' => 'MAIN', 'external_ref' => 'VPG2-INV-9'], [['sku_id' => $sku, 'qty' => 100,
            'unit_cost' => '3.00']]);
        // Its booking committed late: its own time two hours ago, long before the window the job reads again (one hour).
        $then = Clock::db(Clock::now()->modify('-2 hours'));
        $posted = [];
        foreach ([$in->id, $rel->id] as $doc) {
            $posted[$doc] = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$doc]);
        }
        foreach ([$in->id => Clock::db(Clock::now()->modify('-2 hours -1 minute')), $rel->id => $then] as $doc => $t) {
            self::$db->exec('UPDATE stock_ledger l LEFT JOIN stock_value_seq s ON s.stock_ledger_id = l.id SET l.created_at = ?, s.created_at = ? WHERE l.document_id = ?',
                [$t, $t, $doc]);
            self::$db->exec('UPDATE document SET posted_at = ? WHERE id = ?', [$t, $doc]);
        }
        self::assertSame(100, $this->onHand($sku));
        $this->engine(60)->run(reconcile: true);
        self::assertSame(30000, (int) self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]),
            'the release read by the look back: its 100 at £3 (it kept no price for good)');
        self::assertNotNull(self::$db->value('SELECT 1 FROM item_cost_document WHERE document_id = ?', [$rel->id]), 'marked read');
        self::assertSame(30000, $this->engine(120)->rebuild($sku), 'and replayed by a history written again');
        // The documents' posting times put back (a posted document's time is write-once: the invariants check it after the test).
        foreach ($posted as $doc => $t) {
            self::$db->exec('UPDATE document SET posted_at = ? WHERE id = ?', [$t, $doc]);
        }
    }
}
