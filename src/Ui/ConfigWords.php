<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Admin\ConfigHistory;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * The history of a setting, a rule, a reason, a warehouse or a place in words (the change pages of the set-it-yourself pack;
 * docs/decisions.md Y2): when, who (by name; "set up by CW" for the set-up's own rows; "on the server" for a command), what was
 * done, what changed from what to what, and why. Pure apart from history(), which reads the versions.
 */
final class ConfigWords
{
    /**
     * The versions of a subject as rows for a `table.stack`: when (UTC, the template shows UK time), who, action, what, why.
     *
     * @param string|null $valueType a setting's value type (its values are shown in words)
     * @return list<array{when: string, who: string, action: string, what: string, why: string}>
     */
    public static function history(Db $db, string $type, string $key, ?string $valueType = null, int $limit = 50): array
    {
        $out = [];
        foreach (ConfigHistory::history($db, $type, $key, $limit) as $v) {
            $out[] = [
                'when' => $v['created_at'],
                'who' => self::who($v['actor'], $v['who']),
                'action' => Words::of('CONFIG_ACTION', $v['action']),
                'what' => $v['before'] === null ? self::state($type, $v['state'], $valueType) : self::changes($type, $v['before'], $v['state'], $valueType),
                'why' => (string) ($v['reason'] ?? ''),
            ];
        }
        return $out;
    }

    /** Who made a change, in words: the person's name, "set up by CW" (the set-up), "on the server" (a command). */
    public static function who(string $actor, ?string $name): string
    {
        if (str_starts_with($actor, 'staff:')) {
            return $name ?? $actor;
        }
        return in_array($actor, ['system:migrate', 'system:history'], true) ? Words::CONFIG['set_up'] : Words::CONFIG['server'];
    }

    /**
     * What changed between two versions: "Name: Room 2 → Back room · In use: Yes → No" (only the fields that differ).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function changes(string $type, array $before, array $after, ?string $valueType = null): string
    {
        $parts = [];
        foreach (self::labels($type) as $field => $label) {
            $b = self::field($type, $field, $before[$field] ?? null, $valueType);
            $a = self::field($type, $field, $after[$field] ?? null, $valueType);
            if ($b !== $a) {
                $parts[] = $label . ': ' . $b . ' → ' . $a;
            }
        }
        return implode(' · ', $parts);
    }

    /** A whole state in words (a baseline or an add): "Name: Main warehouse · In use: Yes …". @param array<string, mixed> $state */
    public static function state(string $type, array $state, ?string $valueType = null): string
    {
        $parts = [];
        foreach (self::labels($type) as $field => $label) {
            $parts[] = $label . ': ' . self::field($type, $field, $state[$field] ?? null, $valueType);
        }
        return implode(' · ', $parts);
    }

    /** A setting's stored JSON text in words: Yes / No, "not set", the value. */
    public static function settingValue(string $valueType, mixed $json): string
    {
        if ($json === null || $json === '') {
            return Words::CONFIG['not_set'];
        }
        try {
            $v = Settings::decode($valueType, (string) $json);
        } catch (\JsonException) {
            return (string) $json;
        }
        return match (true) {
            is_bool($v) => Words::CONFIG[$v ? 'yes' : 'no'],
            $v === null, $v === '' => Words::CONFIG['not_set'],
            default => (string) $v,
        };
    }

    /** @return array<string, string> tracked field => its label in the history */
    private static function labels(string $type): array
    {
        return match ($type) {
            'setting' => ['value' => Words::SETTING_EDIT['now'], 'provisional' => Words::SETTING_EDIT['status']],
            'reason' => ['label' => Words::REASONS_EDIT['name'], 'is_active' => Words::REASONS_EDIT['status']],
            'document_rule' => ['review_rule' => Words::APPROVALS['review'], 'review_limit_units' => Words::APPROVALS['review_limit'],
                'review_due_days' => Words::APPROVALS['days'], 'approval_rule' => Words::APPROVALS['ok_first'], 'approval_limit_units' => Words::APPROVALS['number'],
                'reject_action' => Words::APPROVALS['reject']],
            'warehouse' => ['name' => Words::WAREHOUSES['wh_name'], 'note' => Words::WAREHOUSES['note'], 'is_sellable' => Words::WAREHOUSES['sold_from'],
                'stock_owner' => Words::WAREHOUSES['owner'], 'owner_entity' => Words::WAREHOUSES['owner_name'], 'is_active' => Words::WAREHOUSES['status']],
            'location' => ['name' => Words::WAREHOUSES['place_name'], 'note' => Words::WAREHOUSES['note'], 'is_active' => Words::WAREHOUSES['status']],
            default => throw new CwException('unknown_type', "no words for {$type}", 500),
        };
    }

    private static function field(string $type, string $field, mixed $v, ?string $valueType): string
    {
        if ($type === 'setting' && $field === 'value') {
            return self::settingValue($valueType ?? 'string', $v);
        }
        if ($type === 'setting' && $field === 'provisional') {
            return Words::CONFIG[(int) $v === 1 ? 'no' : 'yes'];
        }
        if (in_array($field, ['is_active', 'is_sellable'], true)) {
            return Words::CONFIG[(int) $v === 1 ? 'yes' : 'no'];
        }
        if ($type === 'document_rule') {
            return match ($field) {
                'review_rule' => Words::APPROVALS[match ((string) $v) { 'all' => 'review_all', 'over_limit' => 'review_over', default => 'review_none' }],
                'approval_rule' => Words::CONFIG[(string) $v === 'none' ? 'off' : 'on'],
                'reject_action' => Words::APPROVALS[(string) $v === 'record' ? 'reject_record' : 'reject_reverse'],
                default => $v === null ? Words::CONFIG['not_set'] : number_format((int) $v),
            };
        }
        if ($type === 'warehouse' && $field === 'stock_owner') {
            return Words::WAREHOUSES[(string) $v === 'other' ? 'owner_other' : 'owner_ours'];
        }
        return $v === null || $v === '' ? Words::CONFIG['not_set'] : (string) $v;
    }
}
