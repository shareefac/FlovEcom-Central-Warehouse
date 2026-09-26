<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\ApiTestCase;

/** GET /v1/changes, /v1/availability, /v1/snapshot over HTTP (plan §3, §4; D38, D39). */
final class ApiFeedTest extends ApiTestCase
{
    public function testChangesCarryValuesAndVersionsPerListing(): void
    {
        [$site, $key] = $this->apiSite();
        [, $otherKey] = $this->apiSite('vapebig');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V10', $sku, 10);
        $v9 = $this->listing($site, 'V9', null);

        $first = self::assertEnvelope($this->call('GET', '/v1/changes?after=0', $key), 200)->data();
        self::assertSame(['listings', 'more', 'next_after', 'resync'], array_keys($first));
        self::assertFalse($first['more']);
        $views = array_column($first['listings'], null, 'variant_id');
        self::assertSame([10, 1], [$views['V1']['available'], $views['V10']['available']]);
        self::assertSame('in_stock', $views['V1']['state']);
        self::assertSame(self::code($sku), $views['V1']['sku_code']);
        self::assertSame($first['next_after'], $views['V1']['version']);
        self::assertArrayNotHasKey('V9', $views, 'nothing changed for the unlinked listing');
        // Another site's feed never shows this site's listings.
        self::assertSame([], $this->call('GET', '/v1/changes?after=0', $otherKey)->data()['listings']);

        // A sale: new values with a newer version.
        $this->call('POST', '/v1/reservations', $key, ['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'b', 'c')]]);
        $next = self::assertEnvelope($this->call('GET', '/v1/changes?after=' . $first['next_after'], $key), 200)->data();
        $views = array_column($next['listings'], null, 'variant_id');
        self::assertSame([7, 0], [$views['V1']['available'], $views['V10']['available']]);
        self::assertSame('out_of_stock', $views['V10']['state']);
        self::assertGreaterThan($first['next_after'], $views['V1']['version']);
        self::assertSame($next['next_after'], $views['V1']['version']);

        // A link change reaches the site as a listing row.
        $this->relink($v9, $sku, 'mapped', 2);
        $after = self::assertEnvelope($this->call('GET', '/v1/changes?after=' . $next['next_after'], $key), 200)->data();
        $views = array_column($after['listings'], null, 'variant_id');
        self::assertSame(['mapped', 3], [$views['V9']['link'], $views['V9']['available']]);
        self::assertFalse($after['resync']);

        // A warehouse change is channel-wide: the site is told to re-snapshot.
        self::$db->exec("INSERT INTO warehouse (code, name, is_sellable) VALUES ('MAIN2', 'Second building', 1)");
        $this->ok($this->stock->assignSellableWarehouse(self::staff(), (int) $site->channelId, 'MAIN2', $this->key()));
        $wide = self::assertEnvelope($this->call('GET', '/v1/changes?after=' . $after['next_after'], $key), 200)->data();
        self::assertTrue($wide['resync']);
        // ...only that site: the other site's feed has no reason to re-snapshot.
        self::assertFalse($this->call('GET', '/v1/changes?after=' . $after['next_after'], $otherKey)->data()['resync']);
    }

    public function testAvailabilityAndSnapshot(): void
    {
        [$site, $key] = $this->apiSite();
        [, $otherKey] = $this->apiSite('vapebig');
        $sku = $this->item('backorder', 2);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V3', $sku, 3);
        $this->listing($site, 'V9', null);
        $this->listing($site, 'L1', $this->item('legacy', 4));

        $r = self::assertEnvelope($this->call('GET', '/v1/availability?variant_ids=V1,V3,V9,NOPE', $key), 200);
        $views = array_column($r->data()['listings'], null, 'variant_id');
        self::assertCount(4, $views);
        self::assertSame([2, 'in_stock'], [$views['V1']['available'], $views['V1']['state']]);
        self::assertSame([0, 'backorder'], [$views['V3']['available'], $views['V3']['state']]);
        self::assertSame(['unmapped', 'unlinked', null], [$views['V9']['link'], $views['V9']['state'], $views['V9']['available']]);
        self::assertSame(['unknown', 'unlinked', 0], [$views['NOPE']['link'], $views['NOPE']['state'], $views['NOPE']['version']]);
        $r = self::assertEnvelope($this->call('GET', '/v1/availability?variant_ids[]=L1&variant_ids[]=V1', $key), 200);
        self::assertSame(['legacy', 4], [array_column($r->data()['listings'], null, 'variant_id')['L1']['state'],
            array_column($r->data()['listings'], null, 'variant_id')['L1']['available']]);
        // Another site does not see this site's listings.
        $r = self::assertEnvelope($this->call('GET', '/v1/availability?variant_ids=V1', $otherKey), 200);
        self::assertSame('unknown', $r->data()['listings'][0]['link']);

        // Snapshot pages through every listing of the channel.
        $seen = [];
        $after = 0;
        $pages = 0;
        do {
            $page = self::assertEnvelope($this->call('GET', "/v1/snapshot?after_listing={$after}&limit=3", $key), 200)->data();
            self::assertSame(['listings', 'next_after_listing', 'seq'], array_keys($page));
            foreach ($page['listings'] as $l) {
                $seen[] = $l['variant_id'];
            }
            $after = $page['next_after_listing'];
            $pages++;
        } while ($after !== null && $pages < 5);
        sort($seen);
        self::assertSame(['L1', 'V1', 'V3', 'V9'], $seen);
        self::assertSame(2, $pages);
        self::assertSame((int) self::$db->value('SELECT MAX(seq) FROM stock_change'), $page['seq']);
    }

    public function testBadQueryParameters(): void
    {
        [, $key] = $this->apiSite();
        foreach ([
            '/v1/changes?after=-1', '/v1/changes?after=abc', '/v1/changes?after=1.5', '/v1/changes?limit=0',
            '/v1/changes?limit=5001', '/v1/changes?after[]=1', '/v1/snapshot?limit=5001', '/v1/snapshot?after_listing=x',
            '/v1/availability', '/v1/availability?variant_ids=', '/v1/availability?variant_ids=,,',
            '/v1/availability?variant_ids=' . str_repeat('x', 65), '/v1/availability?variant_ids=a%20b',
            '/v1/availability?variant_ids=' . implode(',', range(1, 1001)), '/v1/purchasing?limit=0', '/v1/purchasing?after_sku=-3',
        ] as $path) {
            self::assertEnvelope($this->call('GET', $path, $key), 400, 'bad_query');
        }
        self::assertEnvelope($this->call('GET', '/v1/availability?variant_ids=' . implode(',', range(1, 1000)), $key), 200);
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }
}
