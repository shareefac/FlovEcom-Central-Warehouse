<?php

declare(strict_types=1);

namespace CW\Api;

use CW\CwException;
use CW\Db;

/**
 * Who is calling (plan §11): `Authorization: Bearer <key>` AND the channel's IP allowlist.
 *
 *  - The presented key is hashed (sha256) and compared with every configured channel's
 *    api_key_hash using hash_equals, without stopping at the first match (a handful of rows).
 *  - A channel whose api_key_hash is NULL never matches; one whose allowed_ips is empty or
 *    does not contain REMOTE_ADDR is refused even with the right key (key AND address, never
 *    OR). Forwarding headers (CF-Connecting-IP, X-Forwarded-For, ...) are ignored.
 *  - 401 for a missing/malformed/unknown key, 403 for a known key from an address that is not
 *    allowed. Neither says more than that; the reason is logged server-side without the key.
 */
final class Auth
{
    /** @param (\Closure(string): void)|null $log */
    public function __construct(private readonly Db $db, private readonly ?\Closure $log = null)
    {
    }

    /**
     * The Bearer token of a request, or 401. Needs no database, so the kernel runs it before
     * connecting (junk traffic never costs a database connection).
     *
     * @param (\Closure(string): void)|null $log
     */
    public static function bearer(Request $req, ?\Closure $log = null): string
    {
        $header = $req->header('authorization');
        if ($header === null || preg_match('/^Bearer +(\S+)\s*$/i', $header, $m) !== 1 || preg_match(ApiKey::TOKEN_PATTERN, $m[1]) !== 1) {
            if ($log !== null) {
                $log('auth: missing or malformed bearer token (ip ' . $req->remoteAddr . ', ' . $req->method . ' ' . $req->path . ')');
            }
            throw self::unauthorized();
        }
        return $m[1];
    }

    public function authenticate(Request $req): ApiChannel
    {
        $presented = ApiKey::hash(self::bearer($req, $this->log));

        $match = null;
        foreach ($this->db->all('SELECT id, code, mode, api_key_hash, allowed_ips FROM channel WHERE api_key_hash IS NOT NULL') as $row) {
            if (hash_equals((string) $row['api_key_hash'], $presented) && $match === null) {
                $match = $row;
            }
        }
        if ($match === null) {
            $this->log('auth: unknown key', $req);
            throw self::unauthorized();
        }

        $ips = json_decode((string) $match['allowed_ips'], true);
        if (!is_array($ips) || !array_is_list($ips) || !IpAllowlist::allows($req->remoteAddr, $ips)) {
            $this->log('auth: channel ' . $match['code'] . ' called from an address not on its allowlist', $req);
            throw new CwException('forbidden', 'this client address is not allowed for the key', 403);
        }
        return new ApiChannel((int) $match['id'], (string) $match['code'], (string) $match['mode']);
    }

    private static function unauthorized(): CwException
    {
        return new CwException('unauthorized', 'a valid API key is required (Authorization: Bearer <key>)', 401);
    }

    private function log(string $message, Request $req): void
    {
        if ($this->log !== null) {
            ($this->log)($message . ' (ip ' . $req->remoteAddr . ', ' . $req->method . ' ' . $req->path . ')');
        }
    }
}
