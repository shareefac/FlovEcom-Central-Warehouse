<?php

declare(strict_types=1);

namespace CW\Api;

/**
 * Site API keys (plan §11): 256 random bits, shown once, stored only as sha256 hex in
 * channel.api_key_hash and compared with hash_equals.
 */
final class ApiKey
{
    public const PREFIX = 'cwk_';
    /** What a Bearer token may look like at all (anything else is refused before hashing). */
    public const TOKEN_PATTERN = '/^[\x21-\x7e]{16,256}$/';

    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    public static function hash(#[\SensitiveParameter] string $key): string
    {
        return hash('sha256', $key);
    }
}
