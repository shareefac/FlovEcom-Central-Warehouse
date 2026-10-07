<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\BarcodeReviews;
use CW\Db;
use CW\Matching\Gtin;

/**
 * Seeds sku_barcode from the listing each item was minted from (design S3): the usable GTINs on its
 * listing_profile.barcodes, stored as their comparison key (CW\Matching\Gtin::key: digits, no leading
 * zeros; 8-14 digits with a valid check digit, so "Black Grey", short shop codes and URLs never get in).
 * Until this runs, an item shows "Barcodes: none" next to a listing that shares its barcode, and the
 * review loses its main tie-breaker.
 *
 * Idempotent: a key already recorded for the item is left alone. A key already on ANOTHER item is not
 * added twice (sku_barcode is keyed by barcode): the existing row is marked unusable (is_usable = 0,
 * "0 while the barcode is found on 2 items") and the clash is counted, never resolved here (bin/sync_barcodes.php opens the
 * barcode review for it). A key a person decided away from the item (the latest barcode review of the pair is in
 * CW\Catalogue\BarcodeReviews::KEEPS_AWAY: removed by a person, kept on another item, ruled unusable, not added, moved away) is
 * skipped and counted `skipped_decided`, as the sync does (IM3, I118): a re-run never undoes a person's decision.
 * One transaction per chunk of items; one audit row per run.
 */
final class BarcodeSeeder
{
    public const SOURCE = 'origin_listing';
    private const CHUNK = 500;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param list<int>|null $skuIds only these items (null: every item that has an origin listing)
     * @return array{items: int, with_barcodes: int, added: int, already: int, unusable_codes: int, clashes: int, skipped_decided: int}
     */
    public function seed(Caller $caller, ?array $skuIds = null, bool $dryRun = false): array
    {
        $c = ['items' => 0, 'with_barcodes' => 0, 'added' => 0, 'already' => 0, 'unusable_codes' => 0, 'clashes' => 0, 'skipped_decided' => 0];
        $ids = $skuIds ?? array_map('intval', $this->db->column('SELECT id FROM sku WHERE origin_listing_id IS NOT NULL ORDER BY id'));
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->all(
                'SELECT s.id, s.code, s.origin_listing_id, lp.barcodes FROM sku s JOIN listing_profile lp ON lp.listing_id = s.origin_listing_id '
                . 'WHERE s.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY s.id',
                $chunk,
            );
            $c['items'] += count($chunk);
            $work = [];
            foreach ($rows as $r) {
                $codes = json_decode((string) $r['barcodes'], true);
                $keys = [];
                foreach (is_array($codes) ? $codes : [] as $raw) {
                    $g = Gtin::classify($raw);
                    if ($g['usable']) {
                        $keys[(string) $g['key']] = true;
                    } else {
                        $c['unusable_codes']++;
                    }
                }
                if ($keys !== []) {
                    $c['with_barcodes']++;
                    $work[] = [(int) $r['id'], (string) $r['code'], (int) $r['origin_listing_id'], array_map('strval', array_keys($keys))];
                }
            }
            if ($work === [] || $dryRun) {
                foreach ($work as [, , , $keys]) {
                    $c['added'] += count($keys); // would add (dry run: existing rows are not looked up)
                }
                continue;
            }
            $done = $this->db->transaction(function (Db $db) use ($work): array {
                $n = ['added' => 0, 'already' => 0, 'clashes' => 0, 'skipped_decided' => 0];
                foreach ($work as [$skuId, $code, $listingId, $keys]) {
                    foreach ($keys as $key) {
                        $have = $db->one('SELECT sku_id, is_usable FROM sku_barcode WHERE barcode = ? FOR UPDATE', [$key]);
                        if ($have !== null && (int) $have['sku_id'] === $skuId) {
                            $n['already']++;
                            continue;
                        }
                        $latest = $db->value("SELECT decision FROM barcode_review WHERE barcode = ? AND claimant_sku_id = ? AND status = 'decided' ORDER BY id DESC LIMIT 1",
                            [$key, $skuId]);
                        if ($latest !== null && in_array((string) $latest, BarcodeReviews::KEEPS_AWAY, true)) {
                            $n['skipped_decided']++;
                            continue;
                        }
                        if ($have === null) {
                            // A barcode with an open barcode review (0016, I107) goes in unusable: the review decides.
                            $inReview = $db->value("SELECT 1 FROM barcode_review WHERE barcode = ? AND status = 'open' LIMIT 1", [$key]) !== null;
                            $db->exec('INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source, note) VALUES (?, ?, ?, 1, ?, ?)',
                                [$key, $skuId, $inReview ? 0 : 1, self::SOURCE, "listing {$listingId}" . ($inReview ? ' (in barcode review)' : '')]);
                            $n['added']++;
                        } else {
                            $n['clashes']++;
                            if ((int) $have['is_usable'] === 1) {
                                $db->exec('UPDATE sku_barcode SET is_usable = 0, note = ? WHERE barcode = ?', [mb_substr("also on {$code}", 0, 255), $key]);
                            }
                        }
                    }
                }
                return $n;
            });
            foreach ($done as $k => $v) {
                $c[$k] += $v;
            }
        }
        if (!$dryRun) {
            Audit::write($this->db, $caller, 'sku_barcode.seed', 'sku', null, null, $c + ['source' => self::SOURCE]);
        }
        return $c;
    }
}
