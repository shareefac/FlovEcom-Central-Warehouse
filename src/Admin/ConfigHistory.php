<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * The history of every configuration row a screen can change (config_change, 0019; docs/decisions.md Y2): settings, reason codes,
 * document rules, warehouses and the places inside them. One row per version: the whole tracked row after it (`state`, TRACKED),
 * the row before it, the reason (3-500 characters), who and when. Version 1 is a `baseline` (what 0019 found) or an `add` (a row
 * made on a screen); every later version is a change made by a service of this pack in the same transaction as the change, after
 * locking the row (so versions of one row are serialised by that lock). Append-only for the app login.
 *
 * The services call record() after they wrote the row; ConfigInvariants compares every row that has a history with its latest
 * version every night (K1-K3), so a change made around the services (the app login may UPDATE some columns of these tables) is
 * found. A row with no history at all (added by a later migration without its baseline) is not compared until its first change.
 */
final class ConfigHistory
{
    public const TYPES = ['setting', 'reason', 'document_rule', 'warehouse', 'location'];

    /** subject type => tracked field => its type (int, ?int, string, ?string): what a version's `state` holds. */
    public const TRACKED = [
        'setting' => ['value' => 'string', 'provisional' => 'int'],
        'reason' => ['label' => 'string', 'applies_to' => 'string', 'direction' => 'string', 'needs_note' => 'int', 'is_gift' => 'int',
            'system_only' => 'int', 'is_active' => 'int', 'sort_order' => 'int'],
        'document_rule' => ['review_rule' => 'string', 'review_limit_units' => '?int', 'review_due_days' => 'int', 'approval_rule' => 'string',
            'approval_limit_units' => '?int', 'reject_action' => 'string'],
        'warehouse' => ['code' => 'string', 'name' => 'string', 'is_sellable' => 'int', 'is_active' => 'int', 'stock_owner' => 'string',
            'owner_entity' => '?string', 'is_system' => 'int', 'note' => '?string'],
        'location' => ['warehouse' => 'string', 'code' => 'string', 'name' => 'string', 'is_active' => 'int', 'note' => '?string'],
    ];

    /** The reason every change needs (audited with it). */
    public const REASON_MIN = 3;
    public const REASON_MAX = 500;

    /** A reason of 3 to 500 characters, trimmed; 400 bad_reason otherwise. */
    public static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX || !mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new CwException('bad_reason', 'say in ' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters why this changes (it is kept with the change)', 400,
                ['field' => 'reason']);
        }
        return $reason;
    }

    /**
     * The tracked fields of a row, each cast to its type (TRACKED), in TRACKED's order: what a version stores and what the nightly
     * check compares.
     *
     * @param array<string, mixed> $row
     * @return array<string, int|string|null>
     */
    public static function normalise(string $type, array $row): array
    {
        $fields = self::TRACKED[$type] ?? throw new \InvalidArgumentException("unknown config subject type {$type}");
        $out = [];
        foreach ($fields as $field => $kind) {
            $v = $row[$field] ?? null;
            $out[$field] = match ($kind) {
                'int' => (int) $v,
                '?int' => $v === null ? null : (int) $v,
                'string' => (string) $v,
                default => $v === null ? null : (string) $v,
            };
        }
        return $out;
    }

    /**
     * The live row of a subject as it is tracked, or null when there is none. $lock: FOR UPDATE (the services lock the row they
     * change through this, before they read its history).
     *
     * @return array<string, int|string|null>|null
     */
    public static function state(Db $db, string $type, string $key, bool $lock = false): ?array
    {
        $for = $lock ? ' FOR UPDATE' : '';
        $row = match ($type) {
            'setting' => $db->one('SELECT CAST(value_json AS CHAR) AS value, provisional FROM app_setting WHERE setting_key = ?' . $for, [$key]),
            'reason' => $db->one('SELECT label, CAST(applies_to AS CHAR) AS applies_to, CAST(direction AS CHAR) AS direction, needs_note, is_gift, '
                . 'system_only, is_active, sort_order FROM reason_code WHERE code = ?' . $for, [$key]),
            'document_rule' => $db->one('SELECT CAST(review_rule AS CHAR) AS review_rule, review_limit_units, review_due_days, '
                . 'CAST(approval_rule AS CHAR) AS approval_rule, approval_limit_units, CAST(reject_action AS CHAR) AS reject_action '
                . 'FROM document_type WHERE code = ?' . $for, [$key]),
            'warehouse' => $db->one('SELECT code, name, is_sellable, is_active, CAST(stock_owner AS CHAR) AS stock_owner, owner_entity, is_system, note '
                . 'FROM warehouse WHERE code = ?' . $for, [$key]),
            'location' => self::locationRow($db, $key, $lock),
            default => throw new \InvalidArgumentException("unknown config subject type {$type}"),
        };
        return $row === null ? null : self::normalise($type, $row);
    }

    /** The key of a place inside a warehouse: "MAIN/OVERFLOW". */
    public static function locationKey(string $warehouseCode, string $locationCode): string
    {
        return $warehouseCode . '/' . $locationCode;
    }

    /** @return array<string, mixed>|null */
    private static function locationRow(Db $db, string $key, bool $lock): ?array
    {
        $parts = explode('/', $key, 2);
        if (count($parts) !== 2) {
            return null;
        }
        return $db->one('SELECT w.code AS warehouse, l.code, l.name, l.is_active, l.note FROM warehouse_location l JOIN warehouse w ON w.id = l.warehouse_id '
            . 'WHERE w.code = ? AND l.code = ?' . ($lock ? ' FOR UPDATE OF l' : ''), $parts);
    }

    /** The number of the latest version of a subject (0 when it has no history). */
    public static function version(Db $db, string $type, string $key): int
    {
        return (int) ($db->value('SELECT MAX(version) FROM config_change WHERE subject_type = ? AND subject_key = ?', [$type, $key]) ?? 0);
    }

    /**
     * The latest version of a subject: id, version, action, the state (decoded), created_at, actor; null when it has none.
     *
     * @return array{id: int, version: int, action: string, state: array<string, mixed>, created_at: string, actor: string}|null
     */
    public static function latest(Db $db, string $type, string $key): ?array
    {
        $r = $db->one('SELECT id, version, action, CAST(state AS CHAR) AS state, created_at, actor FROM config_change '
            . 'WHERE subject_type = ? AND subject_key = ? ORDER BY version DESC LIMIT 1', [$type, $key]);
        if ($r === null) {
            return null;
        }
        $state = json_decode((string) $r['state'], true);
        return ['id' => (int) $r['id'], 'version' => (int) $r['version'], 'action' => (string) $r['action'],
            'state' => is_array($state) ? $state : [], 'created_at' => (string) $r['created_at'], 'actor' => (string) $r['actor']];
    }

    /**
     * 409 changed_meanwhile when the form was drawn at another version of the subject than its latest ($seen null: no check, the
     * CLI). The caller holds the subject's row lock.
     */
    public static function checkSeen(Db $db, string $type, string $key, ?int $seen): void
    {
        if ($seen === null) {
            return;
        }
        $now = self::version($db, $type, $key);
        if ($now !== $seen) {
            $latest = self::latest($db, $type, $key);
            throw new CwException('changed_meanwhile', "this was changed by someone else since the page was shown (version {$now}, the page had {$seen}): reload it",
                409, ['version' => $now, 'by' => $latest['actor'] ?? null, 'at' => $latest['created_at'] ?? null]);
        }
    }

    /**
     * Writes the next version of a subject (call it in the transaction that changed the row, after the change). Returns the new
     * row's id and version.
     *
     * @param array<string, mixed>|null $before the tracked row before (null for an add)
     * @param array<string, mixed> $after the tracked row after
     * @return array{id: int, version: int}
     */
    public static function record(Db $db, Caller $caller, string $type, string $key, string $action, ?array $before, array $after, string $reason): array
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('a config change is recorded in the transaction that made it');
        }
        $version = self::version($db, $type, $key) + 1;
        if ($action === 'add' && $version !== 1) {
            throw new \LogicException("config {$type} {$key}: an add is version 1 (it would be version {$version})");
        }
        if ($action !== 'add' && $version === 1) {
            // A row with no history yet (a later migration added it without its baseline): what it was becomes version 1 first.
            if ($before === null) {
                throw new \LogicException("config {$type} {$key}: a change needs the row before it");
            }
            $db->insert("INSERT INTO config_change (subject_type, subject_key, version, action, state, actor) VALUES (?, ?, 1, 'baseline', CAST(? AS JSON), 'system:history')",
                [$type, $key, Idempotency::json(self::normalise($type, $before))]);
            $version = 2;
        }
        $id = $db->insert(
            'INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor, staff_user_id) '
            . 'VALUES (?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON), ?, ?, ?)',
            [$type, $key, $version, $action, Idempotency::json(self::normalise($type, $after)),
                $before === null ? null : Idempotency::json(self::normalise($type, $before)), $reason, $caller->actor, $caller->staffUserId],
        );
        return ['id' => $id, 'version' => $version];
    }

    /**
     * A subject's versions, newest first: version, action, state and before (decoded), reason, actor, the person's name, when.
     *
     * @return list<array{id: int, version: int, action: string, state: array<string, mixed>, before: ?array<string, mixed>, reason: ?string, actor: string, staff_user_id: ?int, who: ?string, created_at: string}>
     */
    public static function history(Db $db, string $type, string $key, int $limit = 50): array
    {
        $out = [];
        foreach ($db->all(
            'SELECT c.id, c.version, c.action, CAST(c.state AS CHAR) AS state, CAST(c.before_state AS CHAR) AS before_state, c.reason, c.actor, '
            . 'c.staff_user_id, u.display_name, c.created_at FROM config_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id '
            . 'WHERE c.subject_type = ? AND c.subject_key = ? ORDER BY c.version DESC LIMIT ' . max(1, min(500, $limit)),
            [$type, $key],
        ) as $r) {
            $state = json_decode((string) $r['state'], true);
            $before = $r['before_state'] === null ? null : json_decode((string) $r['before_state'], true);
            $out[] = ['id' => (int) $r['id'], 'version' => (int) $r['version'], 'action' => (string) $r['action'],
                'state' => is_array($state) ? $state : [], 'before' => is_array($before) ? $before : null,
                'reason' => $r['reason'] === null ? null : (string) $r['reason'], 'actor' => (string) $r['actor'],
                'staff_user_id' => $r['staff_user_id'] === null ? null : (int) $r['staff_user_id'],
                'who' => $r['display_name'] === null ? null : (string) $r['display_name'], 'created_at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * Who may change configuration: a CLI tool (system caller), or a staff caller who holds settings.manage now (re-read inside the
     * transaction: Y1). 403 otherwise (a site never).
     */
    public static function authorise(Db $db, Caller $caller): void
    {
        if ($caller->isChannel()) {
            throw new CwException('staff_required', 'settings and rules are changed by staff or on the server', 403);
        }
        if ($caller->staffUserId !== null
            && !\CW\Auth\Permissions::can(\CW\Staff\StaffRoles::active($db, $caller->staffUserId), 'settings.manage')) {
            throw new CwException('role_not_allowed', 'only an admin or a reviewer changes settings, rules and lists', 403);
        }
    }
}
