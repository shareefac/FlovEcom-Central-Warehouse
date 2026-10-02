<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Invariants;
use CW\Tests\Support\StockTestCase;

/**
 * Invariants 10-11 (the per-unit VERIFY moves of cancel and uncancel, D37, D46) report each kind of
 * corruption. The corruption is written with the admin connection, so this class checks the
 * invariants itself instead of asserting them clean after every test.
 */
final class UncancelInvariantsTest extends StockTestCase
{
    private int $sku;
    private int $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $site = $this->site();
        $this->channel = (int) $site->channelId;
        $this->sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $this->sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->ok($this->res->cancel($site, '1', ['a', 'b'], false, 'cnl'));
        $this->ok($this->res->uncancel($site, '1', ['a'], 'unc'));
        self::assertSame([], Invariants::check(self::$db), 'clean before the corruption');
    }

    protected function assertPostConditions(): void
    {
    }

    private function assertReports(string $needle): void
    {
        $v = Invariants::check(self::$db);
        $hit = array_filter($v, static fn (string $s): bool => str_contains($s, $needle));
        self::assertNotSame([], $hit, "expected a violation containing \"{$needle}\", got: " . json_encode($v));
    }

    public function testAHalfPairIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET note = NULL WHERE idem_key = 'unc' AND movement_type = 'transfer_in'");
        $this->assertReports("unit {$this->channel}:a: its uncancel_from_verify move under key unc is not one balanced pair through VERIFY (net -1, 1 out, 0 in");
    }

    public function testAPairOnTheWrongSideIsReported(): void
    {
        // the cancel's pair as if it had left VERIFY instead of arriving there
        self::$db->exec("UPDATE stock_ledger SET warehouse_id = IF(warehouse_id = ?, ?, ?) WHERE idem_key = 'cnl' AND unit_id = 'b' AND bucket = 'on_hand'",
            [self::warehouseId('VERIFY'), self::warehouseId('MAIN'), self::warehouseId('VERIFY')]);
        $this->assertReports("unit {$this->channel}:b: its cancel_not_restockable move under key cnl is not one balanced pair through VERIFY");
    }

    public function testMoreTakenBackThanParkedIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET note = 'by hand' WHERE idem_key = 'cnl' AND unit_id = 'a' AND movement_type IN ('transfer_out', 'transfer_in')");
        $this->assertReports("unit {$this->channel}:a: its VERIFY moves of item {$this->sku} net -1 (more taken back than parked)");
    }

    public function testAMovedBackUnitWithAnOpenRecountIsReported(): void
    {
        $id = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'verify_recount' AND status = 'dismissed'");
        self::$db->exec("UPDATE count_review SET status = 'open', resolved_at = NULL, resolution = NULL, dedupe_key = ? WHERE id = ?",
            ["verify:{$this->channel}:a", $id]);
        $this->assertReports("unit {$this->channel}:a was moved back from VERIFY but its recount {$id} is still open under its key");
    }
}
