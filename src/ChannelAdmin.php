<?php

declare(strict_types=1);

namespace CW;

use CW\Api\ApiKey;
use CW\Api\IpAllowlist;

/**
 * Channel set-up for the bin/ tools (plan §10 "a new site = a channel row + key + warehouse
 * assignment"; §11 keys). A key is generated here, returned ONCE to the tool that prints it,
 * and only its sha256 is stored. Every change is audited (never with the key).
 */
final class ChannelAdmin
{
    public const MODES = ['off', 'shadow', 'live'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Creates a channel selling from one sellable warehouse. Defaults fail closed: mode `off`;
     * an empty allowlist refuses every call until addresses are added.
     *
     * @param list<string> $allowedIps addresses or CIDR blocks (IpAllowlist)
     * @param list<string> $movementTypes ERP-relay movement types the site may send (R17); default none
     * @return array{id: int, key: string}
     */
    public function create(string $code, string $name, string $warehouseCode = 'MAIN', array $allowedIps = [], string $mode = 'off',
        int $ttlSec = 2400, array $movementTypes = []): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code) !== 1) {
            throw new CwException('bad_code', 'code must be lower-case letters, digits and _ (max 32), starting with a letter', 400);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new CwException('bad_name', 'name must be 1-100 characters', 400);
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new CwException('bad_mode', 'mode must be one of ' . implode(', ', self::MODES), 400);
        }
        if ($ttlSec < 60 || $ttlSec > 86400) {
            throw new CwException('bad_ttl', 'the hold TTL must be 60..86400 seconds', 400);
        }
        $ips = self::ips($allowedIps);
        $types = self::movementTypes($movementTypes);
        $key = ApiKey::generate();
        $id = $this->db->transaction(function (Db $db) use ($code, $name, $warehouseCode, $ips, $mode, $ttlSec, $key, $types): int {
            if ($db->value('SELECT id FROM channel WHERE code = ?', [$code]) !== null) {
                throw new CwException('channel_exists', "channel {$code} already exists (use bin/rotate_key.php for a new key)", 409);
            }
            $wh = $db->one('SELECT id, is_sellable FROM warehouse WHERE code = ?', [$warehouseCode]);
            if ($wh === null) {
                throw new CwException('unknown_warehouse', "no warehouse {$warehouseCode}", 404);
            }
            if ((int) $wh['is_sellable'] !== 1) {
                throw new CwException('not_sellable', "warehouse {$warehouseCode} is not sellable", 422);
            }
            $id = $db->insert(
                'INSERT INTO channel (code, name, mode, api_key_hash, allowed_ips, reserve_ttl_sec, movement_types) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $name, $mode, ApiKey::hash($key), json_encode($ips, JSON_THROW_ON_ERROR), $ttlSec, json_encode($types, JSON_THROW_ON_ERROR)],
            );
            $db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$id, (int) $wh['id']]);
            Audit::write($db, Caller::system('channel_admin'), 'channel.create', 'channel', $code, null,
                ['mode' => $mode, 'warehouse' => $warehouseCode, 'allowed_ips' => $ips, 'reserve_ttl_sec' => $ttlSec, 'movement_types' => $types]);
            return $id;
        });
        return ['id' => $id, 'key' => $key];
    }

    /** Replaces a channel's key; the old key stops working at once. Returns the new key (once). */
    public function rotateKey(string $code): string
    {
        $key = ApiKey::generate();
        $this->db->transaction(function (Db $db) use ($code, $key): void {
            $id = $db->value('SELECT id FROM channel WHERE code = ? FOR UPDATE', [$code]);
            if ($id === null) {
                throw new CwException('unknown_channel', "no channel {$code}", 404);
            }
            $db->exec('UPDATE channel SET api_key_hash = ? WHERE id = ?', [ApiKey::hash($key), (int) $id]);
            Audit::write($db, Caller::system('channel_admin'), 'channel.key_rotate', 'channel', $code, null, ['channel_id' => (int) $id]);
        });
        return $key;
    }

    /**
     * The movement types a site may be granted: only the ERP relay's (R17). Counts, adjustments,
     * write-offs and transfers are staff-only.
     *
     * @param list<string> $types
     * @return list<string> de-duplicated, sorted
     */
    public static function movementTypes(array $types): array
    {
        $out = [];
        foreach ($types as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (!in_array($t, Movements::CHANNEL_TYPES, true)) {
                throw new CwException('bad_movement_type', "{$t} cannot be granted to a site (only " . implode(', ', Movements::CHANNEL_TYPES) . ')', 400);
            }
            $out[$t] = true;
        }
        $out = array_map('strval', array_keys($out));
        sort($out);
        return $out;
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    public static function ips(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            $e = trim((string) $e);
            if ($e === '') {
                continue;
            }
            if (!IpAllowlist::isValidEntry($e)) {
                throw new CwException('bad_ip', "{$e} is not an IP address or CIDR block (a /0 block is refused)", 400);
            }
            $out[$e] = true;
        }
        return array_map('strval', array_keys($out));
    }
}
