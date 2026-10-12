<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\Mapping\ArrivalCheck;
use CW\Mapping\DecisionService;
use CW\Mapping\ListingIngestService;
use CW\Matching\Gtin;

/**
 * Fixtures for the check on arrival (docs/decisions.md M64, M65), shaped like staging: items made from main-store variants by the
 * seed (each item's origin variant is matched to it and carries its barcode and its web address), and variants that arrive on
 * another store through the listing intake, which marks them "to check". Use in a MappingTestCase; call arrivalSetup() after the
 * stores exist and arrivalTearDown() from tearDown() (the settings are rows the migrations seeded: a test puts them back).
 */
trait ArrivalFixtures
{
    protected ArrivalCheck $arrival;
    protected Caller $arrivalMain;
    protected ?Caller $arrivalLead = null;
    private int $arrivalSeq = 0;
    private const ARRIVAL_DEFAULTS = ['mapping.arrival_stores' => '""', 'mapping.arrival_main_store' => '"vapeandgo"', 'mapping.arrival_bulk_confirm' => 'false',
        'mapping.arrival_max_per_run' => '200', 'mapping.arrival_max_seconds' => '45', 'mapping.arrival_queue_alarm' => '300'];

    /** The check switched on for $stores, with $main as the main store. */
    protected function arrivalSetup(Caller $main, Caller ...$stores): void
    {
        $this->arrivalMain = $main;
        $this->arrivalSetting('mapping.arrival_main_store', json_encode(self::arrivalCode($main)));
        $this->arrivalSetting('mapping.arrival_stores', json_encode(implode(',', array_map(self::arrivalCode(...), $stores))));
        $this->arrival = new ArrivalCheck(self::$db, $this->ds);
    }

    protected function arrivalTearDown(): void
    {
        foreach (self::ARRIVAL_DEFAULTS as $key => $json) {
            $this->arrivalSetting($key, $json);
        }
    }

    /**
     * A setting's value as the Settings page stores it (JSON text). The value only: app_setting is a seed table the tests keep, and
     * who last changed a row is read by other classes (Migration0009Test: every row still `system:migrate`).
     */
    protected function arrivalSetting(string $key, string $json): void
    {
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM app_setting WHERE setting_key = ?', [$key]), $key);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    protected static function arrivalCode(Caller $site): string
    {
        return substr($site->actor, strlen('channel:'));
    }

    /** A barcode that is a real GTIN-13 (a valid check digit, not an in-store number): number $n of the tests. */
    protected static function gtin(int $n): string
    {
        $body = '506076' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        return $body . Gtin::checkDigit($body);
    }

    /**
     * An item made from a main-store variant, as the seed made them: the variant arrives through the intake and a matching lead
     * mints its item, so the variant is the item's origin and is matched to it.
     *
     * @param list<string> $barcodes
     * @return array{sku: int, listing: int, variant: string, code: string}
     */
    protected function mainItem(string $title, string $option = '', array $barcodes = [], ?string $link = null, string $brand = 'Bar Juice 5000'): array
    {
        $variant = (string) (710000 + ++$this->arrivalSeq); // (KeyFixtures numbers its own main-store variants from 700001)
        $lid = $this->arrives($this->arrivalMain, $title, $option, $barcodes, $link, $brand, $variant);
        $this->arrivalLead ??= $this->staffUser('mapping_lead');
        $name = trim($title . ' ' . $option);
        $sku = (int) $this->ds->mintAndLink($this->arrivalLead, $lid, (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$lid]),
            DecisionService::cardFrom([], [], ['name' => $name, 'brand' => $brand]), 'vpg_mint:test')['sku_id'];
        return ['sku' => $sku, 'listing' => $lid, 'variant' => $variant, 'code' => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku])];
    }

    /**
     * A variant arrives on a store through the listing intake (which marks its profile "to check"). @return int its listing id
     *
     * @param list<string> $barcodes
     */
    protected function arrives(Caller $site, string $title, string $option = '', array $barcodes = [], ?string $link = null, string $brand = 'Bar Juice 5000',
        ?string $variant = null): int
    {
        $variant ??= (string) (810000 + ++$this->arrivalSeq);
        $l = ['variant_id' => $variant, 'product_title' => $title, 'brand' => $brand, 'price' => 4.99, 'units_365d' => 7];
        if ($option !== '') {
            $l['variant_title'] = $option;
        }
        if ($barcodes !== []) {
            $l['barcodes'] = $barcodes;
        }
        if ($link !== null) {
            $l['perma_link'] = $link;
        }
        return (int) (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $site->channelId, [$l])['listings'][0]['listing_id'];
    }

    /** The site sends the variant again with something changed (the intake marks it "to check" again when the check reads it). @param array<string, mixed> $change */
    protected function arrivesAgain(int $listingId, array $change): void
    {
        $l = self::$db->one('SELECT cl.channel_id, cl.external_variant_id, lp.product_title, lp.variant_title, lp.brand, CAST(lp.barcodes AS CHAR) AS barcodes, lp.price, lp.perma_link, '
            . 'lp.units_365d FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id = ?', [$listingId]);
        $now = array_filter(['variant_id' => (string) $l['external_variant_id'], 'product_title' => $l['product_title'], 'variant_title' => $l['variant_title'], 'brand' => $l['brand'],
            'barcodes' => $l['barcodes'] === null ? null : json_decode((string) $l['barcodes'], true), 'price' => $l['price'] === null ? null : (float) $l['price'],
            'perma_link' => $l['perma_link'], 'units_365d' => $l['units_365d'] === null ? null : (int) $l['units_365d']], static fn (mixed $v): bool => $v !== null);
        $next = array_filter($change + $now, static fn (mixed $v): bool => $v !== null);
        (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $l['channel_id'], [$next]);
    }

    /** The profile's mark: state, number, outcome. @return array{0: ?string, 1: int, 2: ?string} */
    protected static function arrivalMark(int $listingId): array
    {
        $m = self::$db->one('SELECT arrival_state, arrival_seq, arrival_outcome FROM listing_profile WHERE listing_id = ?', [$listingId]);
        return [$m['arrival_state'], (int) $m['arrival_seq'], $m['arrival_outcome']];
    }

    /** The variant's open suggestion with its run's source, or null. @return array<string, mixed>|null */
    protected static function arrivalOpen(int $listingId): ?array
    {
        $p = self::$db->one('SELECT p.*, r.source, r.run_id AS run_name, r.engine_version, CAST(r.detail AS CHAR) AS run_detail FROM match_proposal p '
            . 'JOIN match_run r ON r.id = p.match_run_id WHERE p.open_listing_id = ?', [$listingId]);
        if ($p === null) {
            return null;
        }
        $p['flags'] = json_decode((string) $p['flags'], true);
        $p['evidence'] = json_decode((string) $p['evidence'], true);
        return $p;
    }
}
