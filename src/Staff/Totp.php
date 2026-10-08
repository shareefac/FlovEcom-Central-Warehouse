<?php

declare(strict_types=1);

namespace CW\Staff;

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30 s: what every authenticator app reads from an otpauth:// URI)
 * and RFC 4648 base32 for the shared secret. The secret is stored encrypted (SecretBox, staff_user.totp_secret_enc) and is
 * shown once, as the otpauth:// URI: by bin/create_staff.php / bin/reset_staff.php on the server's terminal, or as a QR code on
 * a sheet an admin hands over (StaffAdmin::enrol / newSheet / resetAuthenticator). A sheet's secret is never the person's for
 * good (staff_user.totp_state `signup` / `reset`): it works once, and the person's OWN page then shows a fresh secret that only
 * they see, confirmed with one code before any session (Staff\Enrolment; docs/decisions.md Y40-Y43). verify() refuses a step at
 * or below the last one accepted (totp_last_step), so a code works once.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const SECRET_BYTES = 20;
    public const ISSUER = 'CW Warehouse';
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32 (32 characters for 20 bytes). */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public static function uri(string $secret, string $account, string $issuer = self::ISSUER): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** The code for time step $step (default: now). */
    public static function code(string $secret, ?int $step = null): string
    {
        $step ??= intdiv(time(), self::PERIOD);
        $mac = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $o = ord($mac[19]) & 0x0f;
        $n = ((ord($mac[$o]) & 0x7f) << 24) | (ord($mac[$o + 1]) << 16) | (ord($mac[$o + 2]) << 8) | ord($mac[$o + 3]);
        return str_pad((string) ($n % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code belongs to (now ± $window steps), or null. A step at or below
     * $lastStep is refused (staff_user.totp_last_step: a code is used once).
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, int $window = 1, ?int $now = null): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $cur = intdiv($now ?? time(), self::PERIOD);
        for ($s = $cur - $window; $s <= $cur + $window; $s++) {
            if (($lastStep === null || $s > $lastStep) && hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }
        return null;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim(str_replace(' ', '', $b32), '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) {
                throw new \InvalidArgumentException('not base32');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
