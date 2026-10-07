<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\SiteWriter\SiteView;
use PHPUnit\Framework\TestCase;

/**
 * What a site writes for one listing (IM10; docs/decisions.md I150): the `site` block of the feed, pure. Counted items follow their
 * policy on every site (plan §6.5, §7.4), a blocked item is Out-Of-Stock whatever it is (I160), a legacy item takes the mode CW
 * set for that site or keeps the site's own; nothing at all while the site's switch is off or the listing is not linked.
 */
final class SiteViewTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private static function rule(array $over): array
    {
        return SiteView::rule($over + ['writer_on' => true, 'linked' => true, 'status' => 'mapped', 'policy' => 'legacy', 'qty' => 7, 'blocked' => [], 'site' => null]);
    }

    public function testNothingWhileTheSwitchIsOffOrTheListingIsNotLinked(): void
    {
        self::assertSame(['writer' => false, 'why' => 'writer_off'], self::rule(['writer_on' => false, 'policy' => 'strict']));
        self::assertSame(['writer' => false, 'why' => 'unlinked'], self::rule(['linked' => false, 'qty' => null]));
    }

    public function testCountedItemsFollowTheirPolicyOnEverySite(): void
    {
        $pick = static fn (array $r): array => [$r['mode'], $r['backorders'], $r['why'], $r['qty']];
        $site = ['mode' => 'In-Stock', 'threshold' => 4];
        self::assertSame(['From-Warehouse', 0, 'strict', 7], $pick(self::rule(['policy' => 'strict', 'site' => $site])), 'a site mode never overrides a policy');
        self::assertSame(['From-Warehouse', 1, 'backorder', -3], $pick(self::rule(['policy' => 'backorder', 'qty' => -3])), 'a negative figure is the arrange signal');
        self::assertSame(['Out-Of-Stock', 0, 'stopped', 7], $pick(self::rule(['policy' => 'stopped'])));
        self::assertSame(['Out-Of-Stock', 0, 'quarantined', 7], $pick(self::rule(['policy' => 'strict', 'status' => 'quarantined'])));
        self::assertSame(4, self::rule(['policy' => 'strict', 'site' => $site])['low_stock_threshold'], 'the threshold is per site for every item');
        self::assertSame('sold while there is stock', self::rule(['policy' => 'strict'])['meaning']);
        self::assertSame('sold whatever the figure (back-ordered below zero)', self::rule(['policy' => 'backorder'])['meaning']);
    }

    public function testLegacyItemsTakeTheirSiteModeOrKeepTheSitesOwn(): void
    {
        $r = self::rule(['site' => ['mode' => 'In-Stock', 'threshold' => null]]);
        self::assertSame([true, 7, 'In-Stock', null, null, 'site_mode', 'sold whatever the figure'],
            [$r['writer'], $r['qty'], $r['mode'], $r['backorders'], $r['low_stock_threshold'], $r['why'], $r['meaning']]);
        $r = self::rule(['site' => ['mode' => 'From-Warehouse', 'threshold' => 3]]);
        self::assertSame(['From-Warehouse', null, 3, 'sold while there is stock (unless the site allows backorders)'],
            [$r['mode'], $r['backorders'], $r['low_stock_threshold'], $r['meaning']], 'a legacy item keeps the site\'s own back-order flag');
        $r = self::rule([]);
        self::assertSame([true, 7, null, null, 'site_own', 'the site keeps its own selling mode'], [$r['writer'], $r['qty'], $r['mode'], $r['backorders'], $r['why'],
            $r['meaning']], 'the quantity is written, the mode left alone');
    }

    public function testABlockedItemIsOutOfStockWhateverItIs(): void
    {
        foreach (['legacy', 'strict', 'backorder'] as $policy) {
            $r = self::rule(['policy' => $policy, 'blocked' => ['trpr_refill_ml'], 'site' => ['mode' => 'In-Stock', 'threshold' => 2]]);
            self::assertSame(['Out-Of-Stock', 0, 'blocked', 7, 2], [$r['mode'], $r['backorders'], $r['why'], $r['qty'], $r['low_stock_threshold']], $policy);
            self::assertSame('not sold: blocked by its item card (nicotine refill over 10 ml)', $r['meaning']);
        }
    }

    /**
     * A force ends (review 7 Oct, I178): blocked, quarantined or stopped forces Out-Of-Stock; once it ends a legacy item with no mode
     * of CW's for the site is site_own again (mode null: the site's writer restores the mode it overwrote, SC15), while one CW set a
     * mode for gets that mode back from CW itself.
     */
    public function testAForcedOutOfStockEndsInTheSitesOwnModeOrCwsMode(): void
    {
        self::assertSame(['blocked', 'quarantined', 'stopped'], SiteView::FORCED);
        foreach ([['blocked' => ['trpr_refill_ml']], ['status' => 'quarantined'], ['policy' => 'stopped']] as $force) {
            $r = self::rule($force);
            self::assertSame('Out-Of-Stock', $r['mode']);
            self::assertContains($r['why'], SiteView::FORCED);
        }
        self::assertSame(['site_own', null, null], array_values(array_intersect_key(self::rule([]), ['mode' => 0, 'backorders' => 0, 'why' => 0])), 'after: the site\'s own');
        self::assertSame(['site_mode', 'In-Stock'], array_values(array_intersect_key(self::rule(['site' => ['mode' => 'In-Stock', 'threshold' => null]]), ['mode' => 0, 'why' => 0])));
        foreach (['strict', 'backorder', 'site_mode', 'site_own', 'writer_off', 'unlinked'] as $why) {
            self::assertNotContains($why, SiteView::FORCED);
        }
    }

    public function testTheSaleTypesAreTheOnesASiteLogsItself(): void
    {
        self::assertSame(['reserve', 'release', 'expire', 'commit', 'commit_release', 'ship', 'unship', 'opening', 'adopt'], SiteView::SALE_TYPES);
        foreach (['goods_in', 'supplier_return', 'adjustment', 'count', 'write_off', 'transfer_in', 'transfer_out', 'cancel', 'uncancel', 'return', 'erp_sale'] as $t) {
            self::assertNotContains($t, SiteView::SALE_TYPES, "{$t} is described by the site writer");
        }
    }
}
