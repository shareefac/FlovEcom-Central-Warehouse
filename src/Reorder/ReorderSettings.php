<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;
use CW\Staff\StaffRoles;

/**
 * The reorder settings people change (spec §7.5; docs/decisions.md I66): per item (item_reorder), per brand (reorder_brand),
 * the anomaly windows (demand_anomaly), and the reorder.* settings read by the builder and the list (params()).
 *
 * Every write: a staff caller holding reorder.manage (buyer, purchasing_manager; never admin), roles re-read inside ONE
 * Db::transaction; item and brand rows carry a version (0 = "no row yet"; 409 version_conflict when the row moved since the
 * form was drawn); nothing changed writes nothing. Audit reorder.item_settings / reorder.brand_settings {before, after},
 * reorder.anomaly_add / reorder.anomaly_end. These tables take no lock but their own row (spec §6.9).
 */
final class ReorderSettings
{
    public const ITEM_FIELDS = ['safety_days', 'lead_days_override', 'min_stock', 'max_stock', 'demand_factor', 'pack_rounding', 'do_not_reorder', 'note'];
    public const BRAND_FIELDS = ['demand_factor', 'safety_days', 'note'];
    public const ANOMALY_MAX_DAYS = 92;
    public const MAX_STOCK = 2_000_000_000;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The reorder.* settings, checked (a value outside its range is a configuration error: 500 bad_setting).
     *
     * @return array{lead: int, review: int, safety: int, short_window: int, long_window: int, weight_e6: int, min_short: int, min_long: int,
     *   cap_multiple: int, cap_floor: int, promo_drop: string, promo_uplift: string, promo_min_units: int, stale_days: int}
     */
    public static function params(Settings $s): array
    {
        $int = static function (string $key, int $min, int $max) use ($s): int {
            $v = $s->get($key);
            if (!is_int($v) || $v < $min || $v > $max) {
                throw new CwException('bad_setting', "the setting {$key} must be a whole number from {$min} to {$max}", 500);
            }
            return $v;
        };
        $dec = static function (string $key, string $min, string $max) use ($s): string {
            $v = $s->get($key);
            if (!is_string($v) || preg_match('/^\d{1,8}(\.\d{1,6})?$/D', $v) !== 1 || Settings::cmpDecimal($v, $min) < 0 || Settings::cmpDecimal($v, $max) > 0) {
                throw new CwException('bad_setting', "the setting {$key} must be a decimal from {$min} to {$max}", 500);
            }
            return $v;
        };
        $short = $int('reorder.short_window_days', 1, 120);
        $long = $int('reorder.long_window_days', $short, 120);
        return [
            'lead' => $int('reorder.default_lead_days', 0, 120),
            'review' => $int('reorder.default_review_days', 0, 120),
            'safety' => $int('reorder.default_safety_days', 0, 120),
            'short_window' => $short,
            'long_window' => $long,
            'weight_e6' => PromoDetector::e6($dec('reorder.short_weight', '0', '1')),
            'min_short' => $int('reorder.min_valid_days_short', 1, $short),
            'min_long' => $int('reorder.min_valid_days_long', 1, $long),
            'cap_multiple' => $int('reorder.spike_cap_multiple', 1, 100),
            'cap_floor' => $int('reorder.spike_cap_floor', 0, 1_000_000),
            'promo_drop' => $dec('reorder.promo_price_drop', '0', '1'),
            'promo_uplift' => $dec('reorder.promo_units_uplift', '1', '100'),
            'promo_min_units' => $int('reorder.promo_min_units', 0, 1_000_000),
            'stale_days' => $int('reorder.stale_history_days', 0, 120),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the item_reorder row */
    public function item(int $skuId): ?array
    {
        return $this->db->one('SELECT * FROM item_reorder WHERE sku_id = ?', [$skuId]);
    }

    /**
     * Saves an item's reorder settings (every field of ITEM_FIELDS; an empty value clears it). $version: the row's version
     * the form was drawn with, 0 when the item had none.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed> the row
     */
    public function saveItem(Caller $caller, int $skuId, int $version, array $fields): array
    {
        $new = self::itemFields($fields);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $version, $new): array {
            $this->manager($caller);
            $sku = $db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE id = ?', [$skuId])
                ?? throw new CwException('unknown_item', 'there is no such item', 404);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "item {$sku['code']} was merged into another item: change that one's settings", 422);
            }
            $row = $db->one('SELECT * FROM item_reorder WHERE sku_id = ? FOR UPDATE', [$skuId]);
            self::checkVersion($row, $version);
            $before = $row === null ? null : self::pick($row, self::ITEM_FIELDS);
            if ($before !== null && $before == $new) {
                return $row;
            }
            $now = Clock::db(($this->clock)());
            if ($row === null) {
                $db->exec('INSERT INTO item_reorder (sku_id, safety_days, lead_days_override, min_stock, max_stock, demand_factor, pack_rounding, do_not_reorder, note, '
                    . 'version, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                    [$skuId, $new['safety_days'], $new['lead_days_override'], $new['min_stock'], $new['max_stock'], $new['demand_factor'], $new['pack_rounding'],
                        $new['do_not_reorder'], $new['note'], $caller->staffUserId, $caller->actor, $now]);
            } else {
                $db->exec('UPDATE item_reorder SET safety_days = ?, lead_days_override = ?, min_stock = ?, max_stock = ?, demand_factor = ?, pack_rounding = ?, '
                    . 'do_not_reorder = ?, note = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE sku_id = ?',
                    [$new['safety_days'], $new['lead_days_override'], $new['min_stock'], $new['max_stock'], $new['demand_factor'], $new['pack_rounding'],
                        $new['do_not_reorder'], $new['note'], $caller->staffUserId, $caller->actor, $now, $skuId]);
            }
            Audit::write($db, $caller, 'reorder.item_settings', 'sku', (string) $skuId, null, ['item' => $sku['code'], 'before' => $before, 'after' => $new]);
            return (array) $db->one('SELECT * FROM item_reorder WHERE sku_id = ?', [$skuId]);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Brands
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the reorder_brand row */
    public function brand(string $brand): ?array
    {
        return $this->db->one('SELECT * FROM reorder_brand WHERE brand = ?', [trim($brand)]);
    }

    /**
     * Saves a brand's demand factor and safety days (an empty value clears it). The brand must be an item's brand.
     *
     * @param array<string, mixed> $fields demand_factor, safety_days, note
     * @return array<string, mixed> the row
     */
    public function saveBrand(Caller $caller, string $brand, int $version, array $fields): array
    {
        $brand = trim($brand);
        if ($brand === '' || mb_strlen($brand) > 128 || !mb_check_encoding($brand, 'UTF-8')) {
            throw new CwException('bad_field', 'brand: the brand as the items carry it', 400, ['field' => 'brand']);
        }
        $new = self::brandFields($fields);
        return $this->db->transaction(function (Db $db) use ($caller, $brand, $version, $new): array {
            $this->manager($caller);
            $known = $db->value('SELECT brand FROM sku WHERE brand = ? LIMIT 1', [$brand]);
            $row = $db->one('SELECT * FROM reorder_brand WHERE brand = ? FOR UPDATE', [$brand]);
            if ($known === null && $row === null) {
                throw new CwException('unknown_brand', 'no item has the brand ' . mb_substr($brand, 0, 60), 422, ['field' => 'brand']);
            }
            self::checkVersion($row, $version);
            $before = $row === null ? null : self::pick($row, self::BRAND_FIELDS);
            if ($before !== null && $before == $new) {
                return $row;
            }
            $now = Clock::db(($this->clock)());
            $name = $row === null ? (string) $known : (string) $row['brand'];
            if ($row === null) {
                $db->exec('INSERT INTO reorder_brand (brand, demand_factor, safety_days, note, version, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
                    [$name, $new['demand_factor'], $new['safety_days'], $new['note'], $caller->staffUserId, $caller->actor, $now]);
            } else {
                $db->exec('UPDATE reorder_brand SET demand_factor = ?, safety_days = ?, note = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? '
                    . 'WHERE brand = ?', [$new['demand_factor'], $new['safety_days'], $new['note'], $caller->staffUserId, $caller->actor, $now, $name]);
            }
            Audit::write($db, $caller, 'reorder.brand_settings', 'brand', mb_substr($name, 0, 64), null, ['brand' => $name, 'before' => $before, 'after' => $new]);
            return (array) $db->one('SELECT * FROM reorder_brand WHERE brand = ?', [$name]);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Anomalies
    // ------------------------------------------------------------------------------------------

    /**
     * A window of days whose sales are not trusted as demand: date_from..date_to (at most 92 days apart), optionally one
     * channel and / or one brand, and a label (3-200 characters).
     *
     * @param array<string, mixed> $fields date_from, date_to, channel_id, brand, label
     * @return array<string, mixed> the row
     */
    public function addAnomaly(Caller $caller, array $fields): array
    {
        $from = self::date($fields['date_from'] ?? null, 'date_from');
        $to = self::date($fields['date_to'] ?? null, 'date_to');
        if ($from > $to) {
            throw new CwException('bad_field', 'the first day is after the last day', 422, ['field' => 'date_to']);
        }
        if (DemandMath::day($to) - DemandMath::day($from) > self::ANOMALY_MAX_DAYS) {
            throw new CwException('bad_field', 'a window covers at most ' . (self::ANOMALY_MAX_DAYS + 1) . ' days', 422, ['field' => 'date_to']);
        }
        $label = self::text($fields['label'] ?? null, 200, 'label');
        if ($label === null || mb_strlen($label) < 3) {
            throw new CwException('bad_field', 'label: say in 3 to 200 characters what happened (it shows in every "Why")', 422, ['field' => 'label']);
        }
        $brand = self::text($fields['brand'] ?? null, 128, 'brand');
        $channel = $fields['channel_id'] ?? null;
        $channel = $channel === null || $channel === '' ? null : (is_int($channel) || (is_string($channel) && ctype_digit($channel)) ? (int) $channel
            : throw new CwException('bad_field', 'channel_id: a site', 400, ['field' => 'channel_id']));
        return $this->db->transaction(function (Db $db) use ($caller, $from, $to, $label, $brand, $channel): array {
            $this->manager($caller);
            if ($channel !== null && $db->value('SELECT 1 FROM channel WHERE id = ?', [$channel]) === null) {
                throw new CwException('bad_field', 'there is no such site', 422, ['field' => 'channel_id']);
            }
            $id = $db->insert('INSERT INTO demand_anomaly (date_from, date_to, channel_id, brand, label, created_by, created_actor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$from, $to, $channel, $brand, $label, $caller->staffUserId, $caller->actor, Clock::db(($this->clock)())]);
            Audit::write($db, $caller, 'reorder.anomaly_add', 'demand_anomaly', (string) $id, null,
                ['from' => $from, 'to' => $to, 'channel_id' => $channel, 'brand' => $brand, 'label' => $label]);
            return (array) $db->one('SELECT * FROM demand_anomaly WHERE id = ?', [$id]);
        });
    }

    /** Ends a window (it no longer excludes anything at the next recalculation). @return array<string, mixed> the row */
    public function endAnomaly(Caller $caller, int $id): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): array {
            $this->manager($caller);
            $row = $db->one('SELECT * FROM demand_anomaly WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_anomaly', 'there is no such window', 404);
            if ((int) $row['is_active'] !== 1) {
                throw new CwException('anomaly_ended', 'this window was already ended', 409);
            }
            $db->exec('UPDATE demand_anomaly SET is_active = 0, ended_by = ?, ended_at = ? WHERE id = ?', [$caller->staffUserId, Clock::db(($this->clock)()), $id]);
            Audit::write($db, $caller, 'reorder.anomaly_end', 'demand_anomaly', (string) $id, null,
                ['from' => $row['date_from'], 'to' => $row['date_to'], 'label' => $row['label']]);
            return (array) $db->one('SELECT * FROM demand_anomaly WHERE id = ?', [$id]);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------------------------------

    /** The caller may change reorder settings (reorder.manage, never admin), read inside the transaction. */
    public function manager(Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'reorder settings are changed by staff', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (in_array('admin', $roles, true) || !Permissions::can($roles, 'reorder.manage')) {
            throw new CwException('role_not_allowed', (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles))
                . ') cannot change reorder settings', 403);
        }
    }

    /**
     * @param array<string, mixed> $in
     * @return array{safety_days: ?int, lead_days_override: ?int, min_stock: ?int, max_stock: ?int, demand_factor: ?string, pack_rounding: ?string, do_not_reorder: int, note: ?string}
     */
    public static function itemFields(array $in): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::ITEM_FIELDS, true)) {
                throw new CwException('bad_field', 'item settings are ' . implode(', ', self::ITEM_FIELDS), 400, ['field' => mb_substr((string) $k, 0, 40)]);
            }
        }
        $out = [
            'safety_days' => self::int($in['safety_days'] ?? null, 0, 90, 'safety_days'),
            'lead_days_override' => self::int($in['lead_days_override'] ?? null, 0, 120, 'lead_days_override'),
            'min_stock' => self::int($in['min_stock'] ?? null, 0, self::MAX_STOCK, 'min_stock'),
            'max_stock' => self::int($in['max_stock'] ?? null, 0, self::MAX_STOCK, 'max_stock'),
            'demand_factor' => self::factor($in['demand_factor'] ?? null),
            'pack_rounding' => null,
            'do_not_reorder' => 0,
            'note' => self::text($in['note'] ?? null, 500, 'note'),
        ];
        $r = $in['pack_rounding'] ?? null;
        if ($r !== null && $r !== '') {
            if (!in_array($r, ['up', 'nearest'], true)) {
                throw new CwException('bad_field', 'pack rounding: up or nearest', 400, ['field' => 'pack_rounding']);
            }
            $out['pack_rounding'] = $r;
        }
        $d = $in['do_not_reorder'] ?? null;
        if ($d !== null && $d !== '' && $d !== '0' && $d !== 0 && $d !== false) {
            if ($d !== '1' && $d !== 1 && $d !== true) {
                throw new CwException('bad_field', 'do not reorder: yes or no', 400, ['field' => 'do_not_reorder']);
            }
            $out['do_not_reorder'] = 1;
        }
        if ($out['min_stock'] !== null && $out['max_stock'] !== null && $out['max_stock'] < $out['min_stock']) {
            throw new CwException('bad_field', 'the maximum stock is below the minimum stock', 422, ['field' => 'max_stock']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @return array{demand_factor: ?string, safety_days: ?int, note: ?string}
     */
    public static function brandFields(array $in): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::BRAND_FIELDS, true)) {
                throw new CwException('bad_field', 'brand settings are ' . implode(', ', self::BRAND_FIELDS), 400, ['field' => mb_substr((string) $k, 0, 40)]);
            }
        }
        return [
            'demand_factor' => self::factor($in['demand_factor'] ?? null),
            'safety_days' => self::int($in['safety_days'] ?? null, 0, 90, 'safety_days'),
            'note' => self::text($in['note'] ?? null, 500, 'note'),
        ];
    }

    /** A demand factor 0.00..5.00 (at most 2 decimals), as the DECIMAL(4,2) string; null when empty. */
    public static function factor(mixed $v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');
        if (preg_match('/^(\d)(?:\.(\d{1,2}))?$/D', $s, $m) !== 1 || ((int) $m[1] === 5 && (int) ($m[2] ?? 0) > 0) || (int) $m[1] > 5) {
            throw new CwException('bad_field', 'demand factor: a number from 0 to 5 with at most 2 decimals (0.85 = 15% less demand)', 422, ['field' => 'demand_factor']);
        }
        return $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
    }

    /** @param array<string, mixed>|null $row */
    private static function checkVersion(?array $row, int $version): void
    {
        $have = $row === null ? 0 : (int) $row['version'];
        if ($have !== $version) {
            throw new CwException('version_conflict', 'these settings changed since this page was drawn: reload it and try again', 409,
                ['version' => $have, 'expected_version' => $version]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function pick(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $row[$f];
            $out[$f] = match ($f) {
                'do_not_reorder' => (int) $v,
                'demand_factor', 'note', 'pack_rounding' => $v === null ? null : (string) $v,
                default => $v === null ? null : (int) $v,
            };
        }
        return $out;
    }

    private static function int(mixed $v, int $min, int $max, string $field): ?int
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (is_string($v) && preg_match('/^\s*(\d{1,10})\s*$/D', $v, $m) === 1) {
            $v = (int) $m[1];
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new CwException('bad_field', str_replace('_', ' ', $field) . ": a whole number from {$min} to " . number_format($max), 422, ['field' => $field]);
        }
        return $v;
    }

    private static function text(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new CwException('bad_field', "{$field}: text", 400, ['field' => $field]);
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field}: at most {$max} characters", 422, ['field' => $field]);
        }
        return $v === '' ? null : $v;
    }

    private static function date(mixed $v, string $field): string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim($v)) !== 1
            || \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v), Clock::utc())?->format('Y-m-d') !== trim($v)) {
            throw new CwException('bad_field', str_replace('_', ' ', $field) . ': a date, YYYY-MM-DD', 422, ['field' => $field]);
        }
        return trim($v);
    }
}
