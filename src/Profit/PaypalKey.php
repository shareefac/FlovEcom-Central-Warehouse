<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * A PayPal account's REST app key, read from its key file on the CW server (docs/decisions.md PPF11; the owner, 24 Nov 2026: CW uses the
 * shop's existing key). The file's path is app.env's `paypal_key_file_<account>` (or CW_PAYPAL_KEY_FILE_<ACCOUNT>); it is a JSON object of
 * exactly client_id, client_secret, api_base, shop (the account's main shop: PaypalFees::run refuses a key whose shop is not the account's
 * main shop), account and merchant_id (PayPal's account number of the merchant: every page PayPal gives must name it, PaypalApi; the
 * lead's decision 4, fix round 1), owned by root and readable by root ALONE (root:root 0600 as the go-live kit places it; 0400 passes too:
 * the Fable final review F-M1, round 4: no group may read it, so nothing the web app runs as can; only the nightly job, which runs as root,
 * reads it). It is never in git, never printed, never logged: a refusal names what is wrong with the file, never what it holds. CW calls only
 * POST /v1/oauth2/token and GET /v1/reporting/transactions with it (PaypalApi::PATHS).
 */
final class PaypalKey
{
    /** PayPal's REST hosts (live and sandbox): any other api_base is refused. */
    public const HOSTS = ['https://api-m.paypal.com', 'https://api.paypal.com', 'https://api-m.sandbox.paypal.com', 'https://api.sandbox.paypal.com'];
    public const FIELDS = ['client_id', 'client_secret', 'api_base', 'shop', 'account', 'merchant_id'];
    public const MAX_BYTES = 4096;

    private function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter] private readonly string $secret,
        public readonly string $apiBase,
        public readonly string $shop,
        public readonly string $account,
        public readonly string $merchantId,
    ) {
    }

    /** The app.env key of an account's key file. */
    public static function configKey(string $account): string
    {
        return 'paypal_key_file_' . $account;
    }

    /**
     * The key of $account from its file. Refused (403 paypal_key_refused) when the file is missing, not a plain file, has another owner than
     * root or a mode anyone but root can read (or anyone run), is not the JSON above, or is for another account.
     */
    public static function load(?string $path, string $account): self
    {
        $no = static fn (string $why): CwException => new CwException('paypal_key_refused', "the PayPal key file of {$account} {$why}: nothing was read", 403,
            ['account' => $account]);
        if ($path === null || $path === '' || $path[0] !== '/') {
            throw $no('is not set (app.env ' . self::configKey($account) . ': a full path)');
        }
        clearstatcache(true, $path);
        $st = @lstat($path);
        if ($st === false) {
            throw $no('is missing');
        }
        if (($st['mode'] & 0170000) !== 0100000) {
            throw $no('is not a plain file (a link or a folder is refused)');
        }
        // the checks are made on the file actually opened (fstat of its descriptor, the same inode as the path's: no swap in between)
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw $no('cannot be read');
        }
        try {
            $fs = fstat($h);
            if ($fs === false || (int) $fs['ino'] !== (int) $st['ino'] || (int) $fs['dev'] !== (int) $st['dev'] || ($fs['mode'] & 0170000) !== 0100000) {
                throw $no('changed while it was opened');
            }
            if ((int) $fs['uid'] !== 0) {
                throw $no('is not owned by root');
            }
            if (($fs['mode'] & 0177) !== 0) {
                throw $no(sprintf('has mode %04o: only root may read it (0600; no group, no others, nobody may run it)', $fs['mode'] & 07777));
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
     * A key from its fields (the file's, or a test's), checked the same way.
     *
     * @param array<mixed> $j
     */
    public static function fromArray(#[\SensitiveParameter] array $j, string $account): self
    {
        $no = static fn (string $why): CwException => new CwException('paypal_key_refused', "the PayPal key file of {$account} {$why}: nothing was read", 403,
            ['account' => $account]);
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
        if (preg_match('/^[A-Za-z0-9_.-]{10,200}$/D', $j['client_id']) !== 1 || preg_match('/^[A-Za-z0-9_.-]{10,200}$/D', $j['client_secret']) !== 1) {
            throw $no('has a client id or secret that is not in the form PayPal uses');
        }
        if (!in_array($j['api_base'], self::HOSTS, true)) {
            throw $no('names an api_base that is not PayPal\'s (' . implode(', ', self::HOSTS) . ')');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $j['account']) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $j['shop']) !== 1) {
            throw $no('names an account or a shop that is not a short lower-case name');
        }
        if ($j['account'] !== $account) {
            throw $no('is for another account');
        }
        if (preg_match('/^[A-Z0-9]{8,20}$/D', $j['merchant_id']) !== 1) {
            throw $no('names a merchant_id that is not PayPal\'s account number form');
        }
        return new self($j['client_id'], $j['client_secret'], $j['api_base'], $j['shop'], $j['account'], $j['merchant_id']);
    }

    /** Whether the key is for PayPal's live host (production must use one: the go-live kit checks it). */
    public function live(): bool
    {
        return in_array($this->apiBase, ['https://api-m.paypal.com', 'https://api.paypal.com'], true);
    }

    /** The Basic credentials of the token call (never printed). */
    public function basic(): string
    {
        return base64_encode($this->clientId . ':' . $this->secret);
    }

    /** @return array<string, string> what a dump of this object shows: never the secret */
    public function __debugInfo(): array
    {
        return ['shop' => $this->shop, 'account' => $this->account, 'api_base' => $this->apiBase, 'merchant_id' => $this->merchantId];
    }
}
