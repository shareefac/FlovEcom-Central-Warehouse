<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\ChannelAdmin;
use CW\CwException;
use CW\Tests\Support\StockTestCase;

/** POST /v1/movements semantics and the goods-in relay (§3, §9, §14 "Goods-in relay"). */
final class MovementsTest extends StockTestCase
{
    public function testAPayloadWithTheSameVariantTwiceBooksTheSumInCentralUnits(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'TEN', $sku, 10);

        $r = $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-2026-00001', 'lines' => [
            ['variant_id' => 'V1', 'qty' => 5, 'line_index' => 0],
            ['variant_id' => 'V1', 'qty' => 7, 'line_index' => 1],
            ['variant_id' => 'TEN', 'qty' => 2, 'line_index' => 2],
        ]], 'ACC-PINV-2026-00001');
        self::assertSame(200, $r->status);
        self::assertSame([5, 7, 20], array_column($r->body['lines'], 'delta'));
        $this->assertBal(32, 0, 0, $sku);
        self::assertSame(32, $this->view($site, 'V1')['available']);
        self::assertSame(3, $this->view($site, 'TEN')['available']);
    }

    public function testDebitNotesAndErpSalesBookNegativeWhateverTheSignSent(): void
    {
        $site = $this->site();
        $this->grant($site, 'supplier_return', 'erp_sale');
        $sku = $this->item('strict', 20);
        $this->listing($site, 'V1', $sku);
        $this->moves->record($site, ['type' => 'supplier_return', 'doc_ref' => 'ACC-PINV-RET-1', 'lines' => [['variant_id' => 'V1', 'qty' => -3]]], 'k1');
        $this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-SINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 2]]], 'k2');
        $this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-SINV-2', 'lines' => [['variant_id' => 'V1', 'qty' => -1]]], 'k3');
        $this->assertBal(14, 0, 0, $sku);
        self::assertSame([-3, -2, -1], self::$db->column("SELECT qty_delta FROM stock_ledger WHERE movement_type IN ('supplier_return', 'erp_sale') ORDER BY id"));
    }

    public function testUnresolvedLinesLandInSuspenseOnceAndTheRestIsBooked(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'UNLINKED', null);
        $req = ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-9', 'lines' => [
            ['variant_id' => 'V1', 'qty' => 4],
            ['variant_id' => 'NOPE', 'qty' => 6, 'description' => 'Mystery 10mg'],
            ['variant_id' => 'UNLINKED', 'qty' => 2],
        ]];
        $r = $this->moves->record($site, $req, 'relay-1');
        self::assertSame(['booked', 'suspense', 'suspense'], array_column($r->body['lines'], 'result'));
        self::assertSame([null, 'unknown_listing', 'unlinked_listing'], array_map(static fn (array $l): ?string => $l['reason'] ?? null, $r->body['lines']));
        $this->assertBal(4, 0, 0, $sku);
        $rows = self::$db->all('SELECT source, doc_type, doc_ref, line_index, qty, external_variant_id, reason, dedupe_key FROM goods_in_suspense ORDER BY line_index');
        self::assertSame([
            ['source' => 'erp_relay', 'doc_type' => 'purchase_invoice', 'doc_ref' => 'ACC-PINV-9', 'line_index' => 1, 'qty' => 6,
                'external_variant_id' => 'NOPE', 'reason' => 'unknown_listing', 'dedupe_key' => 'goods_in:ACC-PINV-9#1'],
            ['source' => 'erp_relay', 'doc_type' => 'purchase_invoice', 'doc_ref' => 'ACC-PINV-9', 'line_index' => 2, 'qty' => 2,
                'external_variant_id' => 'UNLINKED', 'reason' => 'unlinked_listing', 'dedupe_key' => 'goods_in:ACC-PINV-9#2'],
        ], $rows);

        // the relay sends the document again under another key: suspense is not duplicated
        $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-9', 'lines' => [['variant_id' => 'NOPE', 'qty' => 6, 'line_index' => 1]]], 'relay-2');
        self::assertSame(2, self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'));
    }

    public function testAnErpItemOverrideWinsOverTheListingLink(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $a = $this->item('strict', 0);
        $b = $this->item('strict', 0);
        $this->listing($site, 'V1', $a);
        self::$db->exec("INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES ('ITEM-X', ?, 5)", [$b]);
        $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'P1', 'lines' => [
            ['erp_item_code' => 'ITEM-X', 'variant_id' => 'V1', 'qty' => 2],
            ['erp_item_code' => 'ITEM-UNKNOWN', 'variant_id' => 'V1', 'qty' => 3],
        ]], 'p1');
        $this->assertBal(3, 0, 0, $a);
        $this->assertBal(10, 0, 0, $b);
    }

    public function testStaffMovementsAreIdempotentPerSourceAndKey(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 0);
        $req = ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => 5]]];
        $first = $this->moves->record(self::staff(), $req, 'adj-1');
        $again = $this->moves->record(Caller::staff(2), $req, 'adj-1');
        self::assertTrue($again->replayed, 'staff share one idempotency scope');
        self::assertEquals($first->body, $again->body);
        $this->assertBal(5, 0, 0, $sku);

        // the same key string from a site or a system job is another scope
        $this->grant($site, 'goods_in');
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'PINV-1', 'lines' => [['sku_id' => $sku, 'qty' => 5]]], 'adj-1'));
        $this->ok($this->moves->record(Caller::system('import'), $req, 'adj-1'));
        $this->assertBal(15, 0, 0, $sku);

        $other = $this->moves->record(self::staff(), ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => 6]]], 'adj-1');
        self::assertSame([422, 'idempotency_key_reused'], [$other->status, $other->body['error']]);
        $this->assertBal(15, 0, 0, $sku);
    }

    public function testTransfersWriteOffsAndAdjustments(): void
    {
        $sku = $this->item('strict', 10);
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_out', 'lines' => [['sku_id' => $sku, 'qty' => 4]]], $this->key()));
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_in', 'warehouse' => 'UNSTAMPED', 'lines' => [['sku_id' => $sku, 'qty' => 4]]], $this->key()));
        $this->ok($this->moves->record(self::staff(), ['type' => 'write_off', 'warehouse' => 'UNSTAMPED', 'lines' => [['sku_code' => sprintf('CW-%06d', $sku), 'qty' => 1]]], $this->key()));
        $this->ok($this->moves->record(self::staff(), ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => -2]]], $this->key()));
        $this->assertBal(4, 0, 0, $sku);
        $this->assertBal(3, 0, 0, $sku, 'UNSTAMPED');
    }

    public function testAnUnresolvedLineRefusesAWholeNonRelayMovement(): void
    {
        $sku = $this->item('strict', 5);
        $r = $this->moves->record(self::staff(), ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => 1], ['sku_code' => 'CW-999999', 'qty' => 1]]], 'a1');
        self::assertSame([422, 'unresolved_line'], [$r->status, $r->body['error']]);
        self::assertSame('unknown_sku', $r->body['lines'][0]['reason']);
        $this->assertBal(5, 0, 0, $sku);
    }

    public function testBadRequestsAreRejectedBeforeAnythingIsStored(): void
    {
        $sku = $this->item('strict', 5);
        $bad = [
            ['type' => 'teleport', 'lines' => [['sku_id' => $sku, 'qty' => 1]]],
            ['type' => 'goods_in', 'lines' => [['sku_id' => $sku, 'qty' => 1]]],                 // no doc_ref
            ['type' => 'count', 'lines' => [['sku_id' => $sku, 'qty' => 1]]],                    // no counted_at
            ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => 0]]],
            ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'sku_code' => 'CW-000001', 'qty' => 1]]],
            ['type' => 'adjustment', 'lines' => [['variant_id' => 'V1', 'qty' => 1]]],           // staff cannot name a variant
        ];
        foreach ($bad as $i => $req) {
            try {
                $this->moves->record(self::staff(), $req, "bad-{$i}");
                self::fail("request {$i} should be rejected");
            } catch (CwException $e) {
                self::assertSame(400, $e->httpStatus);
            }
        }
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'bad-%'"));
    }

    /**
     * R17, §3 "POST /v1/movements — ERP relay, staff screens": a site may send only the relay
     * types its channel is granted (none by default); counts, adjustments, write-offs and
     * transfers are staff-only even if a channel row lists them. Found by the review of 26 Sep
     * (slot review3): any site key could count or adjust any item of the shared book.
     */
    public function testASiteSendsOnlyTheRelayTypesItsChannelIsGranted(): void
    {
        $site = $this->site();
        $other = $this->site('alt');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $refused = function (Caller $who, array $req): void {
            try {
                $this->moves->record($who, $req, $this->key('lp'));
                self::fail('expected 403 for ' . $req['type']);
            } catch (CwException $e) {
                self::assertSame([403, 'movement_not_allowed'], [$e->httpStatus, $e->errorCode]);
            }
        };
        $goodsIn = ['type' => 'goods_in', 'doc_ref' => 'P-1', 'lines' => [['sku_id' => $sku, 'qty' => 5]]];
        $refused($site, $goodsIn);   // default: nothing granted
        $refused($other, $goodsIn);
        $this->grant($site, 'goods_in', 'count', 'adjustment'); // count/adjustment can never be a site's
        $this->ok($this->moves->record($site, $goodsIn, 'gi-1'));
        $refused($site, ['type' => 'erp_sale', 'doc_ref' => 'S-1', 'lines' => [['sku_id' => $sku, 'qty' => 1]]]);
        $refused($site, ['type' => 'count', 'counted_at' => '2026-09-26T17:00:00Z', 'lines' => [['sku_id' => $sku, 'qty' => 99]]]);
        $refused($site, ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => -9]]]);
        $refused($other, ['type' => 'write_off', 'lines' => [['sku_id' => $sku, 'qty' => 9]]]);
        $this->assertBal(15, 0, 0, $sku);
        self::assertNull(self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE channel_id IS NOT NULL AND path = '/v1/movements'"));

        // the channel tool grants relay types only
        self::assertSame(['erp_sale', 'goods_in'], ChannelAdmin::movementTypes(['goods_in', ' erp_sale', 'goods_in', '']));
        try {
            ChannelAdmin::movementTypes(['goods_in', 'count']);
            self::fail('count cannot be granted to a site');
        } catch (CwException $e) {
            self::assertSame('bad_movement_type', $e->errorCode);
        }
    }
}
