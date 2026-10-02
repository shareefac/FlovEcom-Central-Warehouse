<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Mapping\ListingIngestService;

/**
 * Fixtures for the Key re-band / spot-check / bulk-confirm tests (M26-M28), shaped like the real first match: Vape and
 * Go items minted from their listings by the seed (mintAndLink, origin listing; numeric variant ids, so the evidence's
 * lane target `CWP-<variant id>` names that listing as in run3), Electrofag listings pushed through ListingIngestService,
 * and first-match proposals of a b2.0 run carrying the evidence bin/import_proposals.php stores, with the titles the
 * judge saw. Use in a MappingTestCase.
 */
trait KeyFixtures
{
    protected Caller $vpgSite;
    protected Caller $altSite;
    protected Caller $seedLead;
    protected int $firstMatchRun = 0;
    private int $keySeq = 0;

    protected function keySetup(): void
    {
        $this->vpgSite = $this->site('vpg', 'shadow');
        $this->altSite = $this->site('alt', 'shadow');
        $this->seedLead = $this->staffUser('mapping_lead');
        $this->firstMatchRun = $this->proposals->run('run3t-sold', 'first_match', null, 'n2.0/c1.0/v2.0/b2.0', null, ['band_version' => 'b2.0']);
    }

    /** An item minted from a Vape and Go listing by the seed (vpg_mint batch). @return int the item id */
    protected function vpgItem(string $name = ''): int
    {
        $n = ++$this->keySeq;
        $name = $name !== '' ? $name : "Vape and Go item {$n} 10mg";
        $lid = (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $this->vpgSite->channelId,
            [['variant_id' => (string) (700000 + $n), 'product_title' => $name, 'brand' => 'Acme']])['listings'][0]['listing_id'];
        return (int) $this->ds->mintAndLink($this->seedLead, (int) $lid, 0, DecisionService::cardFrom([], [], ['name' => $name, 'brand' => 'Acme']), 'vpg_mint:test')['sku_id'];
    }

    /** An Electrofag listing with a profile. @return int listing id */
    protected function altListing(string $title = '', int $u365 = 0): int
    {
        $n = ++$this->keySeq;
        return (int) (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $this->altSite->channelId,
            [['variant_id' => "A{$n}", 'product_title' => $title !== '' ? $title : "Electrofag listing {$n}", 'units_30d' => intdiv($u365, 10), 'units_365d' => $u365]])['listings'][0]['listing_id'];
    }

    /**
     * Stored first-match evidence for a barcode-lane listing whose key target and judge pick are $sku.
     *
     * @param array<string, mixed> $over evidence keys
     * @param array<string, mixed> $ai judge answer keys
     * @return array<string, mixed>
     */
    protected static function firstMatchEvidence(int $sku, int $conf, array $over = [], array $ai = []): array
    {
        // the item as run3 named it: CWP-<the Vape and Go variant id it was minted from>, with that listing's title
        $v = self::$db->one('SELECT cl.external_variant_id, lp.product_title, lp.variant_title FROM sku s JOIN channel_listing cl ON cl.id = s.origin_listing_id '
            . 'LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE s.id = ?', [$sku]);
        $vid = $v !== null && ctype_digit((string) $v['external_variant_id']) ? (string) $v['external_variant_id'] : (string) $sku;
        $item = ['cw_id' => 'CWP-' . $vid, 'vpg_variant_id' => (int) $vid, 'title' => $v === null ? "item {$sku}" : ($v['variant_title'] ?? $v['product_title']),
            'sku_id' => $sku];
        $band = $conf >= 90 ? 'Key' : 'Check';
        return $over + ['run_id' => 'run3t-sold', 'band' => $band, 'band_reasons' => [$band === 'Key' ? "barcode_key+ai_{$conf}" : "key_with_flags_or_low_conf_{$conf}"],
            'lane' => 'barcode', 'lane_target' => $item, 'lane_flags' => [], 'target_vetoes' => [], 'target_soft_flags' => [], 'key_possible' => true,
            'key_blocked_by' => [], 'relabel_pending' => null, 'relabel_partners' => [],
            'ai' => $ai + ['outcome' => 'match', 'confidence' => $conf, 'units_per_item' => 1, 'reason' => 'same product', 'chosen' => $item, 'closest' => null,
                'fields_not_agree' => [], 'vetoes_on_chosen' => [], 'soft_flags_on_chosen' => [], 'warnings' => [], 'model' => 'claude-sonnet-5-5'],
            'candidates' => [['ref' => 'C1', 'cw_id' => 'CWP-' . $sku, 'sku_id' => $sku, 'role' => 'lane_target', 'prescore' => 92, 'vetoes' => [], 'soft_flags' => []]]];
    }

    /**
     * A first-match proposal (run3t-sold, band b2.0) for a new Electrofag listing: Key at >= 90, Check at 85-89 unless $band.
     *
     * @param array<string, mixed> $ev evidence overrides
     * @param array<string, mixed> $ai judge overrides
     * @param array<string, mixed> $p proposal field overrides
     * @return array{listing: int, proposal: int, sku: int}
     */
    protected function firstMatch(int $sku, int $conf = 95, array $ev = [], array $ai = [], ?string $band = null, array $p = [], int $u365 = 100): array
    {
        $lid = $this->altListing('', $u365);
        $title = (string) self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$lid]);
        $evidence = self::firstMatchEvidence($sku, $conf, $ev + ['title' => $title], $ai);
        $pid = $this->proposals->add(Caller::system('import_proposals'), $lid, $this->firstMatchRun, $p + [
            'proposed_sku_id' => $sku, 'band' => $band ?? ($conf >= 90 ? 'Key' : 'Check'), 'lane' => $evidence['lane'], 'ai_outcome' => $evidence['ai']['outcome'],
            'ai_confidence' => $conf, 'ai_units_per_item' => $evidence['ai']['units_per_item'], 'ai_model' => 'claude-sonnet-5-5', 'evidence' => $evidence, 'flags' => [],
        ])['proposal_id'];
        return ['listing' => $lid, 'proposal' => $pid, 'sku' => $sku];
    }

    /** The site renames a listing (identity change, M20: map_version moves on). */
    protected function rename(int $listingId, string $title): void
    {
        $l = self::$db->one('SELECT channel_id, external_variant_id FROM channel_listing WHERE id = ?', [$listingId]);
        (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $l['channel_id'], [['variant_id' => (string) $l['external_variant_id'], 'product_title' => $title]]);
    }

    /**
     * A second Vape and Go listing linked to an item (by a mapping lead), and the lane target naming it, as run3 would.
     *
     * @return array{listing: int, lane_target: array<string, mixed>}
     */
    protected function secondVpgListing(int $sku, string $title = ''): array
    {
        $n = ++$this->keySeq;
        $vid = 700000 + $n;
        $title = $title !== '' ? $title : "Vape and Go listing {$n}";
        $lid = (int) (new ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $this->vpgSite->channelId,
            [['variant_id' => (string) $vid, 'product_title' => $title, 'brand' => 'Acme']])['listings'][0]['listing_id'];
        $this->ds->decide($this->seedLead, ['action' => 'link', 'listing_id' => $lid, 'sku_id' => $sku, 'units_per_item' => 1,
            'expected_map_version' => (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$lid])]);
        return ['listing' => $lid, 'lane_target' => ['cw_id' => 'CWP-' . $vid, 'vpg_variant_id' => $vid, 'title' => $title, 'sku_id' => $sku]];
    }

    /** The Vape and Go listing an item was minted from (the lane target of its first-match proposals). */
    protected static function vpgListingOf(int $sku): int
    {
        return (int) self::$db->value('SELECT origin_listing_id FROM sku WHERE id = ?', [$sku]);
    }

    /** A person confirms a proposal on the review screen (link to its item, u = 1, the proposal named). */
    protected function confirm(Caller $who, int $proposalId): array
    {
        $p = self::$db->one('SELECT listing_id, proposed_sku_id FROM match_proposal WHERE id = ?', [$proposalId]);
        return $this->decide($who, 'link', (int) $p['listing_id'], ['sku_id' => (int) $p['proposed_sku_id'], 'units_per_item' => 1, 'proposal_id' => $proposalId]);
    }

    /** @return array<string, mixed>|null */
    protected static function proposalRow(int $id): ?array
    {
        return self::$db->one('SELECT * FROM match_proposal WHERE id = ?', [$id]);
    }

    /** Drops the recorded bases, as for proposals made before 0010 (admin login; the app login cannot). @param list<int> $ids */
    protected static function forgetBases(array $ids): void
    {
        self::$db->transaction(static function (Db $db) use ($ids): void {
            foreach ($ids as $id) {
                $db->exec('DELETE FROM match_proposal_basis WHERE proposal_id = ?', [$id]);
            }
        });
    }
}
