<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Receiving\ReceiptLinesFile;
use CW\Receiving\ReceiptMath;
use CW\Receiving\SellingModes;
use PHPUnit\Framework\TestCase;

/**
 * The receipt's arithmetic and pure rules (IM6; I129, I130, I134, I136, I140): the invoice key, where a line's units go, the
 * expected duty, the tolerance cap, the selling mode on receipt, the supplier sheet's header names.
 */
final class ReceiptMathTest extends TestCase
{
    public function testTheInvoiceKey(): void
    {
        self::assertSame('INV001', ReceiptMath::invoiceKey(' inv 001 '));
        self::assertSame('INV-001/A', ReceiptMath::invoiceKey("inv-001/a\u{00A0}"));
        self::assertSame('SI12', ReceiptMath::invoiceKey("si\t1\u{3000}2"));
        self::assertSame('', ReceiptMath::invoiceKey(null));
        self::assertSame('ÉTÉ-7', ReceiptMath::invoiceKey('été-7'));
    }

    public function testWhereTheUnitsGo(): void
    {
        $l = static fn (array $over): array => $over + ['units' => 10, 'short_units' => 0, 'over_units' => 0, 'damaged_units' => 0, 'wrong_item_units' => 0,
            'unstamped_units' => 0, 'unstamped_action' => null, 'linked_to_po' => true];
        self::assertSame(['units' => 10, 'accepted' => 10, 'verify' => 0, 'quarantine' => 0, 'refused' => 0, 'short' => 0, 'po_units' => 10, 'arrived' => 10, 'extras' => null],
            ReceiptMath::split($l([])));
        self::assertSame(['units' => 10, 'accepted' => 2, 'verify' => 6, 'quarantine' => 2, 'refused' => 0, 'short' => 1, 'po_units' => 2, 'arrived' => 4, 'extras' => null],
            ReceiptMath::split($l(['short_units' => 1, 'damaged_units' => 2, 'wrong_item_units' => 3, 'over_units' => 1, 'unstamped_units' => 2, 'unstamped_action' => 'quarantine'])),
            'a line that needs no stamp: damaged and over to VERIFY');
        // I167: an unstamped delivery's damaged and over units are unstamped too (they follow its unstamped units); wrong items stay in VERIFY.
        $stamped = static fn (array $over): array => $l($over + ['stamp_required' => true, 'stamp_on_pack' => 1]);
        self::assertSame(['accepted' => 2, 'verify' => 3, 'quarantine' => 5, 'refused' => 0, 'extras' => 'quarantine'], array_intersect_key(ReceiptMath::split($stamped(['short_units' => 1,
            'damaged_units' => 2, 'wrong_item_units' => 3, 'over_units' => 1, 'unstamped_units' => 2, 'unstamped_action' => 'quarantine'])), array_flip(['accepted', 'verify',
            'quarantine', 'refused', 'extras'])));
        self::assertSame(['accepted' => 0, 'verify' => 0, 'quarantine' => 0, 'refused' => 15, 'extras' => 'refuse'], array_intersect_key(ReceiptMath::split($l(['units' => 10,
            'damaged_units' => 2, 'over_units' => 5, 'unstamped_units' => 8, 'unstamped_action' => 'refuse', 'stamp_required' => true, 'stamp_on_pack' => 0])),
            array_flip(['accepted', 'verify', 'quarantine', 'refused', 'extras'])), 'the reviewer\'s probe C: 8 refused + 2 damaged + 5 over, none of them in VERIFY');
        self::assertSame(['verify' => 0, 'quarantine' => 13, 'extras' => 'quarantine'], array_intersect_key(ReceiptMath::split($l(['damaged_units' => 10, 'over_units' => 3,
            'stamp_required' => true, 'stamp_on_pack' => 0])), array_flip(['verify', 'quarantine', 'extras'])), 'no stamp, nothing unstamped counted (all damaged): quarantined');
        self::assertSame(['verify' => 3, 'extras' => null], array_intersect_key(ReceiptMath::split($l(['damaged_units' => 2, 'over_units' => 1, 'unstamped_units' => 4,
            'unstamped_action' => 'accept_pre_october', 'stamp_required' => true, 'stamp_on_pack' => 0])), array_flip(['verify', 'extras'])), 'pre-October stock is legal: VERIFY');
        self::assertSame(['verify' => 3, 'extras' => null], array_intersect_key(ReceiptMath::split($stamped(['damaged_units' => 2, 'over_units' => 1])),
            array_flip(['verify', 'extras'])), 'stamped, nothing unstamped: VERIFY');
        self::assertNull(ReceiptMath::extras(false, 0, 'refuse'), 'a line that needs no stamp');
        self::assertSame(10, ReceiptMath::split($l(['unstamped_units' => 4, 'unstamped_action' => 'accept_pre_october']))['accepted'], 'accepted with the evidence');
        self::assertSame([6, 4, 0], array_values(array_intersect_key(ReceiptMath::split($l(['unstamped_units' => 4, 'unstamped_action' => 'refuse', 'linked_to_po' => false])),
            ['accepted' => 0, 'refused' => 0, 'po_units' => 0])));
        $this->expectException(\InvalidArgumentException::class);
        ReceiptMath::split($l(['short_units' => 6, 'damaged_units' => 5]));
    }

    public function testTheExpectedDutyRoundedDownPerUnit(): void
    {
        $card = static fn (?string $type, ?string $ml, ?bool $duty = true): array => ['product_type' => $type, 'liquid_ml' => $ml, 'duty_liable' => $duty];
        self::assertSame(220, ReceiptMath::dutyPencePerUnit($card('e_liquid', '10.0'), 22), '£2.20 per 10 ml');
        self::assertSame(1100, ReceiptMath::dutyPencePerUnit($card('shortfill', '50.0'), 22));
        self::assertSame(41, ReceiptMath::dutyPencePerUnit($card('prefilled_pod', '1.9'), 22), '41.8p rounded down');
        self::assertSame(44, ReceiptMath::dutyPencePerUnit($card(null, '2'), 22), 'no type: the ml says it');
        self::assertNull(ReceiptMath::dutyPencePerUnit($card('device_kit', '2.0'), 22), 'a kit\'s ml is its tank');
        self::assertNull(ReceiptMath::dutyPencePerUnit($card('coil', null, false), 22));
        self::assertNull(ReceiptMath::dutyPencePerUnit($card('e_liquid', '10.0', null), 22), 'not answered: no figure');
        self::assertNull(ReceiptMath::dutyPencePerUnit($card('e_liquid', null), 22));
        self::assertSame(105, ReceiptMath::mlTenths('10.5'));
        self::assertSame('£1,234.56', ReceiptMath::money(123456));
        self::assertSame('0.07', ReceiptMath::decimal(7));
    }

    public function testTheToleranceCap(): void
    {
        self::assertSame(11, ReceiptMath::toleranceCap(10, 10));
        self::assertSame(10, ReceiptMath::toleranceCap(10, 0));
        self::assertSame(108, ReceiptMath::toleranceCap(99, 10), '108.9 rounded down');
        self::assertSame(10, ReceiptMath::toleranceCap(10, -5), 'never below the order');
    }

    public function testTheSellingModeOnReceipt(): void
    {
        $now = static fn (?string $mode, ?string $previous = null): array => ['mode' => $mode, 'previous' => $previous];
        self::assertSame(['mode' => 'In-Stock', 'source' => 'last'], SellingModes::resolve($now('In-Stock'), 'default', 'From-Warehouse'));
        self::assertSame(['mode' => 'In-Stock', 'source' => 'previous'], SellingModes::resolve($now('Out-Of-Stock', 'In-Stock'), 'default', 'From-Warehouse'));
        self::assertSame(['mode' => 'From-Warehouse', 'source' => 'fallback'], SellingModes::resolve($now('Out-Of-Stock'), 'default', 'From-Warehouse'));
        self::assertSame(['mode' => 'In-Stock', 'source' => 'fallback'], SellingModes::resolve($now(null), 'default', 'In-Stock'));
        self::assertSame(['mode' => 'From-Warehouse', 'source' => 'fallback'], SellingModes::resolve($now(null), 'default', 'Out-Of-Stock'), 'never a fallback of Out-Of-Stock');
        self::assertSame(['mode' => 'Out-Of-Stock', 'source' => 'chosen'], SellingModes::resolve($now('In-Stock'), 'Out-Of-Stock', 'From-Warehouse'));
        self::assertSame('In-Stock', SellingModes::previousFor($now('In-Stock'), 'Out-Of-Stock'));
        self::assertSame('From-Warehouse', SellingModes::previousFor($now('Out-Of-Stock', 'From-Warehouse'), 'Out-Of-Stock'), 'kept while it stays out');
        self::assertNull(SellingModes::previousFor($now('Out-Of-Stock', 'In-Stock'), 'In-Stock'));
        self::assertSame(['In-Stock', 'From-Warehouse', 'Out-Of-Stock', null, null], array_map(SellingModes::label(...), ['in stock', 'From-Warehouse', 'OUT_OF_STOCK', 'Sold', null]));
    }

    public function testTheSupplierSheetHeaderNames(): void
    {
        self::assertSame('unitprice', ReceiptLinesFile::headerKey(' Unit Price (£) '));
        self::assertSame('ean13', ReceiptLinesFile::headerKey('EAN-13'));
        self::assertSame('qty', ReceiptLinesFile::headerKey("\u{FEFF}Qty."));
        $resolve = static fn (string $type, string $value): array|string => $type === 'supplier_code' && $value === 'A1'
            ? ['sku_id' => 7, 'sku_code' => 'CW-000007', 'units_per_scan' => 1, 'supplier_item' => ['id' => 3, 'units_per_pack' => 12, 'purchase_unit' => 'case', 'supplier_code' => 'A1']]
            : "unknown {$value}";
        $r = ReceiptLinesFile::parse(['format' => 'csv', 'rows' => [1 => ['ACME TRADING'], 3 => ['Product Code', 'Quantity Shipped', 'Case Size', 'Total Units', 'Net Price'],
            4 => ['A1', '2', '12', '24', '30.00'], 5 => ['', 'Delivery', '', '', '5.00']]], $resolve);
        self::assertSame([], $r['errors']);
        self::assertSame(3, $r['header_row']);
        self::assertSame([['sku_id' => 7, 'supplier_item_id' => 3, 'supplier_code' => null, 'units_per_pack' => 12, 'packs' => 2, 'pack_price' => '30.0000',
            'description' => null, 'row' => 4]], $r['lines']);
        self::assertSame(['row 5: Delivery 5.00 (no item code)'], $r['skipped']);
    }
}
