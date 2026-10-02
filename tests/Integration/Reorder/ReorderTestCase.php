<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\ReorderList;
use CW\Tests\Integration\PurchaseOrders\PurchaseOrderTestCase;

/**
 * Base of the reorder service tests (docs/decisions.md I60-I71): the fixtures of ReorderFixtures and the purchase-order
 * fixtures of PurchaseOrderTestCase. Every test ends with the full invariant check (StockTestCase).
 */
abstract class ReorderTestCase extends PurchaseOrderTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    protected function reorderList(): ReorderList
    {
        return new ReorderList(self::$db, $this->pos);
    }
}
