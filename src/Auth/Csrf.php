<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Staff\SecretBox;

/**
 * CSRF tokens for the /ui forms: HMAC-SHA256 under a key derived from app.env `ui_secret_key`
 * (never the key itself), bound to what identifies the form's owner:
 *  - a signed-in form: the staff_session id, so a token is worthless in any other session and dies
 *    with the session (login rotates it);
 *  - the login form (no session yet): the random `cw_pre` cookie the login page sets.
 * No database state; compared in constant time. SameSite=Strict cookies and the Origin check in
 * Ui\Kernel are additional layers, not substitutes.
 */
final class Csrf
{
    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    /** @throws \CW\ConfigException when the key is not 32 bytes of base64 */
    public static function fromSecretKey(#[\SensitiveParameter] string $b64): self
    {
        SecretBox::fromBase64($b64); // validates length and encoding
        $raw = (string) base64_decode(trim($b64), true);
        return new self(hash_hmac('sha256', 'cw-ui-csrf-v1', $raw, true));
    }

    public function forSession(string $sessionId): string
    {
        return hash_hmac('sha256', 's:' . $sessionId, $this->key);
    }

    public function forPre(string $preCookie): string
    {
        return hash_hmac('sha256', 'p:' . $preCookie, $this->key);
    }

    public function validForSession(string $sessionId, ?string $token): bool
    {
        return $token !== null && hash_equals($this->forSession($sessionId), $token);
    }

    public function validForPre(?string $preCookie, ?string $token): bool
    {
        return $preCookie !== null && $preCookie !== '' && $token !== null && hash_equals($this->forPre($preCookie), $token);
    }
}
