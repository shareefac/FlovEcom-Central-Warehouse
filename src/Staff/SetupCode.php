<?php

declare(strict_types=1);

namespace CW\Staff;

/**
 * The one-time set-up code printed on a sign-up sheet (review finding I5, docs/decisions.md Y42): /ui/enrol needs it together with
 * the e-mail and the 6 numbers of the code app, so a set-up window cannot be guessed open with the e-mail alone. 15 characters of
 * Crockford's base32 (0-9 and A-Z without I, L, O, U: nothing that reads like another character), 75 bits, printed in three groups
 * of five ("7KQ2M-X9D4H-RT3WP"). Only its sha256 is stored (staff_user.setup_code_hash); it works once.
 *
 * What a person types is read kindly: case, spaces and dashes do not matter, and O, I and L are read as 0, 1 and 1.
 */
final class SetupCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const LENGTH = 15;
    public const GROUP = 5;

    /** A new code, grouped for printing. */
    public static function new(): string
    {
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }
        return implode('-', str_split($out, self::GROUP));
    }

    /** The code as stored: sha256 hex of its normalised form; null when the text cannot be a code. */
    public static function hash(string $typed): ?string
    {
        $n = self::normalise($typed);
        return $n === null ? null : hash('sha256', $n);
    }

    /** The 15 characters a typed code stands for, or null. */
    public static function normalise(string $typed): ?string
    {
        if (strlen($typed) > 64) {
            return null;
        }
        $t = strtr(strtoupper((string) preg_replace('/[\s-]+/', '', $typed)), ['O' => '0', 'I' => '1', 'L' => '1']);
        return strlen($t) === self::LENGTH && strspn($t, self::ALPHABET) === self::LENGTH ? $t : null;
    }
}
