<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\Tests\Support\StockTestCase;

/** I1, I6, I8: a unit cost on staff movements (POST /v1/movements as staff), stored on the ledger row. */
final class MovementCostTest extends StockTestCase
{
    /** @return array{unit_cost: ?string, cost_currency: ?string, cost_source: ?string, document_id: mixed, document_line: mixed} */
    private function costOfLast(int $sku): array
    {
        $r = self::$db->one('SELECT unit_cost, cost_currency, cost_source, document_id, document_line FROM stock_ledger WHERE sku_id = ? ORDER BY id DESC LIMIT 1', [$sku]);
        self::assertNotNull($r);
        return $r;
    }

    public function testCostIsStoredOnEveryCostBearingType(): void
    {
        $sku = $this->item('strict', 100);
        $manual = static fn (string $c): array => ['unit_cost' => $c, 'cost_currency' => 'GBP', 'cost_source' => 'manual', 'document_id' => null, 'document_line' => null];

        $this->book('goods_in', $sku, 10, 'MAIN', null, '1.5');
        self::assertSame($manual('1.500000'), $this->costOfLast($sku));
        $this->book('supplier_return', $sku, 2, 'MAIN', null, '0.25');
        self::assertSame($manual('0.250000'), $this->costOfLast($sku));
        $this->book('adjustment', $sku, 3, 'MAIN', null, '2');
        self::assertSame($manual('2.000000'), $this->costOfLast($sku));
        $this->book('adjustment', $sku, -4, 'MAIN', null, '12.345678');
        self::assertSame($manual('12.345678'), $this->costOfLast($sku));
        $this->book('write_off', $sku, 1, 'MAIN', null, '0');
        self::assertSame($manual('0.000000'), $this->costOfLast($sku));
        $this->book('count', $sku, 90, 'MAIN', '2026-09-26T17:00:00Z', '99999999.999999');
        self::assertSame($manual('99999999.999999'), $this->costOfLast($sku));
        $this->assertBal(90, 0, 0, $sku);

        // Without a cost nothing changes: NULL cost, no currency, no source (valued at average in IM8).
        $this->book('goods_in', $sku, 1);
        self::assertSame(['unit_cost' => null, 'cost_currency' => null, 'cost_source' => null, 'document_id' => null, 'document_line' => null], $this->costOfLast($sku));
        self::assertSame(6, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND unit_cost IS NOT NULL", [$sku]));
    }

    public function testTypesWithoutACostRefuseOne(): void
    {
        $sku = $this->item('strict', 10);
        foreach (['erp_sale', 'transfer_out', 'transfer_in'] as $type) {
            try {
                $this->moves->record(self::staff(), ['type' => $type, 'doc_ref' => 'X-1', 'lines' => [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '1.00']]], $this->key());
                self::fail("{$type} with a cost was accepted");
            } catch (CwException $e) {
                self::assertSame(['cost_not_allowed', 400, ['field' => 'lines[0].unit_cost']], [$e->errorCode, $e->httpStatus, $e->detail], $type);
            }
        }
        // An explicit null is no cost at all (the canonical request is the one without the field).
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_out', 'lines' => [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => null]]], $this->key()));
        $this->assertBal(9, 0, 0, $sku);
    }

    public function testASiteCannotSendACostAndNothingIsStored(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in', 'supplier_return');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $before = (int) self::$db->value('SELECT COUNT(*) FROM idempotency');
        foreach (['goods_in', 'supplier_return'] as $type) {
            try {
                $this->moves->record($site, ['type' => $type, 'doc_ref' => 'ACC-PINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 5, 'unit_cost' => 1.5]]], 'site-cost-' . $type);
                self::fail("a site's {$type} cost was accepted");
            } catch (CwException $e) {
                self::assertSame(['cost_not_allowed', 400], [$e->errorCode, $e->httpStatus], $type);
            }
        }
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM idempotency'), 'no idempotency row');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$sku]));
        // The same key without the cost then books normally (nothing was stored under it).
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 5]]], 'site-cost-goods_in'));
        $this->assertBal(5, 0, 0, $sku);
    }

    public function testCountLinesOfOneItemWithDifferentCostsConflict(): void
    {
        $sku = $this->item('strict', 10);
        $other = $this->item('strict', 10);
        $count = fn (array $lines): mixed => $this->moves->record(self::staff(), ['type' => 'count', 'counted_at' => '2026-09-26T17:00:00Z', 'lines' => $lines], $this->key());
        foreach ([
            [['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5'], ['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '1.6']],
            [['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5'], ['sku_id' => $sku, 'qty' => 4]],
        ] as $lines) {
            try {
                $count($lines);
                self::fail('different costs on one counted item were accepted');
            } catch (CwException $e) {
                self::assertSame(['cost_conflict', 400], [$e->errorCode, $e->httpStatus]);
            }
        }
        $this->assertBal(10, 0, 0, $sku);
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));

        // The same cost written two ways is one cost; another item may carry another one. The summed row takes it.
        $this->ok($count([['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5'], ['sku_id' => $sku, 'qty' => 4, 'unit_cost' => 1.5],
            ['sku_id' => $other, 'qty' => 2, 'unit_cost' => '9']]));
        $this->assertBal(7, 0, 0, $sku);
        self::assertSame(['1.500000', '9.000000'], self::$db->column("SELECT unit_cost FROM stock_ledger WHERE movement_type = 'count' ORDER BY sku_id"));
    }

    public function testOneCostWrittenThreeWaysIsAReplayAndAnotherCostIsAnotherRequest(): void
    {
        $sku = $this->item('strict', 0);
        $req = static fn (mixed $cost): array => ['type' => 'goods_in', 'doc_ref' => 'PINV-77', 'lines' => [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => $cost]]];
        $first = $this->ok($this->moves->record(self::staff(), $req('1.5'), 'gi-cost'));
        foreach ([1.5, '1.500000', '1.50'] as $same) {
            $again = $this->moves->record(self::staff(), $req($same), 'gi-cost');
            self::assertTrue($again->replayed, var_export($same, true));
            self::assertEquals($first->body, $again->body);
        }
        $this->assertBal(4, 0, 0, $sku);
        $other = $this->moves->record(self::staff(), $req('1.51'), 'gi-cost');
        self::assertSame([422, 'idempotency_key_reused'], [$other->status, $other->body['error']]);
        $none = $this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'PINV-77', 'lines' => [['sku_id' => $sku, 'qty' => 4]]], 'gi-cost');
        self::assertSame([422, 'idempotency_key_reused'], [$none->status, $none->body['error']], 'the request without a cost is another request');
        $this->assertBal(4, 0, 0, $sku);
        // The response body has no cost field (byte-identical shape to before C0).
        self::assertSame(['line_index', 'result', 'sku_code', 'delta', 'on_hand'], array_keys($first->body['lines'][0]));
    }

    public function testTradeSaleIsDocumentOnly(): void
    {
        $sku = $this->item('strict', 10);
        try {
            $this->moves->record(self::staff(), ['type' => 'trade_sale', 'doc_ref' => 'TRD-1', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key());
            self::fail('trade_sale was accepted by record()');
        } catch (CwException $e) {
            self::assertSame(['bad_type', 400], [$e->errorCode, $e->httpStatus]);
            self::assertStringNotContainsString('trade_sale', $e->getMessage());
        }
        $this->assertBal(10, 0, 0, $sku);
    }

    public function testABadCostIsRefusedBeforeAnythingIsStored(): void
    {
        $sku = $this->item('strict', 0);
        foreach (['-1', '1.2345678', '1e3', '01.5', 100_000_000, '1,5', ' 1'] as $bad) {
            try {
                $this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'PINV-B', 'lines' => [['sku_id' => $sku, 'qty' => 1], ['sku_id' => $sku, 'qty' => 1, 'unit_cost' => $bad]]], $this->key());
                self::fail(var_export($bad, true) . ' was accepted');
            } catch (CwException $e) {
                self::assertSame(['bad_cost', 400, ['field' => 'lines[1].unit_cost']], [$e->errorCode, $e->httpStatus, $e->detail], var_export($bad, true));
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$sku]));
    }
}
