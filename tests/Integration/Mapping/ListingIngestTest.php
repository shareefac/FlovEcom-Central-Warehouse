<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\CwException;
use CW\Mapping\ListingIngestService;
use CW\Tests\Support\StockTestCase;

/**
 * ListingIngestService, shared by PUT /v1/listings and bin/import_listings.php. The first two tests
 * are the HTTP tests' listing cases (ApiResourcesTest, ApiReservationsTest; slot api only) run in
 * process, so the move from CW\ListingProfiles is checked in every slot: same answers, same rows.
 */
final class ListingIngestTest extends StockTestCase
{
    private ListingIngestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingIngestService(self::$db);
    }

    public function testPushWritesProfilesOnlyAndCreatesUnmappedListings(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $mapped = $this->listing($site, 'V1', $sku, 2);
        $push = [
            ['variant_id' => 'V1', 'product_title' => 'Elux Legend 3500', 'variant_title' => 'Blue Razz', 'brand' => 'Elux',
                'attributes' => ['strength' => '20mg', 'colour' => 'blue'], 'barcodes' => ['5056', '5055', '5056'], 'price' => '9.99',
                'perma_link' => 'https://example.test/elux', 'units_30d' => 12, 'units_365d' => 140],
            ['variant_id' => 777, 'product_title' => str_repeat('é', 600), 'price' => 4],
        ];
        $r = $this->svc->push($site, $push);
        self::assertSame([2, 2, 0, 0], [$r['received'], $r['created'], $r['updated'], $r['unchanged']]);
        $p = self::$db->one('SELECT * FROM listing_profile WHERE listing_id = ?', [$mapped]);
        self::assertSame(['Elux Legend 3500', 'Elux', '9.99', 12], [$p['product_title'], $p['brand'], $p['price'], $p['units_30d']]);
        self::assertSame(['5055', '5056'], json_decode((string) $p['barcodes'], true));
        $link = self::$db->one('SELECT sku_id, status, units_per_item FROM channel_listing WHERE id = ?', [$mapped]);
        self::assertSame([$sku, 'mapped', 2], [$link['sku_id'], $link['status'], $link['units_per_item']]);
        $new = self::$db->one("SELECT l.status, l.sku_id, CHAR_LENGTH(p.product_title) AS len FROM channel_listing l JOIN listing_profile p ON p.listing_id = l.id WHERE l.external_variant_id = '777'");
        self::assertSame(['unmapped', null, 512], [$new['status'], $new['sku_id'], $new['len']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id IS NOT NULL'), 'no link change, no feed row');
        self::assertSame(1, json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'listing.profiles'"), true)['new_listings']);

        $push[0]['barcodes'] = ['5055', '5056'];
        $r = $this->svc->push($site, $push);
        self::assertSame([0, 0, 2], [$r['created'], $r['updated'], $r['unchanged']]);
        $push[0]['price'] = 8.5;
        $push[1]['product_title'] = 'Renamed';
        $r = $this->svc->push($site, $push);
        self::assertSame(['777' => true, 'V1' => false], array_column($r['listings'], 'identity_changed', 'variant_id'));
        self::assertSame(['777' => 'updated', 'V1' => 'updated'], array_column($r['listings'], 'result', 'variant_id'));
        self::assertSame('8.50', self::$db->value('SELECT price FROM listing_profile WHERE listing_id = ?', [$mapped]));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'listing.profiles'"));

        foreach ([
            [], ['a' => 1], null,
            [['product_title' => 'no id']],
            [['variant_id' => 'A'], ['variant_id' => 'A']],
            [['variant_id' => 'A', 'price' => -1]],
            [['variant_id' => 'A', 'price' => 'cheap']],
            [['variant_id' => 'A', 'barcodes' => 'x']],
            [['variant_id' => 'A', 'attributes' => ['x', 'y']]],
            [['variant_id' => 'A', 'units_30d' => -1]],
            [['variant_id' => 'A', 'brand' => ['x']]],
        ] as $bad) {
            self::assertSame([400, 'bad_listings'], self::error(fn () => $this->svc->push($site, $bad)), json_encode($bad));
        }
        $many = array_map(static fn (int $i): array => ['variant_id' => 'M' . $i], range(1, 1001));
        self::assertSame([413, 'bad_listings'], self::error(fn () => $this->svc->push($site, $many)));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM listing_profile'));
        self::assertSame([403, 'channel_required'], self::error(fn () => $this->svc->push(Caller::system('import_listings'), [['variant_id' => 'Z']])));
    }

    public function testVariantIdsFollowTheDatabaseCollation(): void
    {
        $site = $this->site();
        $listing = $this->listing($site, 'abc-1', $this->item('strict', 5));
        $r = $this->svc->push($site, [['variant_id' => 'ABC-1', 'product_title' => 'T']]);
        self::assertSame($listing, $r['listings'][0]['listing_id']);
        self::assertSame([400, 'bad_listings'], self::error(fn () => $this->svc->push($site, [['variant_id' => 'x-9'], ['variant_id' => 'X-9']])));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing'));
    }

    public function testAnIdentityChangeClearsTheStoredFeaturesAndAPriceChangeKeepsThem(): void
    {
        $site = $this->site();
        $this->svc->push($site, [['variant_id' => 'V1', 'product_title' => 'Elux 20mg', 'price' => 5]]);
        $id = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'V1'");
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('strength_mg', 20), features_version = 'n2.0' WHERE listing_id = ?", [$id]);
        $this->svc->push($site, [['variant_id' => 'V1', 'product_title' => 'Elux 20mg', 'price' => 6]]);
        self::assertSame('n2.0', self::$db->value('SELECT features_version FROM listing_profile WHERE listing_id = ?', [$id]));
        $r = $this->svc->push($site, [['variant_id' => 'V1', 'product_title' => 'Elux 10mg', 'price' => 6]]);
        self::assertTrue($r['listings'][0]['identity_changed']);
        self::assertSame([null, null], array_values(self::$db->one('SELECT features, features_version FROM listing_profile WHERE listing_id = ?', [$id])));
    }

    public function testExportLinesBecomePushesAndASystemCallerNamesTheChannel(): void
    {
        $site = $this->site('vpg', 'off');
        $row = ['site' => 'vapeandgo', 'variant_id' => 3, 'product_id' => 3, 'product_title' => 'Wotofo Coils', 'variant_title' => 'Wotofo Coils 0.62',
            'brand' => 'Wotofo', 'variant_status' => 'Bin', 'is_landing' => 0, 'price' => 3.79, 'permalink' => 'wotofo/', 'barcodes' => [6937291305184, '5055'],
            'attributes' => [['attr_id' => 3, 'name' => 'Nicotine Strength', 'value' => '10mg', 'is_variable' => 0],
                ['attr_id' => 3, 'name' => 'Nicotine Strength', 'value' => '20mg', 'is_variable' => 1]],
            'units_30d' => 0, 'units_365d' => 4, 'stock' => 19];
        $l = ListingIngestService::fromExport($row);
        self::assertSame(['variant_id' => 3, 'product_title' => 'Wotofo Coils', 'variant_title' => 'Wotofo Coils 0.62', 'brand' => 'Wotofo',
            'attributes' => ['items' => $row['attributes']], 'barcodes' => [6937291305184, '5055'], 'price' => 3.79, 'perma_link' => 'wotofo/',
            'units_30d' => 0, 'units_365d' => 4], $l);
        self::assertNull(ListingIngestService::check($l));
        self::assertSame('bad_listings', ListingIngestService::check(['variant_id' => 'a b'])['error'] ?? null);
        self::assertNull(ListingIngestService::fromExport(['variant_id' => 1, 'attributes' => []])['attributes']);

        $r = $this->svc->ingest(Caller::system('import_listings'), (int) $site->channelId, [$l]);
        self::assertSame(1, $r['created']);
        $p = self::$db->one("SELECT p.barcodes, p.attributes, p.perma_link FROM listing_profile p JOIN channel_listing l ON l.id = p.listing_id WHERE l.external_variant_id = '3'");
        self::assertSame(['5055', '6937291305184'], json_decode((string) $p['barcodes'], true));
        self::assertSame(['10mg', '20mg'], array_column(json_decode((string) $p['attributes'], true)['items'], 'value'), 'the list order is kept');
        self::assertSame('wotofo/', $p['perma_link']);
        self::assertSame('system:import_listings', self::$db->value("SELECT actor FROM audit_log WHERE action = 'listing.profiles'"));
    }

    /** @return array{0: int, 1: string} */
    private static function error(callable $fn): array
    {
        try {
            $fn();
        } catch (CwException $e) {
            return [$e->httpStatus, $e->errorCode];
        }
        self::fail('expected a CwException');
    }
}
