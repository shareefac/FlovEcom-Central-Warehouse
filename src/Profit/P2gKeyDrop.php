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
 * pastes each into CW, never into a chat). The web app (www-data) may never read a key, not even the one it was just given (the final review,
 * H2 / F12): the paste is SEALED to root (libsodium sealed box, sodium_crypto_box_seal) with the public half of a pair the go-live kit's apply
 * makes once (keypair(): the private half root:root 0600 /etc/cw/p2g_drop.secret, the public half 0644 /etc/cw/p2g_drop.pub; app.env
 * `p2g_drop_secret` / `p2g_drop_public`) and dropped into the web app's own folder (/var/lib/cw/p2g_key_drop, www-data 0700; app.env
 * `p2g_key_drop_dir`), one file per account (0600, written whole and renamed into place). The paste is audited (p2g.key_drop) with the sha256
 * of the sealed file and of a random nonce inside it (never the key): that audit row is its authorisation, bound to the account and to those
 * exact bytes. The root cron job (bin/p2g_pull.php) TAKES a drop at its next run: only a plain file of the folder's owner, mode 0600, at most
 * 4 KB, whose sealed bytes a paste of THIS account audited in the last 24 hours, never taken or refused before (each paste is used once), that
 * opens with root's private half, names the same nonce and is a key of this account (P2gKey); then a token is asked for with it ($prove):
 * only when that works is it written into the key file (root:root 0600, the audit p2g.key_set written first, then the file renamed into
 * place), and the drop removed. Anything else is removed and audited as refused (the old key stays). The page shows only whether a key was
 * set, when and by whom.
 */
final class P2gKeyDrop
{
    public const DEFAULT_DIR = '/var/lib/cw/p2g_key_drop';
    public const DEFAULT_SECRET = '/etc/cw/p2g_drop.secret';
    public const DEFAULT_PUBLIC = '/etc/cw/p2g_drop.pub';
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

    /** The sealing pair's public half (the web app reads it). */
    public static function publicPath(?Config $config = null): string
    {
        $d = ($config ?? Config::load())->get('p2g_drop_public');
        return is_string($d) && $d !== '' ? $d : self::DEFAULT_PUBLIC;
    }

    /** The sealing pair's private half (root's alone). */
    public static function secretPath(?Config $config = null): string
    {
        $d = ($config ?? Config::load())->get('p2g_drop_secret');
        return is_string($d) && $d !== '' ? $d : self::DEFAULT_SECRET;
    }

    /**
     * The sealing pair made (the go-live kit's apply, as root): the private half 0600, the public half 0644, each written whole and renamed into
     * place. A pair that is there is kept (a paste sealed to it can still be taken): false; one half without the other is refused.
     */
    public static function keypair(string $secretPath, string $publicPath): bool
    {
        clearstatcache();
        $have = [is_file($secretPath), is_file($publicPath)];
        if ($have === [true, true]) {
            return false;
        }
        if ($have !== [false, false]) {
            throw new CwException('p2g_drop_pair', 'only one half of the Parcel2Go key page\'s sealing pair is there: tell the CW team', 503);
        }
        $pair = sodium_crypto_box_keypair();
        $put = static function (string $path, string $bytes, int $mode): void {
            $tmp = tempnam(dirname($path), '.p2gpair');
            if ($tmp === false) {
                throw new CwException('p2g_drop_pair', 'the sealing pair could not be written: tell the CW team', 503);
            }
            try {
                if (!chmod($tmp, $mode) || file_put_contents($tmp, $bytes) !== strlen($bytes) || !chmod($tmp, $mode) || !rename($tmp, $path)) {
                    throw new CwException('p2g_drop_pair', 'the sealing pair could not be written: tell the CW team', 503);
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        };
        $put($secretPath, base64_encode($pair) . "\n", 0600);
        $put($publicPath, base64_encode(sodium_crypto_box_publickey($pair)) . "\n", 0644);
        sodium_memzero($pair);
        return true;
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
     * The key page's Save: the pasted Client ID and secret checked as a key, sealed to root and dropped for the job (a person of the Delivery
     * bills job only: staff 1 and 2). Nothing of the key is ever written anywhere else in the clear, shown back or logged.
     */
    public static function drop(Db $db, Caller $caller, string $dir, string $public, string $account, string $clientId, #[\SensitiveParameter] string $secret): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'only a person pastes a Parcel2Go key', 403);
        }
        (new DeliveryBills($db))->authoriseWrite($caller);
        $key = P2gKey::fromArray(['client_id' => trim($clientId), 'client_secret' => trim($secret), 'account' => $account, 'api_base' => 'https://www.parcel2go.com'], $account);
        $notReady = static fn (): CwException => new CwException('p2g_drop_dir', 'CW\'s folder for a new Parcel2Go key is not ready (it is made by the go-live kit): tell the CW team. '
            . 'Nothing was kept', 503);
        clearstatcache(true, $dir);
        $st = @lstat($dir);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 || (function_exists('posix_geteuid') && (int) $st['uid'] !== posix_geteuid())) {
            throw $notReady();
        }
        $pk = base64_decode(trim((string) @file_get_contents($public, false, null, 0, 200)), true);
        if (!is_string($pk) || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw $notReady();
        }
        $nonce = bin2hex(random_bytes(16));
        $json = json_encode(['key' => json_decode($key->json(), true, 4, JSON_THROW_ON_ERROR), 'nonce' => $nonce], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sealed = base64_encode(sodium_crypto_box_seal($json, $pk));
        sodium_memzero($json);
        $tmp = tempnam($dir, '.drop');
        if ($tmp === false) {
            throw new CwException('p2g_drop_dir', 'the new Parcel2Go key could not be kept: tell the CW team', 503);
        }
        try {
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $sealed) !== strlen($sealed)) {
                throw new CwException('p2g_drop_dir', 'the new Parcel2Go key could not be kept: tell the CW team', 503);
            }
            // its authorisation first (the account, the sealed bytes, the nonce), then the file in place: a drop is never there unaudited
            Audit::write($db, $caller, 'p2g.key_drop', 'carrier_account', $account, null, ['account' => $account, 'nonce_sha256' => hash('sha256', $nonce),
                'sealed_sha256' => hash('sha256', $sealed)]);
            if (!rename($tmp, self::file($dir, $account))) {
                throw new CwException('p2g_drop_dir', 'the new Parcel2Go key could not be kept: tell the CW team', 503);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * The job's take (root): the account's drop, if any, opened, proved by a token call ($prove: throws when Parcel2Go refuses it), written
     * into its key file and removed (see the class). True when a key was set; refused (p2g_key_refused): removed, audited, the old key kept.
     *
     * @param \Closure(P2gKey): void $prove
     */
    public static function take(Db $db, string $dropDir, string $keyDir, string $secretPath, string $account, \Closure $prove, ?\DateTimeImmutable $now = null): bool
    {
        $file = self::file($dropDir, $account);
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false) {
            return false;
        }
        $sealedSha = null;
        $refuse = static function (string $why) use ($db, $file, $account, &$sealedSha): never {
            @unlink($file);
            Audit::write($db, Caller::system('p2g_pull'), 'p2g.key_refused', 'carrier_account', $account, null, ['account' => $account, 'why' => $why]
                + ($sealedSha === null ? [] : ['sealed_sha256' => $sealedSha]));
            throw new CwException('p2g_key_refused', "the Parcel2Go key pasted for {$account} was not taken ({$why}): paste it again", 403);
        };
        $dirSt = @lstat($dropDir);
        if ($dirSt === false || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 07777) !== 0600 || (int) $st['uid'] !== (int) $dirSt['uid'] || (int) $st['size'] > self::MAX_BYTES) {
            $refuse('the drop is not a plain file of 0600 by the web app');
        }
        $raw = @file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
        $raw = is_string($raw) ? $raw : '';
        $sealedSha = hash('sha256', $raw);
        // its authorisation: a paste of THIS account in the last 24 hours audited these exact bytes, never used before
        $since = Clock::db(($now ?? Clock::now())->modify('-' . self::MAX_AGE_HOURS . ' hours'));
        $paste = null;
        foreach ($db->column("SELECT detail FROM audit_log WHERE action = 'p2g.key_drop' AND entity_id = ? AND created_at >= ? ORDER BY id DESC", [$account, $since]) as $d) {
            $x = json_decode((string) $d, true);
            if (is_array($x) && ($x['account'] ?? null) === $account && is_string($x['sealed_sha256'] ?? null) && hash_equals($x['sealed_sha256'], $sealedSha)) {
                $paste = $x;
                break;
            }
        }
        if ($paste === null) {
            $refuse('no paste of the key page in the last ' . self::MAX_AGE_HOURS . ' hours names it');
        }
        foreach ($db->column("SELECT detail FROM audit_log WHERE action IN ('p2g.key_set', 'p2g.key_refused') AND entity_id = ?", [$account]) as $d) {
            $x = json_decode((string) $d, true);
            if (is_array($x) && is_string($x['sealed_sha256'] ?? null) && hash_equals($x['sealed_sha256'], $sealedSha)) {
                $refuse('this paste was used before');
            }
        }
        clearstatcache(true, $secretPath);
        $sst = @lstat($secretPath);
        if ($sst === false || ($sst['mode'] & 0170000) !== 0100000 || ($sst['mode'] & 0077) !== 0 || (function_exists('posix_geteuid') && (int) $sst['uid'] !== posix_geteuid())) {
            throw new CwException('p2g_key_refused', 'the Parcel2Go key page\'s sealing pair is not ready (the go-live kit makes it): tell the CW team', 503);
        }
        $pair = base64_decode(trim((string) @file_get_contents($secretPath, false, null, 0, 400)), true);
        $box = base64_decode($raw, true);
        $json = is_string($pair) && strlen($pair) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES && is_string($box) && $box !== '' ? sodium_crypto_box_seal_open($box, $pair) : false;
        if (is_string($pair)) {
            sodium_memzero($pair);
        }
        if (!is_string($json)) {
            $refuse('it does not open with CW\'s key');
        }
        try {
            $j = json_decode($json, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $refuse('the drop is not JSON');
        } finally {
            sodium_memzero($json);
        }
        $nonce = is_array($j) && is_string($j['nonce'] ?? null) && preg_match('/^[0-9a-f]{32}$/D', $j['nonce']) === 1 ? $j['nonce'] : null;
        if ($nonce === null || !is_array($j['key'] ?? null) || array_keys($j) !== ['key', 'nonce'] || !hash_equals((string) ($paste['nonce_sha256'] ?? ''), hash('sha256', $nonce))) {
            $refuse('the drop is not in its form');
        }
        try {
            $key = P2gKey::fromArray($j['key'], $account);
        } catch (CwException) {
            $refuse('it is not a Parcel2Go key');
        }
        // the new key replaces the old only once Parcel2Go gives a token for it; Parcel2Go out of reach (or the run's time up) is no answer: the
        // paste waits for the next run, never burnt (G6)
        try {
            $prove($key);
        } catch (CwException $e) {
            if (in_array($e->errorCode, ['p2g_unreachable', 'p2g_deadline'], true)) {
                throw new CwException('p2g_key_later', "the Parcel2Go key pasted for {$account} could not be proved now (Parcel2Go out of reach): the next run tries again", 503);
            }
            $refuse('Parcel2Go gave no token for it');
        } catch (\Throwable) {
            $refuse('Parcel2Go gave no token for it');
        }
        $tmp = tempnam($keyDir, '.p2gkey');
        if ($tmp === false) {
            throw new CwException('p2g_key_refused', 'the Parcel2Go key could not be written (its folder): tell the CW team', 503);
        }
        try {
            $kj = $key->json();
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $kj) !== strlen($kj)) {
                throw new CwException('p2g_key_refused', 'the Parcel2Go key could not be written: tell the CW team', 503);
            }
            Audit::write($db, Caller::system('p2g_pull'), 'p2g.key_set', 'carrier_account', $account, null, ['account' => $account, 'sealed_sha256' => $sealedSha]);
            if (!rename($tmp, P2gKey::path($keyDir, $account))) {
                Audit::write($db, Caller::system('p2g_pull'), 'p2g.key_refused', 'carrier_account', $account, null, ['account' => $account,
                    'why' => 'the key file could not be put in place', 'sealed_sha256' => $sealedSha]);
                throw new CwException('p2g_key_refused', 'the Parcel2Go key could not be written: tell the CW team', 503);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        @unlink($file);
        return true;
    }

    /**
     * What the key page shows of an account (never the key): the newest key set (when, from whose paste), a drop waiting to be taken, and
     * whether the newest paste was refused after the last key set (G6: said, "paste it again").
     *
     * @return array{set_at: ?string, set_by: ?string, waiting: bool, refused_at: ?string, not_taken: bool}
     */
    public static function state(Db $db, string $dropDir, string $account): array
    {
        $set = $db->one("SELECT created_at FROM audit_log WHERE action = 'p2g.key_set' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$account]);
        $by = $set === null ? null : $db->one("SELECT a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id WHERE a.action = 'p2g.key_drop' "
            . 'AND a.entity_id = ? AND a.created_at <= ? ORDER BY a.id DESC LIMIT 1', [$account, $set['created_at']]);
        $ref = $db->value("SELECT created_at FROM audit_log WHERE action = 'p2g.key_refused' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$account]);
        $waiting = is_file(self::file($dropDir, $account));
        $setId = (int) ($db->value("SELECT MAX(id) FROM audit_log WHERE action = 'p2g.key_set' AND entity_id = ?", [$account]) ?? 0);
        $refId = (int) ($db->value("SELECT MAX(id) FROM audit_log WHERE action = 'p2g.key_refused' AND entity_id = ?", [$account]) ?? 0);
        return ['set_at' => $set === null ? null : (string) $set['created_at'], 'set_by' => $by === null ? null : (string) ($by['display_name'] ?? ''),
            'waiting' => $waiting, 'refused_at' => $ref === null ? null : (string) $ref, 'not_taken' => !$waiting && $refId > $setId];
    }
}
