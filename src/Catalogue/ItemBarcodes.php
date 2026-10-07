<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Matching\Gtin;

/**
 * The barcodes of an item, changed by a person on the item page (IM3; docs/decisions.md I106): add a barcode (an outer case
 * with its units per scan: "this barcode = 10 units"), remove one, change its units per scan. sku_barcode is keyed by the
 * barcode (Gtin::key: digits, no leading zeros, a valid GTIN check digit: the same rule as the seeder and the sync), so a
 * barcode is on one item at most.
 *
 *  - A barcode already on another item is refused (409 barcode_on_other_item): a person removes it there first, or decides it
 *    in the barcode review when both items' listings carry it. It is never moved silently.
 *  - A barcode with an open barcode review is added unusable (is_usable = 0: the existing rule "a barcode on two items is
 *    unusable until fixed"); the review decides.
 *  - A removal is recorded as a decided barcode_review row (reason `removed`), so the barcode sync never adds it back to the
 *    item from a listing that still carries it; adding it again by hand is allowed.
 *  - Who: as ItemCards::editor (catalogue.edit, never admin). Audit sku_barcode.add / remove / units.
 */
final class ItemBarcodes
{
    public const UNITS_MAX = 10_000;
    public const SOURCE_MANUAL = 'manual';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The GTIN key of what a person typed or scanned (spaces allowed), or 422 bad_barcode saying what is wrong (the expected
     * check digit when only that is wrong).
     */
    public static function parse(string $raw): string
    {
        $digits = str_replace([' ', '-'], '', trim($raw));
        if ($digits === '' || preg_match('/^[0-9]+$/D', $digits) !== 1) {
            throw new CwException('bad_barcode', 'A barcode is 8, 12, 13 or 14 digits (spaces are allowed).', 422);
        }
        $c = Gtin::classify($digits);
        if ($c['usable']) {
            return (string) $c['key'];
        }
        if ($c['reason'] === 'bad_check_digit') {
            $key = (string) $c['key'];
            throw new CwException('bad_barcode', 'This barcode\'s check digit is wrong: with these digits the last one would be '
                . Gtin::checkDigit(substr($key, 0, -1)) . '. Check what was typed or scan it again.', 422);
        }
        throw new CwException('bad_barcode', 'A barcode is 8, 12, 13 or 14 digits with a valid check digit (an EAN or UPC from the box); '
            . 'shop codes are not barcodes.', 422);
    }

    /**
     * Adds a barcode to an item. 201-style result {barcode, usable}. 409 barcode_exists / barcode_on_other_item, 422
     * bad_barcode / bad_units, 404 unknown_item, 409 merged_item, 403 as ItemCards::editor.
     *
     * @return array{barcode: string, usable: bool, units: int}
     */
    public function add(Caller $caller, int $skuId, string $raw, int $units = 1): array
    {
        $key = self::parse($raw);
        self::checkUnits($units);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $key, $units): array {
            ItemCards::editor($db, $caller);
            $sku = self::lockItem($db, $skuId);
            $row = $db->one('SELECT b.sku_id, s.code, s.name FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode = ? FOR UPDATE', [$key]);
            if ($row !== null && (int) $row['sku_id'] === $skuId) {
                throw new CwException('barcode_exists', "{$key} is already a barcode of {$sku['code']}.", 409);
            }
            if ($row !== null) {
                throw new CwException('barcode_on_other_item', "{$key} is a barcode of {$row['code']} ({$row['name']}). A barcode belongs to one item: if it "
                    . "belongs here, remove it from {$row['code']} first (or decide it in the barcode review when both items' listings carry it).", 409,
                    ['sku_id' => (int) $row['sku_id'], 'code' => (string) $row['code']]);
            }
            $inReview = $db->value("SELECT id FROM barcode_review WHERE barcode = ? AND status = 'open' LIMIT 1", [$key]) !== null;
            try {
                $db->exec('INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source, note) VALUES (?, ?, ?, ?, ?, ?)',
                    [$key, $skuId, $inReview ? 0 : 1, $units, self::SOURCE_MANUAL, $inReview ? 'in barcode review' : null]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    throw new CwException('barcode_on_other_item', "{$key} was given to another item a moment ago: reload the page.", 409);
                }
                throw $e;
            }
            Audit::write($db, $caller, 'sku_barcode.add', 'sku', (string) $skuId, null, ['barcode' => $key, 'units_per_scan' => $units, 'usable' => !$inReview]);
            return ['barcode' => $key, 'usable' => !$inReview, 'units' => $units];
        });
    }

    /**
     * Removes a barcode from an item (409 barcode_gone when it is not on it), recorded as a decided `removed` review so the
     * sync does not add it back. $reason: optional, at most 500 characters.
     *
     * @return array{barcode: string, review_id: int}
     */
    public function remove(Caller $caller, int $skuId, string $barcode, ?string $reason = null): array
    {
        $key = Gtin::key($barcode) ?? '';
        $reason = self::note($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $key, $reason): array {
            $me = ItemCards::editor($db, $caller);
            $sku = self::lockItem($db, $skuId, true);
            $row = $key === '' ? null : $db->one('SELECT sku_id, units_per_scan, is_usable, source FROM sku_barcode WHERE barcode = ? FOR UPDATE', [$key]);
            if ($row === null || (int) $row['sku_id'] !== $skuId) {
                throw new CwException('barcode_gone', "{$key} is not a barcode of {$sku['code']} (any more): reload the page.", 409);
            }
            $db->exec('DELETE FROM sku_barcode WHERE barcode = ?', [$key]);
            $review = $db->insert("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, status, decision, decided_by, decided_actor, decided_at, "
                . "note, opened_by, opened_actor) VALUES (?, 'removed', ?, ?, 'decided', 'removed', ?, ?, UTC_TIMESTAMP(6), ?, ?, ?)",
                [$key, $skuId, $skuId, $me['id'], $caller->actor, $reason, $me['id'], $caller->actor]);
            Audit::write($db, $caller, 'sku_barcode.remove', 'sku', (string) $skuId, null, ['barcode' => $key, 'units_per_scan' => (int) $row['units_per_scan'],
                'was_usable' => (int) $row['is_usable'] === 1, 'source' => $row['source'], 'reason' => $reason, 'review_id' => $review]);
            return ['barcode' => $key, 'review_id' => $review];
        });
    }

    /**
     * Sets how many units one scan of the barcode counts (an outer case of 10: 10). $unitsWas is what the form showed (409
     * barcode_changed when it differs now). Nothing changed writes nothing.
     *
     * @return array{barcode: string, units: int, result: string}
     */
    public function setUnits(Caller $caller, int $skuId, string $barcode, int $unitsWas, int $units): array
    {
        $key = Gtin::key($barcode) ?? '';
        self::checkUnits($units);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $key, $unitsWas, $units): array {
            ItemCards::editor($db, $caller);
            $sku = self::lockItem($db, $skuId);
            $row = $key === '' ? null : $db->one('SELECT sku_id, units_per_scan FROM sku_barcode WHERE barcode = ? FOR UPDATE', [$key]);
            if ($row === null || (int) $row['sku_id'] !== $skuId) {
                throw new CwException('barcode_gone', "{$key} is not a barcode of {$sku['code']} (any more): reload the page.", 409);
            }
            if ((int) $row['units_per_scan'] !== $unitsWas) {
                throw new CwException('barcode_changed', "Someone changed {$key} meanwhile: it counts {$row['units_per_scan']} units a scan now. Look again.", 409);
            }
            if ($units === $unitsWas) {
                return ['barcode' => $key, 'units' => $units, 'result' => 'unchanged'];
            }
            $db->exec('UPDATE sku_barcode SET units_per_scan = ? WHERE barcode = ?', [$units, $key]);
            Audit::write($db, $caller, 'sku_barcode.units', 'sku', (string) $skuId, null, ['barcode' => $key, 'before' => $unitsWas, 'after' => $units]);
            return ['barcode' => $key, 'units' => $units, 'result' => 'saved'];
        });
    }

    public static function checkUnits(int $units): void
    {
        if ($units < 1 || $units > self::UNITS_MAX) {
            throw new CwException('bad_units', 'Units per scan is a whole number from 1 (one item) to ' . number_format(self::UNITS_MAX)
                . ' (for example 10 for an outer case of 10).', 422);
        }
    }

    /** A note of at most 500 characters on one line, or null. */
    public static function note(?string $note): ?string
    {
        $note = $note === null ? null : trim((string) preg_replace('/\s+/u', ' ', $note));
        if ($note === null || $note === '') {
            return null;
        }
        if (!mb_check_encoding($note, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $note) === 1 || mb_strlen($note) > 500) {
            throw new CwException('bad_note', 'The note is at most 500 characters of plain text.', 400);
        }
        return $note;
    }

    /**
     * The item, read FOR SHARE (not merged meanwhile): 404 unknown_item, 409 merged_item (unless $mergedOk: a merged item's
     * barcodes may still be removed, to free them for the item it was merged into).
     *
     * @return array<string, mixed>
     */
    public static function lockItem(Db $db, int $skuId, bool $mergedOk = false): array
    {
        $sku = $db->one('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id = ? FOR SHARE', [$skuId])
            ?? throw new CwException('unknown_item', 'There is no such item.', 404);
        if (!$mergedOk && $sku['merged_into_sku_id'] !== null) {
            throw new CwException('merged_item', "{$sku['code']} was merged into another item: its barcodes are only removed, not added or changed.", 409);
        }
        return $sku;
    }
}
