<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\CwException;
use CW\Mapping\ListingIngestService;
use CW\Mapping\SiteState;
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

        // The website state (0028, M66) is attached after both hashes are made: the same push hashes the same with and without
        // `website`, whatever the state says, on a profile that is there and on one that is new.
        $website = ['variant_status' => 'Published', 'product_status' => 'Published', 'product_type' => 'variable', 'product_id' => 4410, 'is_landing' => 0, 'is_default' => 1,
            'product_link' => 'bar-juice-5000/'];
        self::assertSame(1, $this->svc->push($site, [$push + ['website' => $website]])['unchanged'], 'a state does not make a push look changed');
        self::assertSame(1, $this->svc->push($site, [$push + ['website' => ['variant_status' => 'Bin'] + $website]])['unchanged'], 'nor does a state that changed');
        $hashes = static fn (int $channel): array => array_values((array) self::$db->one('SELECT lp.identity_hash, lp.profile_hash FROM listing_profile lp '
            . 'JOIN channel_listing cl ON cl.id = lp.listing_id WHERE cl.channel_id = ?', [$channel]));
        self::assertSame([$p['identity_hash'], $p['profile_hash']], $hashes((int) $site->channelId));
        self::assertSame('Bin', self::$db->value('SELECT site_variant_status FROM listing_profile'), 'the state itself was written');
        $other = $this->site('vapebig', 'off');
        self::assertSame(1, $this->svc->push($other, [$push + ['website' => $website]])['created']);
        self::assertSame([$p['identity_hash'], $p['profile_hash']], $hashes((int) $other->channelId), 'a profile created with a state hashes as one created without');

        // An export line still becomes the same ten fields: its state is not one of them (the import adds it as `website`,
        // from SiteState::fromExport()).
        self::assertSame(['variant_id', 'product_title', 'variant_title', 'brand', 'attributes', 'barcodes', 'price', 'perma_link', 'units_30d', 'units_365d'],
            array_keys(ListingIngestService::fromExport(['variant_id' => 3, 'variant_status' => 'Published', 'product_status' => 'Published', 'is_landing' => 0,
                'product_type' => 'variable', 'permalink' => 'x/', 'product_permalink' => 'p/'])), 'fromExport() is as it was: no website state in it');
    }

    /** The eight state columns of one variant's profile, in the order of SiteState::COLUMNS, then the date. @return list<mixed> */
    private static function state(string $variant): array
    {
        return array_values((array) self::$db->one('SELECT lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, '
            . 'lp.site_is_default, lp.site_product_link, lp.site_state_at FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id WHERE cl.external_variant_id = ?', [$variant]));
    }

    /** The last listing.profiles audit row's detail. @return array<string, mixed> */
    private static function lastAudit(): array
    {
        return json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'listing.profiles' ORDER BY id DESC LIMIT 1"), true);
    }

    /**
     * The website state (0028, M66) is written whenever the caller supplies it, in each of the three branches of the intake (a
     * profile created, changed, unchanged), and its date is moved on even when the state says the same. A supplied state is whole:
     * what it leaves out is not known from then on. A push that supplies none leaves the stored one, with its date. An import
     * dates the state with the export's time, and a state older than the stored one is not written over it.
     */
    public function testTheWebsiteStateIsWrittenInAllThreeBranchesAndItsDateIsRefreshed(): void
    {
        $site = $this->site('electrofag', 'off');
        $website = ['variant_status' => 'Published', 'product_status' => 'Published', 'product_type' => 'variable', 'product_id' => 4410, 'is_landing' => false, 'is_default' => true,
            'product_link' => 'bar-juice-5000/'];
        $v = ['variant_id' => 'A1', 'product_title' => 'Bar Juice 5000 Blue Razz', 'variant_title' => '10mg', 'price' => 4.99, 'perma_link' => 'bar-juice-5000/blue-razz-10mg'];
        $stored = ['Published', 'Published', 'variable', '4410', 0, 1, 'bar-juice-5000/'];
        $age = static function (string $to = '2026-01-01 00:00:00.000000'): void {
            self::$db->exec('UPDATE listing_profile SET site_state_at = ?', [$to]);
        };

        // Created: the seven values, and the date of the push.
        $t0 = \CW\Clock::db(\CW\Clock::now());
        $r = $this->svc->push($site, [$v + ['website' => $website]]);
        self::assertSame(['received', 'created', 'updated', 'unchanged', 'listings'], array_keys($r), 'what a site is answered is what it was');
        self::assertSame(1, $r['created']);
        self::assertSame($stored, array_slice(self::state('A1'), 0, 7));
        self::assertGreaterThanOrEqual($t0, self::state('A1')[7]);
        self::assertSame([1, 0], [self::lastAudit()['website'], self::lastAudit()['website_ignored']]);

        // Unchanged: the same push again. Nothing of the profile moves but pushed_at; the state's date is moved on all the same.
        $age();
        $t1 = \CW\Clock::db(\CW\Clock::now());
        self::assertSame(1, $this->svc->push($site, [$v + ['website' => $website]])['unchanged']);
        self::assertSame($stored, array_slice(self::state('A1'), 0, 7));
        self::assertGreaterThanOrEqual($t1, self::state('A1')[7], 'the same state, seen again now: its date is refreshed');
        // ... and with a state that says something else: the unchanged branch writes it.
        $age();
        self::assertSame(1, $this->svc->push($site, [$v + ['website' => ['variant_status' => 'Draft'] + $website]])['unchanged']);
        self::assertSame(['Draft', 'Published'], array_slice(self::state('A1'), 0, 2));
        self::assertGreaterThanOrEqual($t1, self::state('A1')[7]);

        // Changed (a price): the state is written in that branch too.
        $age();
        $v['price'] = 5.49;
        self::assertSame(1, $this->svc->push($site, [$v + ['website' => ['variant_status' => 'Bin', 'product_id' => '4411'] + $website]])['updated']);
        self::assertSame(['Bin', 'Published', 'variable', '4411', 0, 1, 'bar-juice-5000/'], array_slice(self::state('A1'), 0, 7));
        self::assertGreaterThanOrEqual($t1, self::state('A1')[7]);

        // A supplied state is whole: what it leaves out is not known from now on (never kept under the new date).
        self::assertSame(1, $this->svc->push($site, [$v + ['website' => ['variant_status' => 'Published']]])['unchanged']);
        self::assertSame(['Published', null, null, null, null, null, null], array_slice(self::state('A1'), 0, 7));

        // No `website` in the push, or nothing usable in it: the stored state stays, with its date (it grows old by itself).
        $age('2026-02-02 00:00:00.000000');
        foreach ([[], ['website' => null], ['website' => []], ['website' => ['variant_status' => null, 'product_status' => '']]] as $none) {
            $this->svc->push($site, [$v + $none]);
            self::assertSame(['Published', null, null, null, null, null, null, '2026-02-02 00:00:00.000000'], self::state('A1'), json_encode($none));
            self::assertSame([0, 0], [self::lastAudit()['website'], self::lastAudit()['website_ignored']]);
        }
        $v['price'] = 5.99;
        self::assertSame(1, $this->svc->push($site, [$v])['updated']);
        self::assertSame(['Published', null, null, null, null, null, null, '2026-02-02 00:00:00.000000'], self::state('A1'), 'also when the profile itself changed');

        // An import dates the state itself (the export's time), for a line that is new and for one that is there.
        $line = ['site' => 'electrofag', 'variant_id' => 'A2', 'product_id' => 77, 'product_title' => 'Elux Legend 3500', 'variant_title' => 'Cola', 'product_type' => 'simple',
            'variant_status' => 'Published', 'product_status' => 'Published', 'is_landing' => 0, 'is_default' => 1, 'permalink' => 'elux/cola', 'product_permalink' => 'elux/',
            'price' => 4.5, 'units_30d' => 0, 'units_365d' => 2];
        $listing = ListingIngestService::fromExport($line) + [SiteState::KEY => SiteState::fromExport($line)];
        $exported = \CW\Clock::fromDb('2026-09-26 09:41:52.000000');
        $system = Caller::system('import_listings');
        self::assertSame(1, $this->svc->ingest($system, (int) $site->channelId, [$listing], $exported)['created']);
        self::assertSame(['Published', 'Published', 'simple', '77', 0, 1, 'elux/', '2026-09-26 09:41:52.000000'], self::state('A2'));
        self::assertSame(1, $this->svc->ingest($system, (int) $site->channelId, [$listing], $exported->modify('+1 day'))['unchanged']);
        self::assertSame('2026-09-27 09:41:52.000000', self::state('A2')[7]);
        // A state older than the stored one is not written over it (an old file loaded after a newer one), and is counted.
        $older = ['variant_status' => 'Bin'] + SiteState::fromExport($line);
        self::assertSame(1, $this->svc->ingest($system, (int) $site->channelId, [[SiteState::KEY => $older] + $listing], $exported)['unchanged']);
        self::assertSame(['Published', '2026-09-27 09:41:52.000000'], [self::state('A2')[0], self::state('A2')[7]]);
        self::assertSame([0, 1], [self::lastAudit()['website'], self::lastAudit()['website_older']]);
        // (the same date again is not older: a file loaded twice writes the same twice)
        self::assertSame(1, $this->svc->ingest($system, (int) $site->channelId, [[SiteState::KEY => $older] + $listing], $exported->modify('+1 day'))['unchanged']);
        self::assertSame('Bin', self::state('A2')[0]);
        // Nothing but the profile was written: no suggestion, no decision, no link.
        self::assertSame([0, 0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM match_proposal'), (int) self::$db->value('SELECT COUNT(*) FROM match_decision'),
            (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE status <> 'unmapped'")]);
    }

    /**
     * A `website` value the intake cannot read never costs the listing its push: a wrong type is ignored and counted in the
     * audit row, and what could be read is stored. Never a 400.
     */
    public function testAWebsiteValueOfTheWrongTypeIsIgnoredAndCountedNeverRefused(): void
    {
        $site = $this->site('electrofag', 'off');
        // Not an object at all: the listing is taken, no state is stored, one value is counted.
        foreach (['Published', 7, ['a', 'b'], true] as $n => $bad) {
            $r = $this->svc->push($site, [['variant_id' => 'B' . $n, 'product_title' => 'T', 'website' => $bad]]);
            self::assertSame(1, $r['created'], json_encode($bad));
            self::assertSame([null, null, null, null, null, null, null, null], self::state('B' . $n));
            self::assertSame([0, 1], [self::lastAudit()['website'], self::lastAudit()['website_ignored']]);
            self::assertNull(ListingIngestService::check(['variant_id' => 'B' . $n, 'website' => $bad]), 'the checks of a listing do not refuse it either');
        }
        // An object with some values of the wrong type: those are not known, the rest is stored, each wrong one is counted.
        $r = $this->svc->push($site, [
            ['variant_id' => 'C1', 'product_title' => 'T', 'website' => ['variant_status' => 5, 'product_status' => 'Published', 'is_landing' => 'no', 'is_default' => 1, 'product_id' => [4]]],
            ['variant_id' => 'C2', 'product_title' => 'T', 'website' => ['variant_status' => 'Draft', 'product_type' => str_repeat('x', 40)]],
            ['variant_id' => 'C3', 'product_title' => 'T', 'website' => ['variant_status' => 9]],
        ]);
        self::assertSame([3, 3], [$r['received'], $r['created']]);
        self::assertSame([null, 'Published', null, null, null, 1, null], array_slice(self::state('C1'), 0, 7));
        self::assertNotNull(self::state('C1')[7]);
        self::assertSame(['Draft', null, null], array_slice(self::state('C2'), 0, 3));
        self::assertSame([null, null], [self::state('C3')[0], self::state('C3')[7]], 'nothing usable: no state, and no date that would call it one');
        self::assertSame([2, 5], [self::lastAudit()['website'], self::lastAudit()['website_ignored']], 'two states written; three, one and one values ignored');
        // The checks a listing always had still refuse what they refused.
        self::assertSame([400, 'bad_listings'], self::error(fn () => $this->svc->push($site, [['variant_id' => 'D1', 'price' => 'cheap', 'website' => ['variant_status' => 'Published']]])));
    }

    /**
     * The queue rules of the state (M66), added to the three of the mark (created, identity, address): a state that changed FROM
     * A KNOWN VALUE marks the variant "to check", whatever its mark was; a state that was not known before marks it only when the
     * profile already carries a mark. So the first load of the state marks none of the variants that were there before the check.
     */
    public function testAStateThatChangedFromAKnownValueQueuesTheVariantAndAFirstStateOnlyAMarkedOne(): void
    {
        $site = $this->site('electrofag', 'off');
        $channel = (int) $site->channelId;
        $website = ['variant_status' => 'Published', 'product_status' => 'Published', 'product_type' => 'simple', 'product_id' => 77, 'is_landing' => 0, 'is_default' => 1,
            'product_link' => 'elux/'];
        $old = ['variant_id' => 'OLD', 'product_title' => 'Was there before the check', 'price' => 4.99];
        $mark = static fn (string $variant): array => array_values((array) self::$db->one('SELECT lp.arrival_state, lp.arrival_seq, lp.arrival_channel_id FROM listing_profile lp '
            . 'JOIN channel_listing cl ON cl.id = lp.listing_id WHERE cl.external_variant_id = ?', [$variant]));
        $looked = static function (): void {
            self::$db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = UTC_TIMESTAMP(6), arrival_outcome = 'no_clue' WHERE arrival_state IS NOT NULL");
        };

        // A variant that was there before the check (no mark) gets its first state: not marked. The same state again: not marked.
        $this->svc->push($site, [$old]);
        self::$db->exec('UPDATE listing_profile SET arrival_state = NULL, arrival_seq = 0, arrival_channel_id = NULL');
        self::assertSame(1, $this->svc->push($site, [$old + ['website' => $website]])['unchanged']);
        self::assertSame([null, 0, null], $mark('OLD'), 'the first state of an unmarked variant queues nothing');
        self::assertSame(0, self::lastAudit()['arrival_queued']);
        $this->svc->push($site, [$old + ['website' => $website]]);
        self::assertSame([null, 0, null], $mark('OLD'));
        // Its state changes from a known value (it went to the bin): marked, whatever its mark was. In the unchanged branch.
        self::assertSame(1, $this->svc->push($site, [$old + ['website' => ['variant_status' => 'Bin'] + $website]])['unchanged']);
        self::assertSame(['queued', 1, $channel], $mark('OLD'));
        self::assertSame(1, self::lastAudit()['arrival_queued']);
        // Looked at; the same state again: nothing. Only the product's address changed: nothing reads it, so nothing.
        $looked();
        $this->svc->push($site, [$old + ['website' => ['variant_status' => 'Bin'] + $website]]);
        $this->svc->push($site, [$old + ['website' => ['variant_status' => 'Bin', 'product_link' => 'elux-legend/'] + $website]]);
        self::assertSame(['done', 1, $channel], $mark('OLD'));
        self::assertSame('elux-legend/', self::state('OLD')[6]);
        // Each value the readers use counts, also one that is known no longer; and in the changed branch (a price moved too).
        $n = 1;
        $now = ['variant_status' => 'Bin', 'product_link' => 'elux-legend/'] + $website;
        foreach ([['product_status' => 'Draft'], ['is_landing' => 1], ['is_default' => 0], ['product_type' => 'variable'], ['product_id' => 78], ['variant_status' => null]] as $change) {
            $looked();
            $now = $change + $now;
            $old['price'] = 4.99 + $n / 100;
            self::assertSame(1, $this->svc->push($site, [$old + ['website' => $now]])['updated']);
            self::assertSame(['queued', ++$n, $channel], $mark('OLD'), json_encode($change));
        }
        // A state that is no longer supplied is no change: the stored one stays, and nothing is marked.
        $looked();
        $this->svc->push($site, [$old]);
        self::assertSame(['done', $n, $channel], $mark('OLD'));

        // A variant that arrived after the check was installed carries a mark. Its first state marks it again (the check has
        // looked at it without knowing whether it is on sale).
        $new = ['variant_id' => 'NEW', 'product_title' => 'Arrived by itself'];
        $this->svc->push($site, [$new]);
        self::assertSame(['queued', 1, $channel], $mark('NEW'));
        $looked();
        $this->svc->push($site, [$new + ['website' => ['variant_status' => 7]]]);
        self::assertSame(['done', 1, $channel], $mark('NEW'), 'a state with nothing usable in it is no state');
        self::assertSame(1, $this->svc->push($site, [$new + ['website' => $website]])['unchanged']);
        self::assertSame(['queued', 2, $channel], $mark('NEW'), 'the first state of a marked variant queues it');
        // A variant created with its state is marked once, as every created one.
        $this->svc->push($site, [['variant_id' => 'BORN', 'product_title' => 'Arrived with its state', 'website' => $website]]);
        self::assertSame(['queued', 1, $channel], $mark('BORN'));
        // One batch: the unmarked first state, the changed state and the unchanged one are told apart.
        $looked();
        self::$db->exec("UPDATE listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id SET lp.arrival_state = NULL, lp.arrival_seq = 0, lp.arrival_channel_id = NULL, "
            . "lp.site_variant_status = NULL, lp.site_product_status = NULL, lp.site_product_type = NULL, lp.site_product_id = NULL, lp.site_is_landing = NULL, lp.site_is_default = NULL, "
            . "lp.site_product_link = NULL, lp.site_state_at = NULL WHERE cl.external_variant_id = 'OLD'");
        $this->svc->push($site, [$old + ['website' => $website], $new + ['website' => ['variant_status' => 'Draft'] + $website],
            ['variant_id' => 'BORN', 'product_title' => 'Arrived with its state', 'website' => $website]]);
        self::assertSame([[null, 0, null], ['queued', 3, $channel], ['done', 1, $channel]], [$mark('OLD'), $mark('NEW'), $mark('BORN')]);
        self::assertSame([1, 3], [self::lastAudit()['arrival_queued'], self::lastAudit()['website']]);
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
