<?php

declare(strict_types=1);

namespace CW\Api;

/**
 * The per-channel IP allowlist (plan §11): entries are single addresses or CIDR blocks, IPv4 or
 * IPv6 (`203.0.113.7`, `198.51.100.0/24`, `2001:db8::/48`). Checked against REMOTE_ADDR only.
 * Fail closed: an empty list, a malformed entry and a /0 block match nothing. An IPv4-mapped
 * IPv6 client address (::ffff:a.b.c.d) is compared as IPv4.
 */
final class IpAllowlist
{
    /** @param list<mixed> $entries */
    public static function allows(string $ip, array $entries): bool
    {
        $addr = self::address($ip);
        if ($addr === null) {
            return false;
        }
        foreach ($entries as $entry) {
            if (is_string($entry) && self::matches($addr, $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function isValidEntry(string $entry): bool
    {
        return self::block($entry) !== null;
    }

    private static function matches(string $addr, string $entry): bool
    {
        $block = self::block($entry);
        if ($block === null || strlen($block[0]) !== strlen($addr)) {
            return false;
        }
        [$net, $bits] = $block;
        $bytes = intdiv($bits, 8);
        if (substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($addr[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /** @return array{0: string, 1: int}|null packed network address + prefix length */
    private static function block(string $entry): ?array
    {
        $entry = trim($entry);
        $parts = explode('/', $entry);
        if (count($parts) > 2) {
            return null;
        }
        $addr = self::address($parts[0]);
        if ($addr === null) {
            return null;
        }
        $max = strlen($addr) * 8;
        if (!isset($parts[1])) {
            return [$addr, $max];
        }
        if (!ctype_digit($parts[1]) || strlen($parts[1]) > 3) {
            return null;
        }
        $bits = (int) $parts[1];
        if ($bits < 1 || $bits > $max) {
            return null; // /0 would allow everyone: refused
        }
        return [$addr, $bits];
    }

    private static function address(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }
        return $packed;
    }
}
