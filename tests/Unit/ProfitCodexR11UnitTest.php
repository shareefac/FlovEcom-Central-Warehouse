<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Profit\SnapshotBody;
use PHPUnit\Framework\TestCase;

/**
 * The Codex check of the fix round (r11, build/A/codex_r11_astra.txt), shown failing first on 973c8ff: the shop's own place (its collection
 * point, Preston) is kept only in the name of its collection, never in a name with a number that could be an address (F12 PARTLY).
 */
final class ProfitCodexR11UnitTest extends TestCase
{
    public function testThePlaceOfTheShopsCollectionPointIsKeptOnlyInItsCollectionsName(): void
    {
        foreach (['Delivery 12 Preston', 'Tracked Preston 5', 'Preston Delivery'] as $address) {
            self::assertNull(SnapshotBody::serviceName($address), $address);
        }
        foreach (['In Store Collection Preston', 'Click and Collect Preston', 'Royal Mail Tracked 48 (2-4 Working Days)'] as $real) {
            self::assertSame($real, SnapshotBody::serviceName($real), $real);
        }
    }
}
