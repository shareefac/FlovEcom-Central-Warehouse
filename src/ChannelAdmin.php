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
     * Sets a channel's mode, IP allowlist and/or site writer switch (bin/channel_set.php, A14; the switch: IM10, I149). A dry run ($apply false)
     * only reads and reports. With $apply the channel row is locked first (FOR UPDATE, alone in its
     * transaction, like key rotation: D39), the before-values are read again under that lock, and each
     * setting that really changes is written and audited: `channel.mode` {from, to, by} and
     * `channel.allowlist` {before, after, by}, `channel.site_writer` {from, to, by} (with a channel-wide feed row, so the site
     * re-snapshots its `site` blocks). An allowlist that differs only in order is unchanged.
     * Nothing changed means nothing written and nothing audited. The site sees a new mode on its next
     * call (X-CW-Channel-Mode, A13).
     *
     * @param list<string>|null $allowedIps null = keep; [] = empty (every call refused)
     * @return array{id: int, code: string, before: array{mode: string, allowed_ips: list<string>, site_writer: bool},
     *     after: array{mode: string, allowed_ips: list<string>, site_writer: bool}, changed: list<string>, applied: bool, warnings: list<string>}
     */
    public function configure(string $code, ?string $mode, ?array $allowedIps, string $actor, bool $apply, ?bool $writer = null): array
    {
        if ($mode === null && $allowedIps === null && $writer === null) {
            throw new CwException('nothing_to_set', 'give a mode, an allowlist, the site writer switch or more', 400);
        }
        if ($mode !== null && !in_array($mode, self::MODES, true)) {
            throw new CwException('bad_mode', 'mode must be one of ' . implode(', ', self::MODES), 400);
        }
        $ips = $allowedIps === null ? null : self::ips($allowedIps);
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 64 || preg_match('/^[\x20-\x7e]+$/', $actor) !== 1) {
            throw new CwException('bad_actor', 'the actor must be 1-64 printable characters', 400);
        }
        $plan = function (Db $db, bool $lock) use ($code, $mode, $ips, $writer): array {
            $row = $db->one('SELECT c.id, c.mode, c.allowed_ips, c.site_writer, o.opening_orders_at FROM channel c '
                . 'LEFT JOIN channel_opening o ON o.channel_id = c.id WHERE c.code = ?' . ($lock ? ' FOR UPDATE OF c' : ''), [$code]);
            if ($row === null) {
                throw new CwException('unknown_channel', "no channel {$code}", 404);
            }
            $stored = json_decode((string) $row['allowed_ips'], true);
            $before = ['mode' => (string) $row['mode'],
                'allowed_ips' => is_array($stored) ? array_values(array_map('strval', array_filter($stored, 'is_string'))) : [],
                'site_writer' => (int) $row['site_writer'] === 1];
            $after = ['mode' => $mode ?? $before['mode'], 'allowed_ips' => $before['allowed_ips'], 'site_writer' => $writer ?? $before['site_writer']];
            $changed = [];
            if ($after['mode'] !== $before['mode']) {
                $changed[] = 'mode';
            }
            if ($after['site_writer'] !== $before['site_writer']) {
                $changed[] = 'site_writer';
            }
            if ($ips !== null && self::sorted($ips) !== self::sorted($before['allowed_ips'])) {
                $after['allowed_ips'] = $ips;
                $changed[] = 'allowed_ips';
            }
            $warnings = [];
            if ($after['mode'] !== 'off' && $after['allowed_ips'] === []) {
                $warnings[] = 'the allowlist is empty: every call of this site is refused (403)';
            }
            if ($before['mode'] === 'off' && $after['mode'] === 'live') {
                $warnings[] = 'off -> live skips shadow (plan §12: off -> shadow -> live)';
            }
            if ($after['mode'] === 'live' && $before['mode'] !== 'live' && $row['opening_orders_at'] === null) {
                $warnings[] = 'no final opening_orders batch has been accepted for this channel (D40, §8.1)';
            }
            if (array_search($after['mode'], self::MODES, true) < array_search($before['mode'], self::MODES, true)) {
                $warnings[] = 'lowering the mode: the site follows on its next call; its own rollback steps apply (plan §12)';
            }
            if ($after['site_writer'] && !$before['site_writer']) {
                $warnings[] = 'site writer on: from the site\'s next feed poll CW writes the stock, selling mode and low-stock threshold of every linked listing '
                    . '(the site writes only while its own effective mode is live and its own site_writer switch is on; IM10, I-Day)';
                if ($after['mode'] !== 'live') {
                    $warnings[] = "the channel is {$after['mode']}: nothing is written on the site until it is live";
                }
            }
            if (!$after['site_writer'] && $before['site_writer']) {
                $warnings[] = 'site writer off: the site keeps the figures CW wrote last and stops taking CW\'s; its own stock screens are the writers again';
            }
            return ['id' => (int) $row['id'], 'code' => $code, 'before' => $before, 'after' => $after, 'changed' => $changed,
                'applied' => false, 'warnings' => $warnings];
        };
        if (!$apply) {
            return $plan($this->db, false);
        }
        return $this->db->transaction(function (Db $db) use ($plan, $actor): array {
            $p = $plan($db, true);
            if ($p['changed'] === []) {
                return $p;
            }
            $db->exec('UPDATE channel SET mode = ?, allowed_ips = ?, site_writer = ? WHERE id = ?',
                [$p['after']['mode'], json_encode($p['after']['allowed_ips'], JSON_THROW_ON_ERROR), $p['after']['site_writer'] ? 1 : 0, $p['id']]);
            $caller = Caller::system('channel_admin');
            if (in_array('mode', $p['changed'], true)) {
                Audit::write($db, $caller, 'channel.mode', 'channel', $p['code'], null,
                    ['from' => $p['before']['mode'], 'to' => $p['after']['mode'], 'by' => $actor]);
            }
            if (in_array('allowed_ips', $p['changed'], true)) {
                Audit::write($db, $caller, 'channel.allowlist', 'channel', $p['code'], null,
                    ['before' => $p['before']['allowed_ips'], 'after' => $p['after']['allowed_ips'], 'by' => $actor]);
            }
            if (in_array('site_writer', $p['changed'], true)) {
                Audit::write($db, $caller, 'channel.site_writer', 'channel', $p['code'], null,
                    ['from' => $p['before']['site_writer'], 'to' => $p['after']['site_writer'], 'by' => $actor]);
                // Every listing view of the site changes (its `site` block): one channel-wide feed row, so the site re-snapshots.
                // LAST: the feed clock, after the channel row (the order assignSellableWarehouse uses, D39).
                (new Stock($db))->channelChanged($p['id'], 'site_writer');
            }
            $p['applied'] = true;
            return $p;
        });
    }

    /**
     * Moves a website to another sellable warehouse (bin/channel_set.php --warehouse; G05, docs/decisions.md Y31): every listing of the
     * site then sells from it, so the feed tells the site to re-snapshot (Stock::assignSellableWarehouse, audited channel.warehouse
     * through its idempotency row, plus channel.warehouse_move {from, to, by}). A dry run ($apply false) only reads and reports. The
     * target must exist, be sellable and switched on (0019); moving to where it sells now changes nothing.
     *
     * @return array{code: string, from: ?string, to: string, changed: bool, applied: bool, warnings: list<string>}
     */
    public function moveWarehouse(string $code, string $warehouseCode, string $actor, bool $apply): array
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 64 || preg_match('/^[\x20-\x7e]+$/', $actor) !== 1) {
            throw new CwException('bad_actor', 'the actor must be 1-64 printable characters', 400);
        }
        $c = $this->db->one('SELECT c.id, CAST(c.mode AS CHAR) AS mode, w.code AS wh FROM channel c LEFT JOIN channel_warehouse cw ON cw.channel_id = c.id AND cw.is_sellable = 1 '
            . 'LEFT JOIN warehouse w ON w.id = cw.warehouse_id WHERE c.code = ?', [$code]) ?? throw new CwException('unknown_channel', "no channel {$code}", 404);
        $to = $this->db->one('SELECT id, code, is_sellable, is_active FROM warehouse WHERE code = ?', [strtoupper(trim($warehouseCode))])
            ?? throw new CwException('unknown_warehouse', "no warehouse {$warehouseCode}", 404);
        if ((int) $to['is_sellable'] !== 1) {
            throw new CwException('not_sellable', "warehouse {$to['code']} is not sellable (the Warehouses page changes that, with a confirmation)", 422);
        }
        if ((int) $to['is_active'] !== 1) {
            throw new CwException('warehouse_off', "warehouse {$to['code']} is switched off", 422);
        }
        $from = $c['wh'] === null ? null : (string) $c['wh'];
        $out = ['code' => $code, 'from' => $from, 'to' => (string) $to['code'], 'changed' => $from !== (string) $to['code'], 'applied' => false, 'warnings' => []];
        if ($out['changed'] && (string) $c['mode'] !== 'off') {
            $out['warnings'][] = "the site is {$c['mode']}: every listing of it sells from {$to['code']} from its next feed poll (it re-snapshots)";
        }
        if (!$apply || !$out['changed']) {
            return $out;
        }
        $r = (new Stock($this->db))->assignSellableWarehouse(Caller::system('channel_admin'), (int) $c['id'], (string) $to['code'], 'channel_set:warehouse:' . bin2hex(random_bytes(12)));
        if ($r->status !== 200) {
            throw new CwException((string) ($r->body['error'] ?? 'refused'), (string) ($r->body['message'] ?? 'the move was refused'), $r->status);
        }
        Audit::write($this->db, Caller::system('channel_admin'), 'channel.warehouse_move', 'channel', $code, null, ['from' => $from, 'to' => $to['code'], 'by' => $actor]);
        $out['applied'] = true;
        return $out;
    }

    /** @param list<string> $ips @return list<string> */
    private static function sorted(array $ips): array
    {
        sort($ips, SORT_STRING);
        return $ips;
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
