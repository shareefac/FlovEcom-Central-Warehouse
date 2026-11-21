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
 *
 * No free text is ever kept (the final review of 6d59eda, b-HIGH2; docs/decisions.md PR96): every text field takes only the shapes the
 * connector really sends. Ids and refs are codes (letters, digits and : _ . -, refused otherwise: they are keys); the order number is a
 * number (a few letters before it at most), else ""; the delivery method id is digits, the service code a code without a long run of
 * digits, else null / ""; statuses, the payment way and the carrier are words of a fixed list, else "other"; a delivery method or service
 * name is kept only when every word of it is a word of the carriers' services (SERVICE_WORDS) or a short number, else null / "". So a name,
 * an e-mail address, a phone number or a postal address can never be stored, whatever a connector maps into a field.
 */
final class SnapshotBody
{
    public const MAX_LINES = 1000;
    public const MAX_PARCELS = 50;
    public const MAX_BYTES = 256 * 1024;
    /** A shop line is ONE unit (one orders_items row: the connector always sends qty 1; the final review of 6d59eda, c-M1, PR97). */
    public const LINE_QTY = 1;
    /** An id or a ref: a code (the shops' ids with their store prefix), never a text with spaces or @. */
    public const CODE = '/^[A-Za-z0-9][A-Za-z0-9:_.\-]{0,63}$/D';
    /** The most digits a service name or code may hold in all (the real ones hold 4 at most: "Tracked 24(1-2 Working Days)", TPL48HV). */
    public const TEXT_DIGITS = 6;
    /** The order number the customer sees: digits, a few letters before them at most (VPG1567469, 1567469), or "". */
    public const ORDER_NO = '/^(?:[A-Za-z]{0,5}[1-9][0-9]{0,9})?$/D';
    /** The shops' order statuses (lower case words); any other is kept as "other". */
    public const ORDER_STATUSES = ['completed', 'cancelled', 'canceled', 'processing', 'failed', 'pending', 'pending_payment', 'awaiting_payment', 'refunded',
        'partially_refunded', 'on_hold', 'hold', 'paid', 'dispatched', 'part_dispatched', 'partially_dispatched', 'shipped', 'partially_shipped', 'delivered',
        'returned', 'draft', 'new', 'abandoned', 'expired', 'declined', 'error', 'fraud', 'awaiting_dispatch', 'packed', 'picked', 'ready', 'collected', 'backorder',
        'back_order', 'pre_order', 'void', 'voided', 'archived', 'other'];
    /** The parcels' statuses (lower case words); any other is kept as "other". */
    public const PARCEL_STATUSES = ['generated', 'created', 'cancelled', 'canceled', 'error', 'failed', 'printed', 'dispatched', 'shipped', 'delivered', 'returned',
        'void', 'voided', 'pending', 'manifested', 'collected', 'awaiting_label', 'label_created', 'in_transit', 'out_for_delivery', 'lost', 'damaged', 'held',
        'booked', 'submitted', 'accepted', 'rejected', 'completed', 'processing', 'new', 'draft', 'ready', 'packed', 'other'];
    /** The shops' payment ways (CONTRACT change 3); any other is kept as "other". */
    public const GATEWAYS = ['', 'globalpay', 'pp', 'pphf', 'viva', 'payroc', 'office', 'paypal', 'worldpay', 'stripe', 'card', 'cash', 'bank_transfer', 'cod', 'other'];
    /** The carriers (the connector's words); any other is kept as "other". */
    public const CARRIERS = ['', 'royal_mail', 'dpd', 'dpd_local', 'manual', 'in_store', 'evri', 'hermes', 'parcel2go', 'ups', 'dhl', 'fedex', 'yodel', 'parcelforce',
        'apc', 'tnt', 'amazon', 'inpost', 'collection', 'other'];
    /**
     * The words a delivery method or service name may be made of (the shops' shipping methods: "Royal Mail Tracked 48 (2-4 Working Days)",
     * "DPD Pickup (2 day Service)", "In Store Collection Preston", "EVRI International", "Return label", ...), with short numbers. A name made of
     * any other word is not kept (null): it could be a person's name or an address.
     */
    public const SERVICE_WORDS = ['royal', 'mail', 'tracked', 'signed', 'special', 'next', 'day', 'days', 'delivery', 'working', 'dpd', 'pickup', 'pick', 'up',
        'service', 'services', 'international', 'evri', 'hermes', 'in', 'store', 'collection', 'collect', 'click', 'and', 'return', 'returns', 'label', 'labels',
        'standard', 'express', 'first', 'second', 'class', 'saturday', 'sunday', 'weekend', 'local', 'parcel', 'parcels', 'parcel2go', 'ups', 'dhl', 'fedex',
        'yodel', 'parcelforce', 'courier', 'free', 'shipping', 'economy', 'priority', 'europe', 'european', 'eu', 'world', 'worldwide', 'global', 'uk', 'by',
        'before', 'am', 'pm', 'noon', 'midday', 'the', 'to', 'for', 'on', 'of', 'or', 'large', 'letter', 'small', 'packet', 'preston', 'office', 'shop', 'counter',
        'manual', 'same', 'hour', 'hours', 'hr', 'hrs', 'guaranteed', 'recorded', 'tracking', 'signature', 'required', 'no', 'only', 'plus', 'premium', 'ireland',
        'northern', 'highlands', 'islands', 'mainland', 'rest', 'air', 'ground', 'letterbox', 'drop', 'off', 'point', 'locker', 'inpost', 'home', 'trade',
        'wholesale', 'pallet', 'collected', 'customer', 'own', 'arranged', 'other', 'apc', 'tnt', 'amazon', 'dpdlocal', 'tracked24', 'tracked48', 'x'];
    /**
     * The words that make a name a carrier's or a service's (one of them at least, besides SERVICE_WORDS for every word): "12 Preston" or
     * "Northern Ireland" alone could be an address and is never kept; "Royal Mail Tracked 48", "DPD", "In Store Collection Preston" are (the
     * second final review of 3c31d94, b-HIGH1, PR141; 'road', a street word, is no service word since).
     */
    public const SERVICE_ANCHORS = ['royal', 'mail', 'dpd', 'dpdlocal', 'evri', 'hermes', 'parcel2go', 'ups', 'dhl', 'fedex', 'yodel', 'parcelforce', 'apc', 'tnt',
        'amazon', 'inpost', 'tracked', 'tracked24', 'tracked48', 'signed', 'special', 'delivery', 'collection', 'collect', 'pickup', 'courier', 'shipping', 'express',
        'standard', 'economy', 'priority', 'international', 'return', 'returns', 'label', 'labels', 'letter', 'letterbox', 'packet', 'parcel', 'parcels', 'service',
        'services', 'locker', 'pallet'];
    /** A parcel's ref: the shop's dispatch row (odl:<id>) or return label (orl:<id>), CONTRACT change 4; nothing else (PR141). */
    public const PARCEL_REF = '/^(?:odl|orl):[0-9]{1,18}$/iD';
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
        // paid_at is always sent (null when not paid): a body without it is refused, never read as "not paid", which would take a paid sale
        // off its day (the final review of 6d59eda, b-M; PR101).
        if (!array_key_exists('paid_at', $raw)) {
            throw self::bad('paid_at', 'paid_at must be sent (null when the order is not paid)');
        }
        $paid = self::time($raw['paid_at'], 'paid_at', $now, true);
        // parent_ref is always sent too (null when the order is no re-send): a body without it is refused, never read as "no parent", which
        // would make a replacement its own sale again (the second final review of 3c31d94, b-M2, PR142; connector 0.4.17 always sends it).
        if (!array_key_exists('parent_ref', $raw)) {
            throw self::bad('parent_ref', 'parent_ref must be sent (null when the order is no re-send)');
        }
        $parent = $raw['parent_ref'];
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
            // The same line in another case is the same line to the database (its key compares without case: the final review of 6d59eda, b-M).
            if (isset($seen[strtolower($id)])) {
                throw self::bad("{$f}.line_id", "line {$id} appears twice");
            }
            $seen[strtolower($id)] = true;
            $qty = $l['qty'] ?? null;
            if ($qty !== self::LINE_QTY) {
                throw self::bad("{$f}.qty", "{$f}.qty must be 1 (one shop order line is one unit)");
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
            'method_id' => self::digits(self::optText($d['method_id'] ?? null, 'delivery.method_id', 64, true)),
            'method' => self::serviceName(self::optText($d['method'] ?? null, 'delivery.method', 128)),
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
            // Only the shop's own shapes: a name or any other text there is refused, never kept (it would stay after the parcel is gone).
            if (preg_match(self::PARCEL_REF, $ref) !== 1) {
                throw self::bad("{$f}.parcel_ref", "{$f}.parcel_ref must be odl:<number> or orl:<number>");
            }
            if (isset($seenP[strtolower($ref)])) {
                throw self::bad("{$f}.parcel_ref", "parcel {$ref} appears twice");
            }
            $seenP[strtolower($ref)] = true;
            $carrier = self::word((string) self::optText($p['carrier'] ?? null, "{$f}.carrier", 32), self::CARRIERS);
            $outParcels[] = [
                'parcel_ref' => $ref,
                'tracking_no' => self::tracking(self::optText($p['tracking_no'] ?? null, "{$f}.tracking_no", 64, true), $carrier,
                    self::flag($p['manual'] ?? false, "{$f}.manual")),
                'carrier' => $carrier,
                'service_code' => self::serviceCode(self::optText($p['service_code'] ?? null, "{$f}.service_code", 64, true)),
                'service' => (string) self::serviceName(self::optText($p['service'] ?? null, "{$f}.service", 128)),
                'status' => self::word((string) self::optText($p['status'] ?? null, "{$f}.status", 32), self::PARCEL_STATUSES),
                'label_at' => self::time($p['label_at'] ?? null, "{$f}.label_at", $now, true),
                'shipped_at' => self::time($p['shipped_at'] ?? null, "{$f}.shipped_at", $now, true),
                'manual' => self::flag($p['manual'] ?? false, "{$f}.manual"),
                'partial' => self::flag($p['partial'] ?? false, "{$f}.partial"),
            ];
        }
        return [
            'v' => 1,
            'version' => $version,
            'order_no' => self::orderNo(self::optText($raw['order_no'] ?? null, 'order_no', 64, true)),
            'status' => self::word((string) self::optText($raw['status'] ?? null, 'status', 32), self::ORDER_STATUSES),
            'placed_at' => $placed,
            'paid_at' => $paid,
            'gateway' => self::word((string) self::optText($raw['gateway'] ?? null, 'gateway', 32), self::GATEWAYS),
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
            $v = Reservations::ref($v);
        } catch (CwException) {
            throw self::bad($field, "{$field} must be 1-64 printable characters");
        }
        if (preg_match(self::CODE, $v) !== 1) {
            throw self::bad($field, "{$field} must be a code: letters, digits and : _ . - (at most 64)");
        }
        return $v;
    }

    /**
     * Whether a JSON body holds its lists as lists (`lines`, `parcels`) and its delivery as an object: decoded to PHP arrays, `{}` and `[]`
     * look alike, so `"lines": {}` would read as an order with no lines and take every line off it (the final review of 6d59eda, b-M;
     * PR101). 400 bad_snapshot naming the field.
     */
    public static function checkJsonShapes(string $json): void
    {
        try {
            $o = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return; // the caller's own decode says what is wrong
        }
        if (!$o instanceof \stdClass) {
            return;
        }
        foreach (['lines', 'parcels'] as $k) {
            if (property_exists($o, $k) && !is_array($o->{$k})) {
                throw self::bad($k, "{$k} must be a list");
            }
        }
        if (property_exists($o, 'delivery') && $o->delivery !== null && !$o->delivery instanceof \stdClass) {
            throw self::bad('delivery', 'delivery must be an object or null');
        }
    }

    /** A word of $list (lower case, spaces as "_"), "" when there is none, else "other". */
    private static function word(string $v, array $list): string
    {
        $w = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($v))), '_');
        if ($w === '') {
            return '';
        }
        return in_array($w, $list, true) ? $w : 'other';
    }

    /** A delivery method or service name made only of SERVICE_WORDS and short numbers (else null). */
    public static function serviceName(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return $v;
        }
        $v = (string) preg_replace('/\s+/', ' ', $v);
        // At most 6 digits in all: a phone number written in groups ("0770 0900 123") is never a service name (the Codex check of c48683e).
        if (preg_match("/^[A-Za-z0-9 ()&+,.\/:'-]+$/D", $v) !== 1 || preg_match_all('/[0-9]/', $v) > self::TEXT_DIGITS) {
            return null;
        }
        $anchored = false;
        foreach (preg_split('/[^A-Za-z0-9]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            $t = strtolower($t);
            if (!in_array($t, self::SERVICE_WORDS, true) && preg_match('/^[0-9]{1,4}(st|nd|rd|th|h|hr|hrs)?$/D', $t) !== 1) {
                return null;
            }
            $anchored = $anchored || in_array($t, self::SERVICE_ANCHORS, true);
        }
        // A carrier's or a service's word in it, else it may be an address ("12 Preston"): not kept (PR141).
        return $anchored ? $v : null;
    }

    /** A carrier's service code (letters, digits, _ . / - ^; at most TEXT_DIGITS digits in all: never a phone number), else "". */
    private static function serviceCode(?string $v): string
    {
        return $v !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/^-]{0,31}$/D', $v) === 1 && preg_match_all('/[0-9]/', $v) <= self::TEXT_DIGITS ? $v : '';
    }

    /** An id of digits (a shipping method's number), else null. */
    private static function digits(?string $v): ?string
    {
        return $v !== null && preg_match('/^[0-9]{1,9}$/D', $v) === 1 ? $v : null;
    }

    /** The order number in its shape (ORDER_NO), else "". */
    private static function orderNo(?string $v): string
    {
        return $v !== null && preg_match(self::ORDER_NO, $v) === 1 ? $v : '';
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
        // With its zone (Z or an offset): a time without one is never taken as UTC (a London time would move a sale to the next day: the
        // final review of 6d59eda, b-LOW).
        if (!is_string($v) || preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/D', $v) !== 1) {
            throw self::bad($field, "{$field} must be an ISO-8601 time with its zone");
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
