<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\Config;
use CW\CwException;
use CW\Db;

/**
 * The write-only key page's hand-over (docs/decisions.md PGL6; the owner, 26 Nov 2026, Q3: he creates the two Parcel2Go API clients himself and
 * pastes each into CW, never into a chat). The web app (www-data) may never read a key: it only DROPS what was pasted into its own folder
 * (/var/lib/cw/p2g_key_drop, www-data 0700; app.env `p2g_key_drop_dir`), one file per account (0600, written whole and renamed into place),
 * and audits the drop with the sha256 of a random nonce the file carries (never the key). The root cron job (bin/p2g_pull.php) TAKES a drop
 * at its next run: only a plain file of the folder's owner, mode 0600, at most 4 KB, whose nonce a drop audited in the last 24 hours names;
 * it is checked as a key (P2gKey) and written into the key file (root:root 0600, written whole and renamed into place), then the drop is
 * removed, audited (p2g.key_set). Anything else is removed and audited as refused. The page shows only whether a key was set, when and by whom.
 */
final class P2gKeyDrop
{
    public const DEFAULT_DIR = '/var/lib/cw/p2g_key_drop';
    public const MAX_BYTES = 4096;
    public const MAX_AGE_HOURS = 24;

    public static function dir(?Config $config = null): string
    {
        $d = ($config ?? Config::load())->get('p2g_key_drop_dir');
        return is_string($d) && $d !== '' ? $d : self::DEFAULT_DIR;
    }

    public static function keyDir(?Config $config = null): string
    {
        $d = ($config ?? Config::load())->get('p2g_key_dir');
        return is_string($d) && $d !== '' ? $d : P2gKey::DEFAULT_DIR;
    }

    /** The drop's file of an account. */
    public static function file(string $dir, string $account): string
    {
        if (!in_array($account, P2gKey::ACCOUNTS, true)) {
            throw new CwException('not_found', 'there is no such Parcel2Go account in CW', 404, ['field' => 'account']);
        }
        return rtrim($dir, '/') . '/' . $account . '.drop.json';
    }

    /**
     * The key page's Save: the pasted Client ID and secret checked as a key and dropped for the job (a person of the Delivery bills job only:
     * staff 1 and 2). Nothing of the key is ever written anywhere else, shown back or logged.
     */
    public static function drop(Db $db, Caller $caller, string $dir, string $account, string $clientId, #[\SensitiveParameter] string $secret): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'only a person pastes a Parcel2Go key', 403);
        }
        (new DeliveryBills($db))->authoriseWrite($caller);
        $key = P2gKey::fromArray(['client_id' => trim($clientId), 'client_secret' => trim($secret), 'account' => $account, 'api_base' => 'https://www.parcel2go.com'], $account);
        clearstatcache(true, $dir);
        $st = @lstat($dir);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 || (function_exists('posix_geteuid') && (int) $st['uid'] !== posix_geteuid())) {
            throw new CwException('p2g_drop_dir', 'CW\'s folder for a new Parcel2Go key is not ready (it is made by the go-live kit): tell the CW team. Nothing was kept', 503);
        }
        $nonce = bin2hex(random_bytes(16));
        $json = json_encode(['key' => json_decode($key->json(), true, 4, JSON_THROW_ON_ERROR), 'nonce' => $nonce], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = tempnam($dir, '.drop');
        if ($tmp === false) {
            throw new CwException('p2g_drop_dir', 'the new Parcel2Go key could not be kept: tell the CW team', 503);
        }
        try {
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, self::file($dir, $account))) {
                throw new CwException('p2g_drop_dir', 'the new Parcel2Go key could not be kept: tell the CW team', 503);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        Audit::write($db, $caller, 'p2g.key_drop', 'carrier_account', $account, null, ['account' => $account, 'nonce_sha256' => hash('sha256', $nonce)]);
    }

    /**
     * The job's take (root): the account's drop, if any, written into its key file and removed (see the class). True when a key was set.
     */
    public static function take(Db $db, string $dropDir, string $keyDir, string $account, ?\DateTimeImmutable $now = null): bool
    {
        $file = self::file($dropDir, $account);
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false) {
            return false;
        }
        $refuse = static function (string $why) use ($db, $file, $account): never {
            @unlink($file);
            Audit::write($db, Caller::system('p2g_pull'), 'p2g.key_refused', 'carrier_account', $account, null, ['account' => $account, 'why' => $why]);
            throw new CwException('p2g_key_refused', "the Parcel2Go key pasted for {$account} was not taken ({$why}): paste it again", 403);
        };
        $dirSt = @lstat($dropDir);
        if ($dirSt === false || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 07777) !== 0600 || (int) $st['uid'] !== (int) $dirSt['uid'] || (int) $st['size'] > self::MAX_BYTES) {
            $refuse('the drop is not a plain file of 0600 by the web app');
        }
        $raw = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        try {
            $j = json_decode(is_string($raw) ? $raw : '', true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $refuse('the drop is not JSON');
        }
        $nonce = is_array($j) && is_string($j['nonce'] ?? null) && preg_match('/^[0-9a-f]{32}$/D', $j['nonce']) === 1 ? $j['nonce'] : null;
        if ($nonce === null || !is_array($j['key'] ?? null) || array_keys($j) !== ['key', 'nonce']) {
            $refuse('the drop is not in its form');
        }
        $since = Clock::db(($now ?? Clock::now())->modify('-' . self::MAX_AGE_HOURS . ' hours'));
        $seen = false;
        foreach ($db->column("SELECT detail FROM audit_log WHERE action = 'p2g.key_drop' AND entity_id = ? AND created_at >= ?", [$account, $since]) as $d) {
            $x = json_decode((string) $d, true);
            if (is_array($x) && hash_equals((string) ($x['nonce_sha256'] ?? ''), hash('sha256', $nonce))) {
                $seen = true;
            }
        }
        if (!$seen) {
            $refuse('no paste of the key page in the last ' . self::MAX_AGE_HOURS . ' hours names it');
        }
        try {
            $key = P2gKey::fromArray($j['key'], $account);
        } catch (CwException) {
            $refuse('it is not a Parcel2Go key');
        }
        $tmp = tempnam($keyDir, '.p2gkey');
        if ($tmp === false) {
            throw new CwException('p2g_key_refused', 'the Parcel2Go key could not be written (its folder): tell the CW team', 503);
        }
        try {
            $json = $key->json();
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, P2gKey::path($keyDir, $account))) {
                throw new CwException('p2g_key_refused', 'the Parcel2Go key could not be written: tell the CW team', 503);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        @unlink($file);
        Audit::write($db, Caller::system('p2g_pull'), 'p2g.key_set', 'carrier_account', $account, null, ['account' => $account]);
        return true;
    }

    /**
     * What the key page shows of an account (never the key): the newest key set (when, from whose paste), a drop waiting to be taken.
     *
     * @return array{set_at: ?string, set_by: ?string, waiting: bool, refused_at: ?string}
     */
    public static function state(Db $db, string $dropDir, string $account): array
    {
        $set = $db->one("SELECT created_at FROM audit_log WHERE action = 'p2g.key_set' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$account]);
        $by = $set === null ? null : $db->one("SELECT a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id WHERE a.action = 'p2g.key_drop' "
            . 'AND a.entity_id = ? AND a.created_at <= ? ORDER BY a.id DESC LIMIT 1', [$account, $set['created_at']]);
        $ref = $db->value("SELECT created_at FROM audit_log WHERE action = 'p2g.key_refused' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$account]);
        return ['set_at' => $set === null ? null : (string) $set['created_at'], 'set_by' => $by === null ? null : (string) ($by['display_name'] ?? ''),
            'waiting' => is_file(self::file($dropDir, $account)), 'refused_at' => $ref === null ? null : (string) $ref];
    }
}
