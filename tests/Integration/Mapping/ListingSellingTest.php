<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\ListingIngestService;
use CW\Tests\Support\StockTestCase;

/**
 * The website's own selling mode on a pushed listing (`selling`, T-556 step 2 option A; migration 0068; CW\Mapping\SiteSelling): stored
 * per website product in listing_selling, in the intake's transaction, whatever branch the profile took; read leniently (never a
 * refusal); outside both hashes (a selling change alone leaves a profile "unchanged", marks nothing to check, moves no map_version); a
 * push without it (an older connector) leaves the stored row; an older state never overwrites a newer one; the site's answer is what
 * it always was, the counts are in the audit row. The website state (`website`) is not touched by it.
 */
final class ListingSellingTest extends StockTestCase
{
    private ListingIngestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingIngestService(self::$db);
    }

    /** @return array<string, mixed>|null the listing_selling row of a variant */
    private function stored(string $variant): ?array
    {
        $r = self::$db->one('SELECT s.stock_mode, s.allow_backorders, s.low_stock_threshold, s.as_of, s.changed_at FROM listing_selling s '
            . 'JOIN channel_listing l ON l.id = s.listing_id WHERE l.external_variant_id = ?', [$variant]);
        return $r === null ? null : (array) $r;
    }

    /** @return array<string, mixed> the last listing.profiles audit row's detail */
    private static function detail(): array
    {
        return json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'listing.profiles' ORDER BY id DESC LIMIT 1"), true);
    }

    public function testThePushStoresTheWebsitesOwnModeOutsideTheProfile(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 5);
        $listing = $this->listing($site, 'V1', $sku);
        $v = ['variant_id' => 'V1', 'product_title' => 'Pod Salt 10ml', 'variant_title' => 'Mango', 'price' => 3.99,
            'website' => ['variant_status' => 'Published', 'product_status' => 'Published']];

        // An older connector: no `selling`, nothing stored (the page says "not known yet").
        $r = $this->svc->push($site, [$v]);
        self::assertSame(1, $r['created']);
        self::assertNull($this->stored('V1'));
        self::assertSame([0, 0], [self::detail()['selling'], self::detail()['selling_ignored']]);
        $hash = (string) self::$db->value('SELECT profile_hash FROM listing_profile WHERE listing_id = ?', [$listing]);
        $mapVersion = (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listing]);
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = '2026-11-16 08:00:00.000000', arrival_outcome = 'no_clue' WHERE listing_id = ?",
            [$listing]);

        // 0.4.13: with it. Stored whole, as of the push; the profile unchanged (outside both hashes), nothing marked, no map_version moved.
        $r = $this->svc->push($site, [['selling' => ['stock_mode' => 'From-Warehouse', 'allow_backorders' => 0, 'low_stock_threshold' => 3]] + $v]);
        self::assertSame([0, 0, 1], [$r['created'], $r['updated'], $r['unchanged']]);
        self::assertSame(['received', 'created', 'updated', 'unchanged', 'listings'], array_keys($r), 'the site\'s answer is what it always was');
        $s1 = $this->stored('V1');
        self::assertSame(['From-Warehouse', 0, 3], [$s1['stock_mode'], $s1['allow_backorders'], $s1['low_stock_threshold']]);
        self::assertSame($s1['as_of'], $s1['changed_at']);
        self::assertSame([$hash, $mapVersion, 'done'], [(string) self::$db->value('SELECT profile_hash FROM listing_profile WHERE listing_id = ?', [$listing]),
            (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listing]),
            self::$db->value('SELECT arrival_state FROM listing_profile WHERE listing_id = ?', [$listing])]);
        self::assertSame([1, 0], [self::detail()['selling'], self::detail()['selling_ignored']]);

        // The same again: as of moves on, "since" stays.
        usleep(2000);
        $this->svc->push($site, [['selling' => ['stock_mode' => 'From-Warehouse', 'allow_backorders' => false, 'low_stock_threshold' => '3']] + $v]);
        $s2 = $this->stored('V1');
        self::assertSame(['From-Warehouse', 0, 3, $s1['changed_at']], [$s2['stock_mode'], $s2['allow_backorders'], $s2['low_stock_threshold'], $s2['changed_at']]);
        self::assertGreaterThan($s1['as_of'], $s2['as_of']);

        // Changed on the website (staff set Out-Of-Stock, back-orders on, no warning): stored, "since" moves.
        usleep(2000);
        $this->svc->push($site, [['selling' => ['stock_mode' => 'Out-Of-Stock', 'allow_backorders' => 1, 'low_stock_threshold' => null]] + $v]);
        $s3 = $this->stored('V1');
        self::assertSame(['Out-Of-Stock', 1, null], [$s3['stock_mode'], $s3['allow_backorders'], $s3['low_stock_threshold']]);
        self::assertSame($s3['as_of'], $s3['changed_at']);
        self::assertGreaterThan($s2['as_of'], $s3['as_of']);

        // A push without it again (an older connector after a rollback): the row stays as it is.
        $this->svc->push($site, [$v]);
        self::assertSame($s3, $this->stored('V1'));

        // An older state (an import as of yesterday that carried one) never overwrites a newer one.
        $this->svc->ingest(Caller::system('import_listings'), (int) $site->channelId, [['selling' => ['stock_mode' => 'In-Stock']] + $v],
            new \DateTimeImmutable('-1 day'));
        self::assertSame($s3, $this->stored('V1'));
        self::assertSame('Published', self::$db->value('SELECT site_variant_status FROM listing_profile WHERE listing_id = ?', [$listing]), 'the website state as before');
    }

    public function testItIsReadLenientlyAndNeverCostsAListingItsPush(): void
    {
        $site = $this->site('vapeandgo');
        $base = ['product_title' => 'Lenient liquid'];
        $r = $this->svc->push($site, [
            ['variant_id' => 'A', 'selling' => ['stock_mode' => 42, 'allow_backorders' => 'yes', 'low_stock_threshold' => 1.5]] + $base,
            ['variant_id' => 'B', 'selling' => 'In-Stock'] + $base,
            ['variant_id' => 'C', 'selling' => ['allow_backorders' => 1]] + $base,
            ['variant_id' => 'D', 'selling' => ['stock_mode' => null, 'allow_backorders' => null, 'low_stock_threshold' => -5]] + $base,
            ['variant_id' => 'E', 'selling' => ['stock_mode' => '  In-Stock  ', 'allow_backorders' => '1', 'low_stock_threshold' => '12', 'extra' => 'left alone']] + $base,
            ['variant_id' => 'F', 'selling' => ['stock_mode' => str_repeat('x', 33), 'low_stock_threshold' => 99999999999]] + $base,
            ['variant_id' => 'G', 'selling' => []] + $base,
        ]);
        self::assertSame(7, $r['created'], 'no listing refused');
        $row = fn (string $v): ?array => ($s = $this->stored($v)) === null ? null : [$s['stock_mode'], $s['allow_backorders'], $s['low_stock_threshold']];
        self::assertSame([
            'A' => [null, null, null], 'B' => null, 'C' => null, 'D' => [null, null, -5], 'E' => ['In-Stock', 1, 12], 'F' => [null, null, null], 'G' => null,
        ], ['A' => $row('A'), 'B' => $row('B'), 'C' => $row('C'), 'D' => $row('D'), 'E' => $row('E'), 'F' => $row('F'), 'G' => $row('G')],
            'a wrong value is ignored (then none); no object, or no stock_mode, is no state; an empty one is nothing');
        // ignored: A 3 values, B 1 (no object), C 1 (no stock_mode), F 2 values
        self::assertSame([4, 7], [self::detail()['selling'], self::detail()['selling_ignored']]);
    }
}
