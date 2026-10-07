<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Barcode handling. Port of product_mapping::norm_barcode (:96-109) plus a GTIN check digit
 * and per-channel usability.
 *
 * - key():      comparison key, digits only, leading zeros stripped (so "06943498697557" == 6943498697557;
 *               the export writes most codes as JSON integers, which already lost their leading zeros).
 * - classify(): shape + check digit. A code is USABLE only when its key is 8..14 digits and the
 *               zero-padded GTIN-14 has a valid mod-10 check digit. Short internal codes ("1746",
 *               "210102") and codes with a bad check digit are never used to link anything.
 * - index():    key -> item ids for one channel; a key on more than one item of the channel is
 *               not usable there (VPG has 4 such keys; plan §7.2).
 */
final class Gtin
{
    public static function key(mixed $raw): ?string
    {
        if ($raw === null || is_bool($raw) || is_array($raw)) {
            return null;
        }
        if (is_float($raw)) {
            $raw = sprintf('%.0f', $raw);
        }
        $d = preg_replace('/[^0-9]/', '', (string) $raw) ?? '';
        $key = ltrim($d, '0');
        if ($key === '' || strlen($key) > 32) {
            return null;
        }
        return $key;
    }

    /** Mod-10 check digit validation on the zero-padded GTIN-14. */
    public static function checkDigitOk(string $digits): bool
    {
        if ($digits === '' || !ctype_digit($digits) || strlen($digits) > 14) {
            return false;
        }
        $g = str_pad($digits, 14, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $g[$i] * (($i % 2 === 0) ? 3 : 1);
        }
        return ((10 - ($sum % 10)) % 10) === (int) $g[13];
    }

    /**
     * The GTIN check digit (mod 10, weights 3 and 1 from the right) of a code WITHOUT its last digit: 1 to 13 digits
     * ("501234567890" -> 0 for the EAN-13 5012345678900). Used to tell people what the last digit should have been.
     */
    public static function checkDigit(string $body): int
    {
        if ($body === '' || !ctype_digit($body) || strlen($body) > 13) {
            throw new \InvalidArgumentException('a GTIN body is 1 to 13 digits');
        }
        $sum = 0;
        $n = strlen($body);
        for ($i = 0; $i < $n; $i++) {
            // the digit next to the check digit weighs 3, the next 1, and so on
            $sum += (int) $body[$n - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10;
    }

    /**
     * @return array{raw:string,key:?string,gtin14:?string,usable:bool,reason:string}
     */
    public static function classify(mixed $raw): array
    {
        $rawS = is_scalar($raw) ? (string) $raw : '';
        $key = self::key($raw);
        if ($key === null) {
            return ['raw' => $rawS, 'key' => null, 'gtin14' => null, 'usable' => false, 'reason' => 'empty'];
        }
        if (strlen($key) < 8) {
            return ['raw' => $rawS, 'key' => $key, 'gtin14' => null, 'usable' => false, 'reason' => 'too_short'];
        }
        if (strlen($key) > 14) {
            return ['raw' => $rawS, 'key' => $key, 'gtin14' => null, 'usable' => false, 'reason' => 'too_long'];
        }
        $g14 = str_pad($key, 14, '0', STR_PAD_LEFT);
        if (!self::checkDigitOk($key)) {
            return ['raw' => $rawS, 'key' => $key, 'gtin14' => $g14, 'usable' => false, 'reason' => 'bad_check_digit'];
        }
        return ['raw' => $rawS, 'key' => $key, 'gtin14' => $g14, 'usable' => true, 'reason' => 'ok'];
    }

    /**
     * Usable keys of one listing (deduplicated, stable order).
     *
     * @param list<mixed> $barcodes
     * @return array{usable:list<string>,unusable:list<array{raw:string,reason:string}>}
     */
    public static function listingKeys(array $barcodes): array
    {
        $usable = [];
        $bad = [];
        foreach ($barcodes as $b) {
            $c = self::classify($b);
            if ($c['usable']) {
                $usable[$c['key']] = true;
            } elseif ($c['reason'] !== 'empty') {
                $bad[] = ['raw' => $c['raw'], 'reason' => $c['reason']];
            }
        }
        return ['usable' => array_keys($usable), 'unusable' => $bad];
    }

    /**
     * Build a channel index. $items maps item id => list of raw barcodes.
     *
     * @param iterable<int|string, list<mixed>> $items
     * @return array{by_key: array<string, list<int|string>>, multi: array<string, true>}
     */
    public static function index(iterable $items): array
    {
        $by = [];
        foreach ($items as $id => $codes) {
            foreach (self::listingKeys($codes)['usable'] as $k) {
                $by[$k][$id] = true;
            }
        }
        $out = ['by_key' => [], 'multi' => []];
        foreach ($by as $k => $ids) {
            $out['by_key'][(string) $k] = array_keys($ids);
            if (count($ids) > 1) {
                $out['multi'][(string) $k] = true;
            }
        }
        return $out;
    }
}
