<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * A Parcel2Go account's API client (Client Credentials), read from its key file on the CW server (docs/decisions.md PGL6; the owner, 26 Nov
 * 2026, Q3: he creates one client per account himself and pastes it into CW's write-only key page, P2gKeyDrop). The file is
 * /etc/cw/<account>.key.json (app.env `p2g_key_dir` changes the folder), root:root with mode 0600 or 0400 exactly (only the root cron job
 * reads it: the web app never can), a JSON object of exactly client_id, client_secret, account (p2g_vapeandgo, p2g_vapebig) and api_base
 * (https://www.parcel2go.com; the sandbox's host only for a test). Never in git, never printed, never logged: a refusal names what is wrong
 * with the file, never what it holds. The client can create and pay orders at Parcel2Go (there is no read-only client): CW asks only for a
 * `public-api` token (it cannot pay) and P2gApi sends only its listed GET calls.
 */
final class P2gKey
{
    public const HOSTS = ['https://www.parcel2go.com', 'https://sandbox.parcel2go.com'];
    public const FIELDS = ['client_id', 'client_secret', 'account', 'api_base'];
    public const ACCOUNTS = ['p2g_vapeandgo', 'p2g_vapebig'];
    public const DEFAULT_DIR = '/etc/cw';
    public const MAX_BYTES = 4096;

    private function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter] private readonly string $secret,
        public readonly string $account,
        public readonly string $apiBase,
    ) {
    }

    /** The key file of an account in $dir. */
    public static function path(string $dir, string $account): string
    {
        if (!in_array($account, self::ACCOUNTS, true)) {
            throw new CwException('p2g_key_refused', 'there is no such Parcel2Go account in CW', 403, ['account' => $account]);
        }
        return rtrim($dir, '/') . '/' . $account . '.key.json';
    }

    /**
     * The key of $account from its file. Refused (403 p2g_key_refused) when the file is missing, not a plain file, not root:root, of a mode
     * other than 0600 or 0400, too large, not the JSON above, or of another account.
     */
    public static function load(string $path, string $account): self
    {
        $no = static fn (string $why): CwException => new CwException('p2g_key_refused', "the Parcel2Go key file of {$account} {$why}: nothing was read", 403,
            ['account' => $account]);
        if ($path === '' || $path[0] !== '/') {
            throw $no('is not a full path');
        }
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false) {
            throw $no('is missing');
        }
        if (($st['mode'] & 0170000) !== 0100000) {
            throw $no('is not a plain file (a link or a folder is refused)');
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw $no('cannot be read');
        }
        try {
            $fs = fstat($h);
            if ($fs === false || (int) $fs['ino'] !== (int) $st['ino'] || (int) $fs['dev'] !== (int) $st['dev'] || ($fs['mode'] & 0170000) !== 0100000) {
                throw $no('changed while it was opened');
            }
            if ((int) $fs['uid'] !== 0 || (int) $fs['gid'] !== 0) {
                throw $no('is not root:root');
            }
            $mode = $fs['mode'] & 07777;
            if ($mode !== 0600 && $mode !== 0400) {
                throw $no(sprintf('has mode %04o: only root may read it (0600 or 0400 exactly)', $mode));
            }
            if ((int) $fs['size'] > self::MAX_BYTES) {
                throw $no('is too large to be a key file');
            }
            $raw = stream_get_contents($h, self::MAX_BYTES + 1);
        } finally {
            fclose($h);
        }
        if (!is_string($raw)) {
            throw $no('cannot be read');
        }
        try {
            $j = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $no('is not JSON');
        }
        return self::fromArray(is_array($j) ? $j : [], $account);
    }

    /**
     * A key from its fields (the file's, the key page's, a test's), checked the same way.
     *
     * @param array<mixed> $j
     */
    public static function fromArray(#[\SensitiveParameter] array $j, string $account): self
    {
        $no = static fn (string $why): CwException => new CwException('p2g_key_refused', "the Parcel2Go key of {$account} {$why}: nothing was read", 403,
            ['account' => $account]);
        if (!in_array($account, self::ACCOUNTS, true)) {
            throw $no('is for no Parcel2Go account CW has');
        }
        $keys = array_keys($j);
        sort($keys);
        $want = self::FIELDS;
        sort($want);
        if ($keys !== $want) {
            throw $no('must hold exactly ' . implode(', ', self::FIELDS));
        }
        foreach (self::FIELDS as $f) {
            if (!is_string($j[$f]) || $j[$f] === '') {
                throw $no("has no {$f}");
            }
        }
        if (!self::validClientId($j['client_id'])) {
            throw $no('has a Client ID that is not in the form Parcel2Go shows it');
        }
        if (!self::validSecret($j['client_secret'])) {
            throw $no('has a secret that is not 8 to 200 characters without spaces');
        }
        if (!in_array($j['api_base'], self::HOSTS, true)) {
            throw $no('names an api_base that is not Parcel2Go\'s (' . implode(', ', self::HOSTS) . ')');
        }
        if ($j['account'] !== $account) {
            throw $no('is for another account');
        }
        return new self($j['client_id'], $j['client_secret'], $j['account'], $j['api_base']);
    }

    /** A Client ID as Parcel2Go shows it on My Account > API Clients: letters, digits and . _ : - (3 to 200). */
    public static function validClientId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{3,200}$/D', $id) === 1;
    }

    /** The secret the owner chose: 8 to 200 printable characters, no space (Parcel2Go keeps only its hash). */
    public static function validSecret(#[\SensitiveParameter] string $secret): bool
    {
        return preg_match('/^[\x21-\x7E]{8,200}$/D', $secret) === 1;
    }

    /** Whether the key is for Parcel2Go's live host (production must use one). */
    public function live(): bool
    {
        return $this->apiBase === 'https://www.parcel2go.com';
    }

    /** The token request's form body (never printed). */
    public function tokenForm(string $scope): string
    {
        return http_build_query(['grant_type' => 'client_credentials', 'scope' => $scope, 'client_id' => $this->clientId, 'client_secret' => $this->secret],
            '', '&', PHP_QUERY_RFC3986);
    }

    /** The key file's JSON (P2gKeyDrop writes it; never printed). */
    public function json(): string
    {
        return json_encode(['client_id' => $this->clientId, 'client_secret' => $this->secret, 'account' => $this->account, 'api_base' => $this->apiBase],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> what a dump of this object shows: never the secret */
    public function __debugInfo(): array
    {
        return ['account' => $this->account, 'api_base' => $this->apiBase];
    }
}
