<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\CwException;
use CW\Idempotency;
use CW\Reservations;

/**
 * The shop -> CW order snapshot (the agreed contract: /root/cw_work/profit_20261119/build/CONTRACT.md; docs/decisions.md PR1-PR3): one
 * whole picture of an order's money and parcels. parse() checks every field the contract names and returns the NORMALISED body: only
 * those fields, in a fixed shape (ids as strings, times as UTC "Y-m-d H:i:s.u", money as integer pence inc VAT). An unknown field is
 * ignored and never kept (forward compatible, and no personal data can ride in on a field CW does not know). A bad field is 400
 * bad_snapshot with `field` naming it (lines[3].price_inc).
 *
 * hash() is the content's fingerprint WITHOUT the version: the same content sent again with a newer version is "same".
 */
final class SnapshotBody
{
    public const MAX_LINES = 1000;
    public const MAX_PARCELS = 50;
    public const MAX_BYTES = 256 * 1024;
    /** The most of one line's qty (a shop line is one unit; a few are more). */
    public const MAX_QTY = 100_000;
    /** A negative discount (a coupon's rounding pennies) is at most this many pence (CONTRACT.md, change 1). */
    public const MAX_NEGATIVE_DISCOUNT = 10_000;
    /**
     * How far below 0 a line's price may go, and how far below minus its price its discount may go, in pence (CONTRACT.md change 8, PR83:
     * the shop leaves spread remainders of -2p / -3p and a coupon's remainder of -99p on a 49p line).
     */
    public const MAX_NEGATIVE_PRICE = 100;
    /** A paid or label time may be at most this far after CW's clock (the shops' clocks drift a little). */
    public const MAX_AHEAD_SEC = Clock::MAX_AHEAD_SEC;

    /**
     * @param array<string, mixed> $raw the decoded JSON object
     * @return array<string, mixed> the normalised body
     */
    public static function parse(array $raw, \DateTimeImmutable $now): array
    {
        if (($raw['v'] ?? null) !== 1) {
            throw self::bad('v', 'v must be 1');
        }
        $version = $raw['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw self::bad('version', 'version must be a whole number from 1');
        }
        $currency = $raw['currency'] ?? null;
        if ($currency !== 'GBP') {
            throw self::bad('currency', 'currency must be GBP');
        }
        $placed = self::time($raw['placed_at'] ?? null, 'placed_at', $now, true);
        $paid = self::time($raw['paid_at'] ?? null, 'paid_at', $now, true);
        $parent = $raw['parent_ref'] ?? null;
        if ($parent !== null) {
            $parent = self::ref($parent, 'parent_ref');
        }
        // An office "sub order" (CONTRACT.md, change 2): its own sale, kept to show the link.
        $subOf = $raw['sub_of'] ?? null;
        if ($subOf !== null) {
            $subOf = self::ref($subOf, 'sub_of');
        }
        $lines = $raw['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            throw self::bad('lines', 'lines must be a list');
        }
        if (count($lines) > self::MAX_LINES) {
            throw self::bad('lines', 'an order may have at most ' . self::MAX_LINES . ' lines');
        }
        $outLines = [];
        $seen = [];
        foreach ($lines as $i => $l) {
            $f = "lines[{$i}]";
            if (!is_array($l) || array_is_list($l) && $l !== []) {
                throw self::bad($f, "{$f} must be an object");
            }
            $id = self::ref($l['line_id'] ?? null, "{$f}.line_id");
            if (isset($seen[$id])) {
                throw self::bad("{$f}.line_id", "line {$id} appears twice");
            }
            $seen[$id] = true;
            $qty = $l['qty'] ?? null;
            if (!is_int($qty) || $qty < 1 || $qty > self::MAX_QTY) {
                throw self::bad("{$f}.qty", "{$f}.qty must be a whole number from 1 to " . self::MAX_QTY);
            }
            // A price may be a few pence below 0 (CONTRACT.md change 8, PR83: a spread remainder the shop stores next to free lines), down to
            // -MAX_NEGATIVE_PRICE.
            $price = self::pence($l['price_inc'] ?? null, "{$f}.price_inc", true);
            if ($price < -self::MAX_NEGATIVE_PRICE) {
                throw self::bad("{$f}.price_inc", "{$f}.price_inc may be below 0 by at most " . self::MAX_NEGATIVE_PRICE . ' pence');
            }
            // A discount may be below 0 (CONTRACT.md changes 1 and 8): the shop leaves a coupon's rounding pennies on one line; never below
            // minus (the line's price + MAX_NEGATIVE_PRICE), nor below -MAX_NEGATIVE_DISCOUNT.
            $discount = self::pence($l['discount_inc'] ?? 0, "{$f}.discount_inc", true);
            if ($discount < -min($price + self::MAX_NEGATIVE_PRICE, self::MAX_NEGATIVE_DISCOUNT)) {
                throw self::bad("{$f}.discount_inc", "{$f}.discount_inc may be below 0 by at most the line's price + " . self::MAX_NEGATIVE_PRICE . ' pence and at most '
                    . self::MAX_NEGATIVE_DISCOUNT . ' pence');
            }
            $outLines[] = [
                'line_id' => $id,
                'variant_id' => self::ref($l['variant_id'] ?? null, "{$f}.variant_id"),
                'qty' => $qty,
                'price_inc' => $price,
                'discount_inc' => $discount,
                'cancelled' => self::flag($l['cancelled'] ?? false, "{$f}.cancelled"),
                'refund_inc' => self::pence($l['refund_inc'] ?? 0, "{$f}.refund_inc"),
                'free' => self::flag($l['free'] ?? false, "{$f}.free"),
            ];
        }
        $d = $raw['delivery'] ?? null;
        if ($d !== null && (!is_array($d) || (array_is_list($d) && $d !== []))) {
            throw self::bad('delivery', 'delivery must be an object or null');
        }
        $d ??= [];
        $delivery = [
            'method_id' => self::optText($d['method_id'] ?? null, 'delivery.method_id', 64, true),
            'method' => self::optText($d['method'] ?? null, 'delivery.method', 128),
            'charged_inc' => self::pence($d['charged_inc'] ?? 0, 'delivery.charged_inc'),
            'refund_inc' => self::pence($d['refund_inc'] ?? 0, 'delivery.refund_inc'),
        ];
        $parcels = $raw['parcels'] ?? [];
        if (!is_array($parcels) || !array_is_list($parcels)) {
            throw self::bad('parcels', 'parcels must be a list');
        }
        if (count($parcels) > self::MAX_PARCELS) {
            throw self::bad('parcels', 'an order may have at most ' . self::MAX_PARCELS . ' parcels');
        }
        $outParcels = [];
        $seenP = [];
        foreach ($parcels as $i => $p) {
            $f = "parcels[{$i}]";
            if (!is_array($p) || (array_is_list($p) && $p !== [])) {
                throw self::bad($f, "{$f} must be an object");
            }
            $ref = self::ref($p['parcel_ref'] ?? null, "{$f}.parcel_ref");
            if (isset($seenP[$ref])) {
                throw self::bad("{$f}.parcel_ref", "parcel {$ref} appears twice");
            }
            $seenP[$ref] = true;
            $outParcels[] = [
                'parcel_ref' => $ref,
                'tracking_no' => self::tracking(self::optText($p['tracking_no'] ?? null, "{$f}.tracking_no", 64, true),
                    (string) self::optText($p['carrier'] ?? null, "{$f}.carrier", 32), self::flag($p['manual'] ?? false, "{$f}.manual")),
                'carrier' => (string) self::optText($p['carrier'] ?? null, "{$f}.carrier", 32),
                'service_code' => (string) self::optText($p['service_code'] ?? null, "{$f}.service_code", 64, true),
                'service' => (string) self::optText($p['service'] ?? null, "{$f}.service", 128),
                'status' => strtolower((string) self::optText($p['status'] ?? null, "{$f}.status", 32)),
                'label_at' => self::time($p['label_at'] ?? null, "{$f}.label_at", $now, true),
                'shipped_at' => self::time($p['shipped_at'] ?? null, "{$f}.shipped_at", $now, true),
                'manual' => self::flag($p['manual'] ?? false, "{$f}.manual"),
                'partial' => self::flag($p['partial'] ?? false, "{$f}.partial"),
            ];
        }
        return [
            'v' => 1,
            'version' => $version,
            'order_no' => (string) self::optText($raw['order_no'] ?? null, 'order_no', 64),
            'status' => strtolower((string) self::optText($raw['status'] ?? null, 'status', 32)),
            'placed_at' => $placed,
            'paid_at' => $paid,
            'gateway' => strtolower((string) self::optText($raw['gateway'] ?? null, 'gateway', 32)),
            'currency' => 'GBP',
            'parent_ref' => $parent,
            'sub_of' => $subOf,
            'lines' => $outLines,
            'delivery' => $delivery,
            'order_refund_inc' => self::pence($raw['order_refund_inc'] ?? 0, 'order_refund_inc'),
            'grand_inc' => self::pence($raw['grand_inc'] ?? 0, 'grand_inc'),
            'parcels' => $outParcels,
        ];
    }

    /** sha256 of the normalised content without its version (and without any `ref` of a bulk line). */
    public static function hash(array $body): string
    {
        unset($body['version'], $body['ref']);
        return hash('sha256', Idempotency::canonicalJson($body));
    }

    /**
     * A carrier's tracking number as CONTRACT.md change 5b says it (builder B, 20 Nov 2026): written as the carriers' invoices print it (spaces
     * and hyphens taken out, upper case) and kept only in one of the carriers' own shapes: Royal Mail XX123456789GB, DPD 10 or 14 digits,
     * Evri / Parcel2Go H + 15 letters or digits, UPS 1Z + 16. Anything else (a note typed by hand, a phone number, a name) is kept as null,
     * so no free text reaches CW (the Codex reviews of 92efa82 b12 and of 57d98ad).
     */
    public static function tracking(?string $t, string $carrier = '', bool $manual = false): ?string
    {
        if ($t === null) {
            return null;
        }
        $n = strtoupper((string) preg_replace('/[\s-]+/', '', $t));
        foreach (['/^[A-Z]{2}[0-9]{9}GB$/D', '/^H[A-Z0-9]{15}$/D', '/^1Z[A-Z0-9]{16}$/D'] as $shape) {
            if (preg_match($shape, $n) === 1) {
                return $n;
            }
        }
        // Digits alone are DPD's shape only on a DPD label the shop made (never on a parcel marked by hand, whose number a person typed: a
        // phone number has 10 or 11 digits; the Codex re-check of 37a2cf0, b12).
        if (preg_match('/^([0-9]{10}|[0-9]{14})$/D', $n) === 1 && strtolower(trim($carrier)) === 'dpd' && !$manual) {
            return $n;
        }
        return null;
    }

    /** The order ref of a path or a bulk line (1-64 printable characters, as the reservations). */
    public static function orderRef(mixed $v, string $field = 'ref'): string
    {
        return self::ref($v, $field);
    }

    private static function ref(mixed $v, string $field): string
    {
        if (is_int($v) && $v >= 0) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            throw self::bad($field, "{$field} must be a string or a whole number");
        }
        try {
            return Reservations::ref($v);
        } catch (CwException) {
            throw self::bad($field, "{$field} must be 1-64 printable characters");
        }
    }

    private static function pence(mixed $v, string $field, bool $signed = false): int
    {
        $min = $signed ? -ProfitMath::MAX_PENCE : 0;
        if (!is_int($v) || $v < $min || $v > ProfitMath::MAX_PENCE) {
            throw self::bad($field, "{$field} must be whole pence from {$min} to " . ProfitMath::MAX_PENCE);
        }
        return $v;
    }

    private static function flag(mixed $v, string $field): bool
    {
        if (!is_bool($v)) {
            throw self::bad($field, "{$field} must be true or false");
        }
        return $v;
    }

    /** A short text (null => null); with $ids a whole number is taken as its digits. No control characters. */
    private static function optText(mixed $v, string $field, int $max, bool $ids = false): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($ids && is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v) || mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/', $v) === 1) {
            throw self::bad($field, "{$field} must be a text of at most {$max} characters");
        }
        return trim($v);
    }

    /** A UTC time ("Y-m-d H:i:s.u") from an ISO-8601 one, or null; never more than MAX_AHEAD_SEC after CW's clock, never before 2000. */
    private static function time(mixed $v, string $field, \DateTimeImmutable $now, bool $nullable): ?string
    {
        if ($v === null && $nullable) {
            return null;
        }
        try {
            $t = Clock::parse($v, $field);
        } catch (CwException) {
            throw self::bad($field, "{$field} must be an ISO-8601 time with its zone");
        }
        if ((int) $t->format('Y') < 2000 || Clock::diff($now, $t) > self::MAX_AHEAD_SEC) {
            throw self::bad($field, "{$field} must be a real time, not in the future");
        }
        return Clock::db($t);
    }

    private static function bad(string $field, string $message): CwException
    {
        return new CwException('bad_snapshot', $message, 400, ['field' => $field]);
    }
}
