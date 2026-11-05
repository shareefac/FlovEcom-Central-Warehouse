<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Staff\SecretBox;

/**
 * The keys of the APIs CW calls, entered on Settings › APIs (0063, api_credential; docs/decisions.md AK1-AK12) instead of a server file.
 * One row per API, made by the migration (the app login only UPDATEs a row's columns): today erp_read, the read-only ERPNext user of the
 * invoice copy (CW-917; Invoices\ErpApiReader::fromConfig() prefers it to app.env's erp_read_url / erp_read_key / erp_read_secret).
 *
 * The key and the secret are sealed with Staff\SecretBox (app.env ui_secret_key, the key of the 2-step codes) and are NEVER shown,
 * logged or audited: shown() gives the address, the key's last 4 characters, whether a secret is saved, who saved it and when; open()
 * alone unseals them, for the reader. A save (save()) needs the address and, the first time, the key and the secret; later an empty key
 * or secret keeps the saved one (proved still openable with this server's key: AK6). A removal (remove()) empties the row. Both re-check
 * the person (settings.manage, Admin\ConfigHistory::authorise) and the version the form was drawn with inside their transaction, and
 * write one audit row naming the address and "key ends ABCD" only. A connection check (Ui\Controller\ApisController::check) is audited
 * by recordCheck() the same way.
 */
final class ApiCredentials
{
    public const ERP_READ = 'erp_read';
    /** Every API a row is made for (0063). */
    public const NAMES = [self::ERP_READ];
    /** The address a form starts with while nothing is saved. */
    public const DEFAULT_URL = [self::ERP_READ => 'https://vpg-erp.floverfy.com'];
    /** The longest address (the column is 200; the name part at most 180, ck_api_credential_url). */
    public const URL_MAX = 180;
    /** What ERPNext makes a key and a secret of: letters and digits (Invoices\ErpApiReader checks the same). */
    public const TOKEN = '/^[A-Za-z0-9]{6,64}$/D';
    /**
     * What a connection check may end with: Invoices\ErpApiReader::CHECKS, and the three that stop before anything is sent (no key at all;
     * a saved key this server cannot open; app.env's key or address not written as ErpApiReader takes them).
     */
    public const OUTCOMES = ['ok', 'wrong_key', 'not_allowed', 'unreachable', 'not_public', 'other', 'nothing', 'cannot_open', 'bad_setup'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * What a screen may show of an API's key: never the key or the secret.
     *
     * @return array{name: string, saved: bool, url: ?string, key_last4: ?string, version: int, updated_by: ?int, updated_by_name: ?string,
     *     updated_actor: string, updated_at: string}
     */
    public function shown(string $name): array
    {
        self::known($name);
        $r = $this->db->one('SELECT c.name, c.url, c.key_last4, (c.key_enc IS NOT NULL AND c.secret_enc IS NOT NULL) AS saved, c.version, c.updated_by, '
            . 'u.display_name AS updated_by_name, c.updated_actor, c.updated_at FROM api_credential c LEFT JOIN staff_user u ON u.id = c.updated_by WHERE c.name = ?', [$name])
            ?? throw new CwException('api_unknown', 'there is no API ' . $name, 404);
        return ['name' => (string) $r['name'], 'saved' => (int) $r['saved'] === 1, 'url' => $r['url'] === null ? null : (string) $r['url'],
            'key_last4' => $r['key_last4'] === null ? null : (string) $r['key_last4'], 'version' => (int) $r['version'],
            'updated_by' => $r['updated_by'] === null ? null : (int) $r['updated_by'], 'updated_by_name' => $r['updated_by_name'] === null ? null : (string) $r['updated_by_name'],
            'updated_actor' => (string) $r['updated_actor'], 'updated_at' => (string) $r['updated_at']];
    }

    /**
     * The saved key of an API, unsealed (for the reader only: never shown, logged or audited), or null when nothing is saved (or the table
     * is not there yet: code ahead of 0063 reads app.env as before). 503 api_cannot_open when this server's key does not open it (the key
     * of the 2-step codes was changed since it was saved): it is typed again on Settings › APIs.
     *
     * @return array{url: string, key: string, secret: string}|null
     */
    public static function open(Db $db, string $name, SecretBox $box): ?array
    {
        self::known($name);
        try {
            $r = $db->one('SELECT url, key_enc, secret_enc FROM api_credential WHERE name = ?', [$name]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1146) {
                return null; // no table yet (a schema before 0063)
            }
            throw $e;
        }
        if ($r === null || $r['key_enc'] === null || $r['secret_enc'] === null || $r['url'] === null) {
            return null;
        }
        try {
            return ['url' => (string) $r['url'], 'key' => $box->decrypt((string) $r['key_enc']), 'secret' => $box->decrypt((string) $r['secret_enc'])];
        } catch (\RuntimeException) {
            throw new CwException('api_cannot_open', 'the key saved on Settings > APIs cannot be opened with this server\'s ui_secret_key: type it again there', 503);
        }
    }

    /**
     * Saves an API's address, key and secret. The first save needs all three; later an empty key or secret keeps the saved one. 409
     * api_changed when the row is no longer at $version (someone saved or removed meanwhile: nothing written); 422 api_bad_url,
     * api_bad_key, api_bad_secret, api_need_key, api_need_secret, api_cannot_open (a kept key or secret this server cannot open: type both
     * again); 403 for a person who may not change settings.
     *
     * @return array{result: 'saved'|'unchanged', version: int, key_last4: string}
     */
    public function save(Caller $caller, string $name, int $version, string $url, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $secret,
        SecretBox $box): array
    {
        self::known($name);
        $url = self::url($url);
        $key = trim($key);
        $secret = trim($secret);
        if ($key !== '' && preg_match(self::TOKEN, $key) !== 1) {
            throw new CwException('api_bad_key', 'the API key is 6 to 64 letters and digits, as ERPNext shows it', 422);
        }
        if ($secret !== '' && preg_match(self::TOKEN, $secret) !== 1) {
            throw new CwException('api_bad_secret', 'the API secret is 6 to 64 letters and digits, as ERPNext shows it', 422);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $name, $version, $url, $key, $secret, $box): array {
            ConfigHistory::authorise($db, $caller);
            $row = self::locked($db, $name, $version);
            $saved = $row['key_enc'] !== null;
            if (!$saved && $key === '') {
                throw new CwException('api_need_key', 'type the API key: nothing is saved yet', 422);
            }
            if (!$saved && $secret === '') {
                throw new CwException('api_need_secret', 'type the API secret: nothing is saved yet', 422);
            }
            if ($saved && $key === '' && $secret === '' && $url === $row['url']) {
                return ['result' => 'unchanged', 'version' => $version, 'key_last4' => (string) $row['key_last4']];
            }
            // A kept key or secret must still open with this server's key: a save never leaves a row the reader cannot use (AK6).
            foreach (['key_enc' => $key, 'secret_enc' => $secret] as $col => $typed) {
                if ($typed === '' && !self::opens($box, (string) $row[$col])) {
                    throw new CwException('api_cannot_open', 'the saved key cannot be opened on this server any more: type the key and the secret again', 422);
                }
            }
            $keyEnc = $key === '' ? (string) $row['key_enc'] : $box->encrypt($key);
            $last4 = $key === '' ? (string) $row['key_last4'] : substr($key, -4);
            $secretEnc = $secret === '' ? (string) $row['secret_enc'] : $box->encrypt($secret);
            $db->exec('UPDATE api_credential SET url = ?, key_enc = ?, key_last4 = ?, secret_enc = ?, version = version + 1, updated_by = ?, updated_actor = ?, '
                . 'updated_at = NOW(6) WHERE name = ? AND version = ?', [$url, $keyEnc, $last4, $secretEnc, $caller->staffUserId, $caller->actor, $name, $version]);
            $detail = ['name' => $name, 'url' => $url, 'key_ends' => $last4, 'key' => $key === '' ? 'kept' : 'new', 'secret' => $secret === '' ? 'kept' : 'new',
                'version' => $version + 1];
            if ($row['url'] !== null && $row['url'] !== $url) {
                $detail['url_before'] = (string) $row['url'];
            }
            Audit::write($db, $caller, 'api.save', 'api_credential', $name, null, $detail);
            return ['result' => 'saved', 'version' => $version + 1, 'key_last4' => $last4];
        });
    }

    /**
     * Empties an API's row (the address, the key and the secret; the row stays, with who emptied it and when). 409 api_changed as save();
     * nothing saved: unchanged. The reader then uses app.env again, or stops when app.env has none.
     *
     * @return array{result: 'removed'|'unchanged', version: int}
     */
    public function remove(Caller $caller, string $name, int $version): array
    {
        self::known($name);
        return $this->db->transaction(function (Db $db) use ($caller, $name, $version): array {
            ConfigHistory::authorise($db, $caller);
            $row = self::locked($db, $name, $version);
            if ($row['key_enc'] === null && $row['url'] === null) {
                return ['result' => 'unchanged', 'version' => $version];
            }
            $db->exec('UPDATE api_credential SET url = NULL, key_enc = NULL, key_last4 = NULL, secret_enc = NULL, version = version + 1, updated_by = ?, '
                . 'updated_actor = ?, updated_at = NOW(6) WHERE name = ? AND version = ?', [$caller->staffUserId, $caller->actor, $name, $version]);
            Audit::write($db, $caller, 'api.remove', 'api_credential', $name, null, ['name' => $name, 'url' => $row['url'], 'key_ends' => $row['key_last4'],
                'version' => $version + 1]);
            return ['result' => 'removed', 'version' => $version + 1];
        });
    }

    /**
     * The audit row of a connection check (AK8): which key was checked (saved here, or app.env's), how it ended, ERPNext's HTTP status
     * when it answered with another one, the address and the key's last 4. Never the key, the secret or what ERPNext answered.
     */
    public function recordCheck(Caller $caller, string $name, string $source, string $outcome, ?int $status, ?string $url, ?string $keyLast4): void
    {
        self::known($name);
        if (!in_array($outcome, self::OUTCOMES, true) || !in_array($source, ['saved', 'app_env', 'none'], true)) {
            throw new \InvalidArgumentException('unknown check outcome or source');
        }
        Audit::write($this->db, $caller, 'api.check', 'api_credential', $name, null, array_filter(['name' => $name, 'source' => $source, 'outcome' => $outcome,
            'status' => $status, 'url' => $url, 'key_ends' => $keyLast4], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * The address as it is saved: https://<a name> in lower case, nothing after the name but one "/" (dropped). 422 api_bad_url otherwise:
     * not https; an IP address, a port, a path, a user, a query or a fragment; a name that is not letters, digits, hyphens and dots with at
     * least one dot and a last part of letters (AK4: CW only calls names, and only on the usual port).
     */
    public static function url(string $typed): string
    {
        $bad = new CwException('api_bad_url', 'the address must be https:// and the ERPNext name, nothing else (for example https://vpg-erp.floverfy.com)', 422);
        $t = trim($typed);
        if ($t === '' || strlen($t) > 8 + self::URL_MAX + 1 || preg_match('/[\s\x00-\x1f\x7f\\\\@]/', $t) === 1) {
            throw $bad;
        }
        if (preg_match('#^https://([^/?\#:]+)/?$#iD', $t, $m) !== 1) {
            throw $bad;
        }
        $host = strtolower($m[1]);
        if (strlen($host) > self::URL_MAX || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host) !== 1) {
            throw $bad;
        }
        return 'https://' . $host;
    }

    /** @throws \InvalidArgumentException for an API with no row (a programming error) */
    private static function known(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("unknown API {$name}");
        }
    }

    /**
     * The row, locked, at the version the form was drawn with; 409 api_changed (who changed it and when) otherwise.
     *
     * @return array<string, mixed>
     */
    private static function locked(Db $db, string $name, int $version): array
    {
        $row = $db->one('SELECT c.url, c.key_enc, c.key_last4, c.secret_enc, c.version, c.updated_actor, c.updated_at, u.display_name FROM api_credential c '
            . 'LEFT JOIN staff_user u ON u.id = c.updated_by WHERE c.name = ? FOR UPDATE OF c', [$name]) // the API's row only, never the person's
            ?? throw new CwException('api_unknown', 'there is no API ' . $name, 404);
        if ((int) $row['version'] !== $version) {
            throw new CwException('api_changed', 'this key was saved or removed meanwhile: nothing was saved', 409,
                ['by' => $row['display_name'] === null ? null : (string) $row['display_name'], 'at' => (string) $row['updated_at']]);
        }
        return $row;
    }

    private static function opens(SecretBox $box, string $sealed): bool
    {
        try {
            $box->decrypt($sealed);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
