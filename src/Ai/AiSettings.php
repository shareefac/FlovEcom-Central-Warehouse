<?php

declare(strict_types=1);

namespace CW\Ai;

use CW\Db;
use CW\Settings;

/**
 * The settings of "Claude reads the upload" (0082; docs/decisions.md AI7), read straight from app_setting on every use (a switch changed on the
 * Settings screen counts for the next upload and the next reading): the switch (ai.read_uploads, off until a person switches it on), the
 * Anthropic API key (a SECRET setting: never shown back, never logged; empty = off), the model, the effort, the month's limit in pounds and the
 * model's two prices in pounds per million tokens (CW counts each call's tokens at them). Reading is on only with the switch on AND a key; a
 * schema before 0082 has no rows: off.
 */
final class AiSettings
{
    public const ON = 'ai.read_uploads';
    public const KEY = 'ai.anthropic_api_key';
    public const MODEL = 'ai.model';
    public const EFFORT = 'ai.effort';
    public const LIMIT = 'ai.monthly_limit_gbp';
    public const PRICE_IN = 'ai.price_input_gbp_per_mtok';
    public const PRICE_OUT = 'ai.price_output_gbp_per_mtok';
    /** The keys 0082 seeds, in the order the screens list them. */
    public const KEYS = [self::ON, self::KEY, self::MODEL, self::EFFORT, self::LIMIT, self::PRICE_IN, self::PRICE_OUT];
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    private function __construct(
        public readonly bool $switch,
        #[\SensitiveParameter] public readonly string $key,
        public readonly string $model,
        public readonly string $effort,
        /** the month's limit in pence (30.00 -> 3000) */
        public readonly int $limitPence,
        /** pounds per million input / output tokens, as decimal strings */
        public readonly string $priceIn,
        public readonly string $priceOut,
    ) {
    }

    public static function read(Db $db): self
    {
        $raw = [];
        foreach ($db->all('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS value_json FROM app_setting WHERE setting_key IN ('
            . implode(', ', array_fill(0, count(self::KEYS), '?')) . ')', self::KEYS) as $r) {
            $raw[(string) $r['setting_key']] = Settings::decode((string) $r['value_type'], (string) $r['value_json']);
        }
        $text = static fn (string $k, string $default): string => is_string($raw[$k] ?? null) && $raw[$k] !== '' ? $raw[$k] : $default;
        $effort = $text(self::EFFORT, 'medium');
        return new self(
            ($raw[self::ON] ?? false) === true,
            is_string($raw[self::KEY] ?? null) ? trim($raw[self::KEY]) : '',
            $text(self::MODEL, self::DEFAULT_MODEL),
            in_array($effort, self::EFFORTS, true) ? $effort : 'medium',
            self::pence($text(self::LIMIT, '0')),
            $text(self::PRICE_IN, '0'),
            $text(self::PRICE_OUT, '0'),
        );
    }

    /** Reading is on: the switch on, a key set, and both prices above 0 (M3). */
    public function on(): bool
    {
        return $this->off() === null;
    }

    /** M3 (Codex final of 86aebbd): a price of 0 (or none) would count paid readings as nothing: reading is off until both are above 0. */
    public function priced(): bool
    {
        $positive = static fn (string $p): bool => preg_match('/^\d{1,8}(\.\d{1,6})?$/D', $p) === 1 && bccomp($p, '0', 6) > 0;
        return $positive($this->priceIn) && $positive($this->priceOut);
    }

    /** Why reading is off, as a code (AI words: off_switch, off_key, off_price), or null when it is on. */
    public function off(): ?string
    {
        return !$this->switch ? 'off_switch' : ($this->key === '' ? 'off_key' : (!$this->priced() ? 'off_price' : null));
    }

    /** "30" / "30.5" / "30.00" (pounds) as pence; 0 for anything that is not an amount. */
    /** The key's setting as 0082 makes it (its description). */
    public const KEY_DESCRIPTION = 'The Anthropic API key CW uses for Claude (a secret: never shown back). Empty: Claude reads nothing';

    /**
     * The key's row typed back to text after an undo had typed it a number (High 2, the re-check of ca8987b; docs/decisions.md AI19: the
     * release before takes no key into a number), its 0082 description back; its value and history untouched. Run by this release's own
     * bin/migrate.php, which the installer runs with the writers paused, so no one can type into it between. True when it changed the row.
     */
    public static function restoreKeyRow(Db $db): bool
    {
        return $db->exec("UPDATE app_setting SET value_type = 'string', description = ? WHERE setting_key = ? AND value_type <> 'string' "
            . "AND JSON_UNQUOTE(value_json) = ''", [self::KEY_DESCRIPTION, self::KEY]) > 0;
    }

    public static function pence(string $gbp): int
    {
        if (preg_match('/^(\d{1,8})(?:\.(\d{1,6}))?$/D', trim($gbp), $m) !== 1) {
            return 0;
        }
        return (int) $m[1] * 100 + (int) substr(str_pad($m[2] ?? '', 2, '0'), 0, 2);
    }
}
