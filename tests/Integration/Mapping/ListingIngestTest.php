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

    /**
     * The check on arrival's mark (0027, M64): a profile is marked "to check" when it is created, when its identity changed and
     * when its web address changed; a push that changes neither (nothing at all, a price, the units sold) marks nothing. The mark
     * names the store, and its number moves on with every marking, so a check that read an older number writes nothing.
     */
    public function testAProfileIsMarkedToCheckWhenItIsCreatedOrItsIdentityOrItsAddressChanged(): void
    {
        $site = $this->site('electrofag', 'off');
        $channel = (int) $site->channelId;
        $v = ['variant_id' => 'A1', 'product_title' => 'Bar Juice 5000 Blue Razz', 'variant_title' => '10mg', 'barcodes' => ['5060769492206'], 'price' => 4.99,
            'perma_link' => 'bar-juice-5000-blue-razz/10mg', 'units_30d' => 1, 'units_365d' => 9];
        $mark = fn (): array => array_values((array) self::$db->one('SELECT lp.arrival_state, lp.arrival_seq, lp.arrival_channel_id, lp.arrival_checked_at, lp.arrival_outcome '
            . "FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id WHERE cl.external_variant_id = 'A1'"));
        $looked = static function (): void {
            self::$db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = '2026-10-12 08:00:00.000000', arrival_outcome = 'no_clue'");
        };
        $detail = static fn (): array => json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'listing.profiles' ORDER BY id DESC LIMIT 1"), true);

        // Created: to check, number 1, its store.
        $this->svc->push($site, [$v]);
        self::assertSame(['queued', 1, $channel, null, null], $mark());
        self::assertSame(1, $detail()['arrival_queued']);

        // The same push again, and one that changes only the price and the units sold: no mark (what the check wrote stays).
        $looked();
        self::assertSame(1, $this->svc->push($site, [$v])['unchanged']);
        self::assertSame(['done', 1, $channel, '2026-10-12 08:00:00.000000', 'no_clue'], $mark());
        self::assertSame(0, $detail()['arrival_queued']);
        $r = $this->svc->push($site, [['price' => 5.49, 'units_30d' => 2, 'units_365d' => 10] + $v]);
        self::assertSame([1, false], [$r['updated'], $r['listings'][0]['identity_changed']]);
        self::assertSame(['done', 1, $channel, '2026-10-12 08:00:00.000000', 'no_clue'], $mark(), 'a price is nothing the check reads');
        self::assertSame(0, $detail()['arrival_queued']);
        $v = ['price' => 5.49, 'units_30d' => 2, 'units_365d' => 10] + $v;

        // The identity changed (a barcode came): to check again, the number moves on.
        $v['barcodes'] = ['5060769492206', '5060769492213'];
        self::assertTrue($this->svc->push($site, [$v])['listings'][0]['identity_changed']);
        self::assertSame(['queued', 2, $channel], array_slice($mark(), 0, 3));
        self::assertSame(1, $detail()['arrival_queued']);

        // The address changed and nothing else: the identity did not, the mark did.
        $looked();
        $v['perma_link'] = 'bar-juice-5000-blue-razz-nic-salt/10mg';
        $r = $this->svc->push($site, [$v]);
        self::assertSame([1, false], [$r['updated'], $r['listings'][0]['identity_changed']]);
        self::assertSame(['queued', 3, $channel], array_slice($mark(), 0, 3));
        // ... taken away altogether: a change of the address too.
        $looked();
        unset($v['perma_link']);
        $this->svc->push($site, [$v]);
        self::assertSame(['queued', 4, $channel], array_slice($mark(), 0, 3));
        $looked();
        self::assertSame(1, $this->svc->push($site, [$v])['unchanged']);
        self::assertSame(['done', 4], array_slice($mark(), 0, 2), 'no address before and none now is no change');

        // A profile that was there before the check (no mark at all) is marked the same way when it changes, and not before.
        self::$db->exec('UPDATE listing_profile SET arrival_state = NULL, arrival_seq = 0, arrival_channel_id = NULL, arrival_checked_at = NULL, arrival_outcome = NULL');
        $this->svc->push($site, [$v]);
        self::assertSame([null, 0, null], array_slice($mark(), 0, 3), 'an unchanged push of an old profile does not queue it: the timer never drains what was there');
        $v['product_title'] = 'Bar Juice 5000 Blue Razz Ice';
        $this->svc->push($site, [$v]);
        self::assertSame(['queued', 1, $channel], array_slice($mark(), 0, 3));

        // A batch: only the profiles that need it are marked (one new, one unchanged).
        $this->svc->push($site, [$v, ['variant_id' => 'A2', 'product_title' => 'Another']]);
        self::assertSame(1, $detail()['arrival_queued']);
        self::assertSame(['A1' => 1, 'A2' => 1], array_map('intval', array_column(self::$db->all('SELECT cl.external_variant_id AS v, lp.arrival_seq FROM listing_profile lp '
            . 'JOIN channel_listing cl ON cl.id = lp.listing_id ORDER BY cl.id'), 'arrival_seq', 'v')));
        // No suggestion is made by the intake, and no link is touched.
        self::assertSame([0, 0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM match_proposal'), (int) self::$db->value('SELECT COUNT(*) FROM match_run'),
            (int) self::$db->value('SELECT COUNT(*) FROM match_decision')]);
    }

    /**
     * Neither hash reads the mark or anything else the check on arrival added: a fixed push hashes to the values main's own code
     * gave before 0027 (commit 6faa6f7; `profile()` run on this push), and both are still what their definitions say: the identity
     * is the five identity fields, the profile every field of the push. And an export line still becomes the same ten fields.
     */
    public function testBothHashesAndTheExportShapeAreWhatTheyWereBeforeTheCheckOnArrival(): void
    {
        $site = $this->site('electrofag', 'off');
        $push = ['variant_id' => 'A1', 'product_title' => ' Bar Juice 5000 Blue Razz ', 'variant_title' => '10mg', 'brand' => 'Bar Juice 5000',
            'attributes' => ['items' => [['name' => 'Strength', 'value' => '10mg', 'attr_id' => 3, 'is_variable' => 1]]], 'barcodes' => ['5060769492206', 5055],
            'price' => '4.99', 'perma_link' => 'bar-juice-5000-blue-razz/10mg/', 'units_30d' => 3, 'units_365d' => 40];
        $this->svc->push($site, [$push]);
        $p = self::$db->one('SELECT identity_hash, profile_hash, product_title, variant_title, brand, CAST(attributes AS CHAR) AS attributes, CAST(barcodes AS CHAR) AS barcodes, '
            . 'price, perma_link, units_30d, units_365d FROM listing_profile');
        self::assertSame(['64038423fd53b121604acc4d153b4348af14622e900d284c3aecc5f85f15b572', 'c7a6fd65b42e28bd0924af2a47d32cb2a8d534e4d4fe5db1d34f80763dd8b35a'],
            [$p['identity_hash'], $p['profile_hash']], 'as main computed them before the check on arrival');
        $identity = ['product_title' => 'Bar Juice 5000 Blue Razz', 'variant_title' => '10mg', 'brand' => 'Bar Juice 5000',
            'attributes' => \CW\Idempotency::canonical($push['attributes']), 'barcodes' => ['5055', '5060769492206']];
        self::assertSame(hash('sha256', \CW\Idempotency::canonicalJson($identity)), $p['identity_hash']);
        self::assertSame(hash('sha256', \CW\Idempotency::canonicalJson(['variant_id' => 'A1'] + $identity + ['price' => '4.99', 'perma_link' => 'bar-juice-5000-blue-razz/10mg/',
            'units_30d' => 3, 'units_365d' => 40])), $p['profile_hash']);
        // The mark moves, the hashes do not: the check's writes never make a push look changed.
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_seq = 7, arrival_checked_at = UTC_TIMESTAMP(6), arrival_outcome = 'arrival_barcode'");
        self::assertSame(1, $this->svc->push($site, [$push])['unchanged']);
        self::assertSame(['done', 7], array_values((array) self::$db->one('SELECT arrival_state, arrival_seq FROM listing_profile')));

        self::assertSame(['variant_id', 'product_title', 'variant_title', 'brand', 'attributes', 'barcodes', 'price', 'perma_link', 'units_30d', 'units_365d'],
            array_keys(ListingIngestService::fromExport(['variant_id' => 3, 'variant_status' => 'Published', 'product_status' => 'Published', 'is_landing' => 0,
                'product_type' => 'variable', 'permalink' => 'x/'])), 'an export line carries no website state into a push yet (build 2)');
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
