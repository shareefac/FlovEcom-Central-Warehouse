<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\ConfigException;

/**
 * Symmetric encryption of staff secrets at rest (the TOTP secret, staff_user.totp_secret_enc) with
 * libsodium secretbox (XSalsa20-Poly1305). The key is `ui_secret_key` in /etc/cw/app.env: 32 random
 * bytes, base64 — outside the web root and git (plan §11), never printed or logged.
 * Stored form: nonce (24 bytes) || box.
 */
final class SecretBox
{
    public const KEY_NAME = 'ui_secret_key';

    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    public static function fromBase64(#[\SensitiveParameter] string $b64): self
    {
        $key = base64_decode(trim($b64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ConfigException(self::KEY_NAME . ' must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes, base64');
        }
        return new self($key);
    }

    /** A new random key, base64 (what bin/create_staff.php writes to app.env when it is missing). */
    public static function newKeyBase64(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
    }

    public function decrypt(string $stored): string
    {
        if (strlen($stored) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('encrypted secret is too short');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($stored, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($stored, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new \RuntimeException('encrypted secret does not open with this key');
        }
        return $plain;
    }
}
