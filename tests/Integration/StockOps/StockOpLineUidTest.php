<?php

declare(strict_types=1);

namespace CW\Tests\Integration\StockOps;

use CW\Tests\Support\LineUids;

/**
 * Stock records through their own service (StockOps::saveLines -> Documents::setLines; 0070, docs/decisions.md LV8): a stock in, a stock out
 * and an adjustment get line ids at each save, and each posting's fingerprint covers none.
 */
final class StockOpLineUidTest extends StockOpsTestCase
{
    use LineUids;

    public function testStockRecordsGetLineIdsAndTheirFingerprintsCoverNone(): void
    {
        $sku = $this->item('strict', 20);
        $other = $this->item('legacy', 5);
        $sc = $this->staffUser('stock_controller');
        foreach ([
            'in' => [['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '1.25'], ['sku_id' => $other, 'qty' => 1]]],
            'out' => [['reason_code' => 'trade_sale'], [['sku_id' => $sku, 'qty' => 1], ['sku_id' => $other, 'qty' => 1]]],
            'adjust' => [[], [['sku_id' => $sku, 'qty' => -1, 'reason_code' => 'damaged'], ['sku_id' => $other, 'qty' => 1, 'reason_code' => 'found', 'unit_cost' => '1.00']]],
        ] as $kind => [$header, $lines]) {
            $d = $this->draft($kind, $sc, $header, $lines);
            self::assertSame([1, 2], self::uidsOf($d->id), $kind);
            $d = $this->ops->saveLines($sc, $d->id, $d->version, array_reverse($lines));
            self::assertSame([3, 4], self::uidsOf($d->id), "{$kind}: saved again, new ids");
            self::assertSame(5, self::nextUidOf($d->id));
            $p = $this->ops->post($sc, $d->id, $d->version);
            self::assertSame('posted', $p->status, $kind);
            self::assertPostingIgnoresUids($this->docs, $p->id);
        }
    }
}
