<?php

declare(strict_types=1);

namespace CW;

use CW\Company\CompanyDetails;

/**
 * CW's typed settings (app_setting, 0009; docs/decisions.md I38-I41): the supplier approval rules (decision 11), the cost
 * write-back switch (decision 12) and, from the later I-2 tasks, the PO and reorder defaults; since 0019 the approval switches
 * (approvals.*: Admin\ApprovalRules), the spot-check size and the staff set-up window. Since 0019 (the set-it-yourself pack, Y4)
 * the Settings page changes them (change(): an admin or a reviewer, settings.manage, with a reason; every change is a version in
 * config_change and an audit row); bin/settings.php --admin keeps working through the same code (set()). Rows are read once per
 * instance (a request or a job sees one consistent set).
 *
 * The company details of the PO letterhead (decision 9) were company.* settings until 0013; they are now versions in
 * company_profile, added and confirmed by staff on the Company details screen (CW\Company\CompanyDetails, I90-I99).
 * company() reads them from there, and set() refuses a company.* key with a pointer to the screen.
 *
 * value_json holds a JSON string, number or boolean; "" means "not set" (an empty placeholder: get() returns '' for the
 * text types and null for int, decimal and date). A decimal is kept as a JSON string (its digits exactly as given; a
 * seeded JSON number is read through its text), and get() returns it as a decimal string: never a float.
 */
final class Settings
{
    public const TYPES = ['string', 'text', 'int', 'decimal', 'bool', 'date'];
    /** Where the company details are changed since 0013 (I91). */
    public const COMPANY_SCREEN = '/ui/reference/company';
    public const STRING_MAX = 255;
    public const TEXT_MAX = 4000;

    /**
     * Key-specific checks on top of the type (parse()): `min`/`max` (an int or a decimal string), `vat_code` (the code
     * must exist in vat_code and be active), `email` (a valid address or empty), `in` (one of the listed values, exactly),
     * `pattern` (a regular expression the text must match; `pattern_says` words it for the refusal), `list_in` (a list of the listed
     * values separated by commas, each once, in any order, or empty; `words` names the Ui\Words group the screens show them in).
     * Every key ending in `_days` is a day count from 0 to 120 unless listed here.
     */
    public const RULES = [
        'suppliers.approval_due_days' => ['min' => 1, 'max' => 120],
        'po.default_vat_code' => ['vat_code' => true],
        'po.over_delivery_tolerance_pct' => ['min' => 0, 'max' => 200],
        'reorder.short_weight' => ['min' => '0', 'max' => '1'],
        'reorder.promo_price_drop' => ['min' => '0', 'max' => '1'],
        'reorder.promo_units_uplift' => ['min' => '1', 'max' => '100'],
        'reorder.promo_min_units' => ['min' => 0, 'max' => 1_000_000],
        'reorder.spike_cap_multiple' => ['min' => 1, 'max' => 100],
        'reorder.spike_cap_floor' => ['min' => 0, 'max' => 1_000_000],
        // The windows and their fewest valid days (review nit, I82): bounded as ReorderSettings::params() reads them, so a
        // value the tool accepts never makes every reorder list and build fail with 500 bad_setting.
        'reorder.short_window_days' => ['min' => 1, 'max' => 120, 'at_least' => ['reorder.min_valid_days_short'], 'at_most' => ['reorder.long_window_days']],
        'reorder.long_window_days' => ['min' => 1, 'max' => 120, 'at_least' => ['reorder.short_window_days', 'reorder.min_valid_days_long']],
        'reorder.min_valid_days_short' => ['min' => 1, 'max' => 120, 'at_most' => ['reorder.short_window_days']],
        'reorder.min_valid_days_long' => ['min' => 1, 'max' => 120, 'at_most' => ['reorder.long_window_days']],
        // Receiving (IM6, 0017; I125-I147): the duty rate behind the expected duty shown, how far back a paper sheet may be dated,
        // the mode an Out-Of-Stock item gets when its previous mode is not known (never Out-Of-Stock: the delivery is for sale).
        'receiving.duty_pence_per_ml' => ['min' => 1, 'max' => 1000],
        'receiving.backdate_max_days' => ['min' => 0, 'max' => 120],
        'receiving.mode_after_out_of_stock' => ['in' => ['In-Stock', 'From-Warehouse']],
        // The site stock writer (IM10, 0018; I148-I166): the sites a receipt's selling mode lands on, as channel codes.
        'site_writer.receipt_mode_sites' => ['pattern' => '/^[a-z][a-z0-9_]{0,31}(,[a-z][a-z0-9_]{0,31})*$/D',
            'pattern_says' => 'channel codes separated by commas, like vapeandgo,electrofag (empty: none)'],
        // The set-it-yourself pack (0019, Y5-Y8): the spot-check size (KeySample), the staff set-up window, the reviewers asked for.
        'approvals.spot_check_size' => ['min' => 5, 'max' => 200],
        // At most a week (review finding I5): a set-up window open longer is a longer chance to guess.
        'staff.setup_hours' => ['min' => 1, 'max' => 168],
        'staff.setup_max_fails' => ['min' => 1, 'max' => 20],
        'staff.min_reviewers' => ['min' => 1, 'max' => 10],
        // The address printed on the sign-up sheets (review nit: never the Host header a request happened to carry).
        'staff.sign_in_address' => ['pattern' => '#^https?://[A-Za-z0-9.-]{1,200}(:[0-9]{1,5})?$#D',
            'pattern_says' => 'the address of the sign-in page without a path, like https://warehouse.example.com'],
        // Bulk action on the matching screens (0021, M46): the match strengths whose ticked rows may be confirmed together (the
        // others stay one at a time; empty: none), and the most rows one bulk action takes (a page holds 50).
        'mapping.bulk_confirm_bands' => ['list_in' => \CW\Mapping\Proposals::BANDS, 'words' => 'BAND'],
        'mapping.bulk_max_rows' => ['min' => 1, 'max' => 500],
    ];
    /** The default rule of a day count (a key ending in `_days`). */
    public const DAYS_RULE = ['min' => 0, 'max' => 120];

    /** @var array<string, array<string, mixed>>|null setting_key => row */
    private ?array $rows = null;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The typed value of a setting: string|int|decimal string|bool|'Y-m-d' (null for an unset int, decimal or date; ''
     * for an unset string or text). An unknown key is a programming error (\LogicException).
     */
    public function get(string $key): mixed
    {
        $row = $this->rows()[$key] ?? throw new \LogicException("unknown setting {$key}");
        return self::decode((string) $row['value_type'], (string) $row['value_json']);
    }

    /** Whether the setting exists (a later task's key on an older schema does not). */
    public function has(string $key): bool
    {
        return isset($this->rows()[$key]);
    }

    /**
     * The company that buys and owns the warehouse stock (decision 9, provisional): what a PO letterhead prints, from the
     * version of company_profile in use (CW\Company\CompanyDetails::company(), read on every call, never cached). Empty
     * strings until someone provides them; confirmed false until someone confirms them; vat_registered null until someone
     * says; version 0 when there is no version at all.
     *
     * @return array{legal_name: string, trading_name: string, address: string, company_number: string, vat_number: string, phone: string, email: string, delivery_address: string, confirmed: bool, vat_registered: ?bool, version: int}
     */
    public function company(): array
    {
        return (new CompanyDetails($this->db))->company();
    }

    /**
     * Every setting for the screen and the CLI, key order: key, type, value (typed), display (text), provisional,
     * decision, description, updated_actor, updated_at.
     *
     * @return list<array{key: string, type: string, value: mixed, display: string, provisional: bool, decision: ?string, description: string, updated_actor: string, updated_at: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rows() as $key => $r) {
            $value = self::decode((string) $r['value_type'], (string) $r['value_json']);
            $out[] = [
                'key' => $key,
                'type' => (string) $r['value_type'],
                'value' => $value,
                'display' => self::display($value),
                'provisional' => (int) $r['provisional'] === 1,
                'decision' => $r['decision'] === null ? null : (string) $r['decision'],
                'description' => (string) $r['description'],
                'updated_actor' => (string) $r['updated_actor'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }
        return $out;
    }

    /** A typed value as people read it: true/false, '' as "(not set)". */
    public static function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null, $value === '' => '(not set)',
            default => (string) $value,
        };
    }

    /**
     * Parses what a person typed for a setting of $type (bin/settings.php), or throws CwException 400 bad_value:
     *   int      ^-?\d{1,9}$
     *   decimal  ^\d{1,8}(\.\d{1,6})?$ (returned as the string given)
     *   bool     true | false
     *   string   at most 255 characters, one line
     *   text     at most 4000 characters, line breaks allowed (CRLF read as LF)
     *   date     a valid Y-m-d
     * An empty value is "not set" for string, text, int, decimal and date ('' / null); a bool is always true or false.
     */
    public static function parse(string $type, string $raw): mixed
    {
        $bad = static fn (string $why): CwException => new CwException('bad_value', $why, 400, ['type' => $type]);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw $bad('the value is not valid UTF-8 text');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            throw $bad('the value contains control characters');
        }
        switch ($type) {
            case 'string':
                if (preg_match('/[\r\n]/', $raw) === 1) {
                    throw $bad('a string setting is one line (use a text setting for several)');
                }
                $v = trim($raw);
                if (mb_strlen($v) > self::STRING_MAX) {
                    throw $bad('a string setting is at most ' . self::STRING_MAX . ' characters');
                }
                return $v;
            case 'text':
                $v = trim(str_replace("\r\n", "\n", $raw));
                if (str_contains($v, "\r")) {
                    throw $bad('a text setting uses line feeds, not bare carriage returns');
                }
                if (mb_strlen($v) > self::TEXT_MAX) {
                    throw $bad('a text setting is at most ' . self::TEXT_MAX . ' characters');
                }
                return $v;
            case 'int':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^-?\d{1,9}$/D', $v) !== 1) {
                    throw $bad('an int setting is a whole number of at most 9 digits');
                }
                return (int) $v;
            case 'decimal':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^\d{1,8}(\.\d{1,6})?$/D', $v) !== 1) {
                    throw $bad('a decimal setting is a number like 0.50 (at most 8 digits before the point and 6 after, no sign)');
                }
                return $v;
            case 'bool':
                $v = strtolower(trim($raw));
                if ($v !== 'true' && $v !== 'false') {
                    throw $bad('a bool setting is true or false');
                }
                return $v === 'true';
            case 'date':
                $v = trim($raw);
                if ($v === '') {
                    return null;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1
                    || \DateTimeImmutable::createFromFormat('!Y-m-d', $v, Clock::utc())?->format('Y-m-d') !== $v) {
                    throw $bad('a date setting is a real date, YYYY-MM-DD');
                }
                return $v;
            default:
                throw new \InvalidArgumentException("unknown setting type {$type}");
        }
    }

    /**
     * The key-specific rule of a setting (RULES; `_days` keys 0..120), checked on a parsed value: 400 bad_value.
     * Needs the database for `vat_code`.
     */
    public function checkRule(string $key, mixed $value): void
    {
        $rule = self::RULES[$key] ?? (str_ends_with($key, '_days') ? self::DAYS_RULE : []);
        if ($rule === [] || $value === null || $value === '') {
            return;
        }
        $bad = static fn (string $why): CwException => new CwException('bad_value', "{$key}: {$why}", 400, ['key' => $key]);
        if (isset($rule['min']) || isset($rule['max'])) {
            if (is_int($value)) {
                if (isset($rule['min']) && $value < (int) $rule['min'] || isset($rule['max']) && $value > (int) $rule['max']) {
                    throw $bad("must be from {$rule['min']} to {$rule['max']}");
                }
            } elseif (is_string($value) && preg_match('/^\d+(\.\d+)?$/D', $value) === 1) {
                if (isset($rule['min']) && self::cmpDecimal($value, (string) $rule['min']) < 0
                    || isset($rule['max']) && self::cmpDecimal($value, (string) $rule['max']) > 0) {
                    throw $bad("must be from {$rule['min']} to {$rule['max']}");
                }
            }
        }
        if (($rule['vat_code'] ?? false) === true) {
            if ($this->db->value('SELECT 1 FROM vat_code WHERE code = ? AND is_active = 1', [(string) $value]) === null) {
                throw $bad('there is no active VAT code ' . mb_substr((string) $value, 0, 8));
            }
        }
        if (($rule['email'] ?? false) === true && filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
            throw $bad('must be an e-mail address');
        }
        if (isset($rule['in']) && !in_array($value, $rule['in'], true)) {
            throw $bad('must be one of ' . implode(', ', $rule['in']));
        }
        if (isset($rule['pattern']) && preg_match($rule['pattern'], (string) $value) !== 1) {
            throw $bad('must be ' . ($rule['pattern_says'] ?? 'in the expected form'));
        }
        if (isset($rule['list_in'])) {
            $items = explode(',', (string) $value);
            if (array_diff($items, $rule['list_in']) !== [] || count(array_unique($items)) !== count($items)) {
                throw $bad('must be some of ' . implode(', ', $rule['list_in']) . ', separated by commas, each once');
            }
        }
        // Against other settings (whole numbers): at least / at most their current values.
        foreach (['at_least' => 1, 'at_most' => -1] as $kind => $sign) {
            foreach ($rule[$kind] ?? [] as $other) {
                $o = $this->has($other) ? $this->get($other) : null;
                if (is_int($o) && is_int($value) && ($value <=> $o) === -$sign) {
                    throw $bad('must be ' . ($sign === 1 ? 'at least' : 'at most') . " {$other} ({$o})");
                }
            }
        }
    }

    /**
     * Changes one setting the way bin/settings.php does (a system caller): $confirm also marks it as agreed by the owner
     * (provisional = 0); otherwise the agreed mark stays as it is. See change().
     *
     * @return array{changed: bool, before: mixed, after: mixed, version: int}
     */
    public function set(Caller $caller, string $key, string $raw, string $reason, bool $confirm = false): array
    {
        return $this->change($caller, $key, $raw, $reason, $confirm ? true : null, null);
    }

    /**
     * Changes one setting (the Settings page and bin/settings.php; Y4). $raw is parsed for the key's type and checked against its
     * rule; $agreed true marks the value agreed by the owner (provisional = 0), false marks it not agreed yet, null keeps the mark.
     * $seen: the setting's history version the form was drawn with (409 changed_meanwhile when someone changed it since; null:
     * no check, the CLI). A staff caller needs settings.manage (an admin or a reviewer, re-read inside the transaction); a CLI tool
     * (system caller) may. Writes value_json, provisional, updated_actor (the caller) and updated_at, the next config_change
     * version (`change`, or `agree` / `unagree` when only the mark moved) and audit setting.change {key, before, after, reason,
     * version, loosened when an approval rule became looser}. Nothing is written when neither the value nor the mark changes. A
     * company.* key is refused (400 company_details): the company details have their own screen and history since 0013 (I91).
     * Loosening an approval rule (Admin\ApprovalRules::loosens: a switch off, a smaller spot check) needs a Reviewer (403
     * loosen_needs_reviewer, review finding I1); tightening it, or only marking it agreed, needs settings.manage as any change.
     *
     * @return array{changed: bool, before: mixed, after: mixed, version: int}
     */
    public function change(Caller $caller, string $key, string $raw, string $reason, ?bool $agreed = null, ?int $seen = null): array
    {
        if (str_starts_with($key, 'company.')) {
            throw new CwException('company_details', 'the company details are no longer settings: a reviewer adds, changes and confirms them on the '
                . 'Company details screen (Reference > Company details, ' . self::COMPANY_SCREEN . '), which keeps every version', 400);
        }
        $reason = \CW\Admin\ConfigHistory::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $key, $raw, $reason, $agreed, $seen): array {
            \CW\Admin\ConfigHistory::authorise($db, $caller);
            $row = $db->one('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS value_json, provisional FROM app_setting WHERE setting_key = ? FOR UPDATE', [$key])
                ?? throw new CwException('unknown_setting', 'there is no setting ' . mb_substr($key, 0, 64), 400);
            \CW\Admin\ConfigHistory::checkSeen($db, 'setting', $key, $seen);
            $type = (string) $row['value_type'];
            $after = self::parse($type, $raw);
            $this->checkRule($key, $after);
            $before = self::decode($type, (string) $row['value_json']);
            $json = self::encode($type, $after);
            $was = (int) $row['provisional'];
            $provisional = $agreed === null ? $was : ($agreed ? 0 : 1);
            $version = \CW\Admin\ConfigHistory::version($db, 'setting', $key);
            if ($before === $after && $provisional === $was) {
                return ['changed' => false, 'before' => $before, 'after' => $after, 'version' => $version];
            }
            $loosened = \CW\Admin\ApprovalRules::loosens($key, $before, $after);
            if ($loosened) {
                \CW\Admin\ApprovalRules::authoriseLoosening($db, $caller);
            }
            $old = \CW\Admin\ConfigHistory::state($db, 'setting', $key);
            $db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ?, updated_at = NOW(6) WHERE setting_key = ?',
                [$json, $provisional, $caller->actor, $key]);
            $new = \CW\Admin\ConfigHistory::state($db, 'setting', $key) ?? throw new \LogicException("setting {$key} vanished");
            $action = $before !== $after ? 'change' : ($provisional === 0 ? 'agree' : 'unagree');
            $v = \CW\Admin\ConfigHistory::record($db, $caller, 'setting', $key, $action, $old ?? $new, $new, $reason);
            Audit::write($db, $caller, 'setting.change', 'app_setting', $key, null, ['key' => $key, 'before' => $before, 'after' => $after, 'reason' => $reason,
                'version' => $v['version']] + ($provisional !== $was ? ($provisional === 0 ? ['confirmed' => true] : ['agreed' => false]) : [])
                + ($loosened ? ['loosened' => true] : []));
            $this->rows = null;
            return ['changed' => true, 'before' => $before, 'after' => $after, 'version' => $v['version']];
        });
    }

    /**
     * A bool setting read straight from the database (the approval switches, Admin\ApprovalRules): $default when the row does not
     * exist (a schema before 0019) or is not a bool. Read on every call: a switch changed on the screen applies to the next action.
     */
    public static function flag(Db $db, string $key, bool $default): bool
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'bool'", [$key]);
        return $v === null ? $default : trim((string) $v) === 'true';
    }

    /**
     * A list setting (a `list_in` rule) read straight from the database: its values, in the rule's order; $default when the row
     * does not exist (a schema before its migration). An empty value is an empty list. A stored value the rule no longer allows is
     * left out (fail closed: a list of what is allowed).
     *
     * @param list<string> $default
     * @return list<string>
     */
    public static function listOf(Db $db, string $key, array $default): array
    {
        $allowed = self::RULES[$key]['list_in'] ?? throw new \LogicException("{$key} is not a list setting");
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'", [$key]);
        if ($v === null) {
            return $default;
        }
        $text = json_decode((string) $v, false, 4);
        $items = is_string($text) && $text !== '' ? explode(',', $text) : [];
        return array_values(array_filter($allowed, static fn (string $a): bool => in_array($a, $items, true)));
    }

    /** An int setting read straight from the database: $default when the row does not exist, is not an int or is not set. */
    public static function number(Db $db, string $key, int $default): int
    {
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'int'", [$key]);
        return $v === null || preg_match('/^-?\d{1,9}$/D', trim((string) $v)) !== 1 ? $default : (int) trim((string) $v);
    }

    /** The JSON a typed value is stored as ("" for not set; a decimal as a JSON string). */
    public static function encode(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? 'true' : 'false',
            'int' => $value === null ? '""' : (string) (int) $value,
            default => Idempotency::json($value === null ? '' : (string) $value),
        };
    }

    /** The typed value of a stored JSON text (decode of encode(), plus seeded JSON numbers for decimals). */
    public static function decode(string $type, string $json): mixed
    {
        $v = json_decode($json, false, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        return match ($type) {
            'bool' => $v === true,
            'int' => $v === '' || $v === null ? null : (int) $v,
            'decimal' => $v === '' || $v === null ? null : (is_string($v) ? $v : trim($json)),
            'date' => $v === '' || $v === null ? null : (string) $v,
            default => $v === null ? '' : (string) $v,
        };
    }

    /** <0, 0, >0 for two non-negative decimal strings (no floats). */
    public static function cmpDecimal(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $ai = ltrim($ai, '0');
        $bi = ltrim($bi, '0');
        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) <=> strlen($bi);
        }
        $w = max(strlen($af), strlen($bf));
        return strcmp($ai . str_pad($af, $w, '0'), $bi . str_pad($bf, $w, '0')) <=> 0;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->db->all('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS value_json, provisional, decision, description, updated_actor, updated_at '
                . 'FROM app_setting ORDER BY setting_key') as $r) {
                $this->rows[(string) $r['setting_key']] = $r;
            }
        }
        return $this->rows;
    }
}
