<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Catalogue\ItemRules;

/**
 * The arithmetic of a goods receipt (IM6; docs/decisions.md I129, I130, I134): pure, integers only.
 *
 *  - invoiceKey(): the supplier invoice number as it is compared (upper case, every space removed): "inv 001" and "INV001" are
 *    one invoice of a supplier (UNIQUE (supplier_id, invoice_key)).
 *  - split(): where a line's units go. The paperwork says packs x units per pack (`units`: never "boxes for units"); the bench
 *    says what of it did not arrive (short), arrived damaged, arrived as another item (wrong item), arrived without a valid duty
 *    stamp (unstamped), and what arrived beyond the paperwork (over):
 *        accepted   (MAIN)       units - short - damaged - wrong item - unstamped, plus the unstamped accepted with the
 *                                supplier's "made before 1 Oct 2026" evidence (before the refusal date only)
 *        verify     (VERIFY)     damaged + wrong item + over
 *        quarantine (UNSTAMPED)  unstamped, when quarantined
 *        refused    (not booked) unstamped, when refused at the door
 *        short      (not booked) short
 *    An unstamped delivery's damaged and over units are unstamped too (I167): on a line that needs a duty stamp, when the stamp
 *    is not on the pack (stamp_on_pack 0) or some of its units are refused or quarantined as unstamped, its damaged and over
 *    units follow the unstamped units (refused, or quarantined in UNSTAMPED; quarantined when the bench chose nothing because
 *    nothing it counted was unstamped) instead of going to VERIFY as ordinary stock. Units accepted on the pre-October evidence
 *    keep the rule above (legal stock until 31 Mar 2027). Wrong-item units are another item: VERIFY, whatever the stamp.
 *    Only the accepted units count towards the purchase order line (po_units).
 *  - dutyPencePerUnit(): the expected Vaping Products Duty of one unit, for information only: ml x the rate (22p a ml = GBP 2.20
 *    per 10 ml), rounded DOWN to the penny; only for a card that says duty-liable and holds liquid (not a coil, tank, accessory
 *    or a kit's tank capacity) with its ml known.
 */
final class ReceiptMath
{
    /** The supplier invoice number as a key: upper case, no white space at all; '' when there is none. */
    public static function invoiceKey(?string $invoiceNumber): string
    {
        if ($invoiceNumber === null) {
            return '';
        }
        return mb_strtoupper((string) preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{3000}]+/u', '', $invoiceNumber));
    }

    /**
     * @param array{units: int, short_units: int, over_units: int, damaged_units: int, wrong_item_units: int, unstamped_units: int,
     *              unstamped_action: ?string, linked_to_po: bool, stamp_required?: bool, stamp_on_pack?: ?int} $l
     * @return array{units: int, accepted: int, verify: int, quarantine: int, refused: int, short: int, po_units: int, arrived: int, extras: ?string}
     *   extras: where the damaged and over units went when they count as unstamped ('quarantine' | 'refuse'), null when VERIFY
     */
    public static function split(array $l): array
    {
        $units = $l['units'];
        $unstamped = $l['unstamped_units'];
        $good = $units - $l['short_units'] - $l['damaged_units'] - $l['wrong_item_units'] - $unstamped;
        if ($good < 0) {
            throw new \InvalidArgumentException('the exceptions of a line are more than its units');
        }
        $action = $unstamped > 0 ? $l['unstamped_action'] : null;
        $accepted = $good + ($action === 'accept_pre_october' ? $unstamped : 0);
        $extras = self::extras(($l['stamp_required'] ?? false) === true, $l['stamp_on_pack'] ?? null, $action);
        $extraUnits = $l['damaged_units'] + $l['over_units'];
        return [
            'units' => $units,
            'accepted' => $accepted,
            'verify' => $l['wrong_item_units'] + ($extras === null ? $extraUnits : 0),
            'quarantine' => ($action === 'quarantine' ? $unstamped : 0) + ($extras === 'quarantine' ? $extraUnits : 0),
            'refused' => ($action === 'refuse' ? $unstamped : 0) + ($extras === 'refuse' ? $extraUnits : 0),
            'short' => $l['short_units'],
            'po_units' => $l['linked_to_po'] ? $accepted : 0,
            // What the bench must look at for its duty stamp: the units on the paperwork that arrived as this item, undamaged.
            'arrived' => $units - $l['short_units'] - $l['damaged_units'] - $l['wrong_item_units'],
            'extras' => $extraUnits > 0 ? $extras : null,
        ];
    }

    /**
     * Where an unstamped delivery's damaged and over units go (class docblock, I167): null = VERIFY (the line needs no stamp, the
     * stamp is on the pack and nothing is refused or quarantined, or the unstamped units were accepted on the pre-October evidence),
     * else 'quarantine' or 'refuse' (the unstamped units' action; quarantine when the bench recorded no unstamped units).
     */
    public static function extras(bool $stampRequired, ?int $stampOnPack, ?string $action): ?string
    {
        if (!$stampRequired || $action === 'accept_pre_october') {
            return null;
        }
        if ($action === 'quarantine' || $action === 'refuse') {
            return $action;
        }
        return $stampOnPack === 0 ? 'quarantine' : null;
    }

    /**
     * The expected duty of one unit in pence, or null when the card does not make it knowable: duty-liable (yes), a product that
     * holds liquid (not coil / tank / accessory, and not a device or kit: its ml is the tank's capacity), ml > 0.
     *
     * @param array{product_type: ?string, liquid_ml: ?string, duty_liable: ?bool} $card
     */
    public static function dutyPencePerUnit(array $card, int $pencePerMl): ?int
    {
        if ($card['duty_liable'] !== true || $card['liquid_ml'] === null) {
            return null;
        }
        $type = $card['product_type'];
        if ($type !== null && (in_array($type, ItemRules::DRY_TYPES, true) || $type === 'device_kit')) {
            return null;
        }
        $tenths = self::mlTenths($card['liquid_ml']);
        if ($tenths <= 0) {
            return null;
        }
        return intdiv($tenths * $pencePerMl, 10); // floor: rounded down to the penny
    }

    /** "10.0" / "2.5" / "10" ml as tenths of a ml (integer arithmetic; the card holds one decimal). */
    public static function mlTenths(string $ml): int
    {
        if (preg_match('/^(\d{1,6})(?:\.(\d))?\d*$/D', trim($ml), $m) !== 1) {
            throw new \InvalidArgumentException("not a ml figure: {$ml}");
        }
        return (int) $m[1] * 10 + (int) ($m[2] ?? '0');
    }

    /** Pence as "£1,234.56". */
    public static function money(int $pence): string
    {
        return '£' . number_format(intdiv($pence, 100)) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Pence as a 2-decimal string ("12.34") for a DECIMAL column. */
    public static function decimal(int $pence): string
    {
        return intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The most units a PO line may have received with this receipt: ordered x (100 + tolerance) / 100, rounded down (the
     * over-delivery tolerance, po.over_delivery_tolerance_pct, I52).
     */
    public static function toleranceCap(int $ordered, int $tolerancePct): int
    {
        return intdiv($ordered * (100 + max(0, $tolerancePct)), 100);
    }
}
