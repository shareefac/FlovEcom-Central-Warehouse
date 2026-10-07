<?php

declare(strict_types=1);

namespace CW\Catalogue;

/**
 * The legal rules of an item card (IM3; docs/decisions.md I102, I103). Pure: no database, no clock.
 *
 * TRPR 2016 reg 36 (the UK's Tobacco and Related Products Regulations) and the single-use ban (Environmental Protection
 * (Single-use Vapes) Regulations, from 1 June 2025):
 *
 *   trpr_refill_ml  a refill container of NICOTINE liquid (e-liquid, shortfill, nic shot) holds more than 10 ml
 *   trpr_tank_ml    a tank or cartridge (tank, prefilled pod, device / kit, single-use) holds more than 2 ml
 *   trpr_nicotine   the liquid has more than 20 mg/ml of nicotine (any product type)
 *   single_use      a person said the item is single-use (never inferred from a "disposable" form)
 *
 * "Over" is strictly over: 10.0 ml, 2.0 ml and 20 mg/ml are allowed. A rule needs its values: an unknown ml or strength
 * breaks nothing (missing() lists what a confirmation needs), and the type-bound rules need the product type.
 *
 * What a breach does (status(), I103 as amended by I113): the rules a person CONFIRMED the card breaks block the item, and keep
 * blocking it until a person confirms the card again (item_card.confirmed_breaches, kept while the card is "changed since it was
 * confirmed"): emptying, retyping or correcting a field never lifts a block by itself, a new confirmation does. Every other rule
 * the values break is a WARNING (a card nobody has confirmed, or a rule broken by an edit since the last confirmation), so a
 * block always rests on a person's acknowledged confirmation. Today a block stops the reorder suggestion, "create draft PO" and
 * the approval of a purchase order; receiving (IM6) and the site stock writer (IM10) will ask ItemCompliance when they are built.
 * Values are compared as integers (tenths of a ml, hundredths of a mg), never floats.
 */
final class ItemRules
{
    /** Product types (item_card.product_type ENUM, 0016) => what people read. */
    public const TYPES = [
        'e_liquid' => 'e-liquid',
        'shortfill' => 'shortfill',
        'nic_shot' => 'nicotine shot',
        'prefilled_pod' => 'prefilled pod',
        'device_kit' => 'device / kit',
        'single_use' => 'single-use vape',
        'coil' => 'coil',
        'tank' => 'tank',
        'accessory' => 'accessory',
    ];
    /** Refill containers: the 10 ml limit applies when they hold nicotine. */
    public const REFILL_TYPES = ['e_liquid', 'shortfill', 'nic_shot'];
    /** Tanks and cartridges (a tank, a pod, a device's or a single-use vape's): the 2 ml capacity limit. */
    public const TANK_TYPES = ['tank', 'prefilled_pod', 'device_kit', 'single_use'];
    /** Products that hold vaping liquid: duty-liable from 1 Oct 2026 (VPD, FA 2026 s.115-116), nicotine-free included. */
    public const LIQUID_TYPES = ['e_liquid', 'shortfill', 'nic_shot', 'prefilled_pod', 'single_use'];
    /** Products that never hold liquid. */
    public const DRY_TYPES = ['coil', 'tank', 'accessory'];

    public const REFILL_MAX_ML_TENTHS = 100;    // 10.0 ml
    public const TANK_MAX_ML_TENTHS = 20;       // 2.0 ml
    public const NICOTINE_MAX_MG_HUNDREDTHS = 2000; // 20.00 mg/ml

    /** Rule code => the short label shown on tags and in lists. */
    public const RULES = [
        'trpr_refill_ml' => 'nicotine refill over 10 ml',
        'trpr_tank_ml' => 'tank or pod over 2 ml',
        'trpr_nicotine' => 'nicotine over 20 mg/ml',
        'single_use' => 'single-use vape',
    ];
    /** Rule code => the sentence that says why. */
    public const WHY = [
        'trpr_refill_ml' => 'A refill container of nicotine liquid may hold at most 10 ml (TRPR 2016 reg 36).',
        'trpr_tank_ml' => 'A tank or cartridge may hold at most 2 ml (TRPR 2016 reg 36).',
        'trpr_nicotine' => 'Vaping liquid may contain at most 20 mg/ml of nicotine (TRPR 2016 reg 36).',
        'single_use' => 'Single-use vapes may not be sold or supplied in the UK (since 1 June 2025).',
    ];
    /** The fields a confirmation may need => what people read (missing()). */
    public const NEEDED = [
        'product_type' => 'product type',
        'duty_liable' => 'duty-liable (yes or no)',
        'liquid_ml' => 'liquid ml',
        'nicotine_mg' => 'nicotine mg/ml',
        'single_use' => 'single-use (yes or no)',
    ];

    /**
     * The rules the card's values break, in RULES order.
     *
     * @param array<string, mixed> $card item_card values as stored (decimals as strings, flags as 0/1/null)
     * @return list<string> rule codes
     */
    public static function breaches(array $card): array
    {
        $type = self::str($card['product_type'] ?? null);
        $ml = self::scaled($card['liquid_ml'] ?? null, 1);
        $mg = self::scaled($card['nicotine_mg'] ?? null, 2);
        $out = [];
        if ($type !== null && in_array($type, self::REFILL_TYPES, true) && $mg !== null && $mg > 0 && $ml !== null && $ml > self::REFILL_MAX_ML_TENTHS) {
            $out[] = 'trpr_refill_ml';
        }
        if ($type !== null && in_array($type, self::TANK_TYPES, true) && $ml !== null && $ml > self::TANK_MAX_ML_TENTHS) {
            $out[] = 'trpr_tank_ml';
        }
        if ($mg !== null && $mg > self::NICOTINE_MAX_MG_HUNDREDTHS) {
            $out[] = 'trpr_nicotine';
        }
        if (self::flag($card['single_use'] ?? null) === 1 || $type === 'single_use') {
            $out[] = 'single_use';
        }
        return $out;
    }

    /**
     * What the rules do to the card now (I113):
     *   blocked   the rules the LAST confirmation acknowledged (`confirmed_breaches`, kept until the next confirmation) and,
     *             while the card is confirmed, every rule its values break;
     *   warnings  the rules its values break that do not block: nobody has confirmed the card, or an edit since the last
     *             confirmation broke them (they block once a person confirms the card with them);
     *   level     'block' (something is blocked), 'warn' (warnings only) or null.
     * Both lists in RULES order. The card needs `confirmed_at` and `confirmed_breaches` (a list, or the JSON of one) besides
     * the values; without them it reads as never confirmed.
     *
     * @param array<string, mixed> $card
     * @return array{level: ?string, blocked: list<string>, warnings: list<string>}
     */
    public static function status(array $card): array
    {
        $now = self::breaches($card);
        $held = self::confirmedBreaches($card['confirmed_breaches'] ?? null);
        $confirmed = ($card['confirmed_at'] ?? null) !== null && ($card['confirmed_at'] ?? null) !== '';
        $blocked = [];
        $warnings = [];
        foreach (array_keys(self::RULES) as $r) {
            if (in_array($r, $held, true) || ($confirmed && in_array($r, $now, true))) {
                $blocked[] = $r;
            } elseif (in_array($r, $now, true)) {
                $warnings[] = $r;
            }
        }
        return ['level' => $blocked !== [] ? 'block' : ($warnings !== [] ? 'warn' : null), 'blocked' => $blocked, 'warnings' => $warnings];
    }

    /** status()['level']. @param array<string, mixed> $card */
    public static function level(array $card): ?string
    {
        return self::status($card)['level'];
    }

    /** Whether the card was confirmed at least once (`first_confirmed_at`, never cleared). @param array<string, mixed> $card */
    public static function enforced(array $card): bool
    {
        $f = $card['first_confirmed_at'] ?? null;
        return $f !== null && $f !== '';
    }

    /**
     * The rule codes of item_card.confirmed_breaches as stored (JSON text), typed (a list) or null.
     *
     * @return list<string>
     */
    public static function confirmedBreaches(mixed $v): array
    {
        if (is_string($v)) {
            $v = $v === '' ? null : json_decode($v, true);
        }
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (mixed $r): string => is_scalar($r) ? (string) $r : '', $v), static fn (string $r): bool => $r !== ''));
    }

    /**
     * The fields a person must fill in before the card can be confirmed (NEEDED keys, in that order): the product type and
     * the duty answer always; the liquid ml and the strength for liquids, pods and single-use vapes; the capacity of a tank;
     * the capacity of its tank or pod (0 = it comes without one) and the single-use answer for a device / kit (I115).
     *
     * @param array<string, mixed> $card
     * @return list<string>
     */
    public static function missing(array $card): array
    {
        $type = self::str($card['product_type'] ?? null);
        $need = ['product_type', 'duty_liable'];
        if ($type !== null && (in_array($type, self::REFILL_TYPES, true) || in_array($type, ['prefilled_pod', 'single_use'], true))) {
            array_push($need, 'liquid_ml', 'nicotine_mg');
        }
        if ($type === 'tank') {
            $need[] = 'liquid_ml';
        }
        if ($type === 'device_kit') {
            array_push($need, 'liquid_ml', 'single_use');
        }
        $out = [];
        foreach (array_keys(self::NEEDED) as $k) {
            if (in_array($k, $need, true) && self::blank($card[$k] ?? null)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** What people read for a field missing() names, on a card of $type (a kit's ml is the capacity of its tank, 0 = none). */
    public static function neededLabel(string $field, ?string $type): string
    {
        if ($field === 'liquid_ml' && $type === 'device_kit') {
            return 'liquid ml (what its tank or pod holds; 0 when it comes without one)';
        }
        return self::NEEDED[$field] ?? $field;
    }

    /**
     * Things worth a second look that never block (shown on the card): duty answered "no" on a product that holds vaping
     * liquid, "yes" on one that never does, and an ECID that is not in the usual 12345-16-12345 shape.
     *
     * @param array<string, mixed> $card
     * @return list<string>
     */
    public static function advice(array $card): array
    {
        $type = self::str($card['product_type'] ?? null);
        $duty = self::flag($card['duty_liable'] ?? null);
        $out = [];
        if ($type !== null && in_array($type, self::LIQUID_TYPES, true) && $duty === 0) {
            $out[] = 'Duty-liable is "no", but a ' . self::TYPES[$type] . ' holds vaping liquid: Vaping Products Duty applies to all vaping liquids '
                . 'from 1 Oct 2026, nicotine-free included.';
        }
        if ($type !== null && in_array($type, self::DRY_TYPES, true) && $duty === 1) {
            $out[] = 'Duty-liable is "yes", but a ' . self::TYPES[$type] . ' holds no vaping liquid: check it.';
        }
        $ecid = self::str($card['ecid'] ?? null);
        if ($ecid !== null && preg_match('/^[0-9]{5}-[0-9]{2}-[0-9]{5}$/D', $ecid) !== 1) {
            $out[] = 'The ECID is not in the usual shape 12345-16-12345: check it against the box or the MHRA list.';
        }
        return $out;
    }

    /** Short labels of rule codes, joined ("tank or pod over 2 ml, nicotine over 20 mg/ml"). @param list<string> $rules */
    public static function labels(array $rules): string
    {
        return implode(', ', array_map(static fn (string $r): string => self::RULES[$r] ?? $r, $rules));
    }

    /**
     * The same rules as breaches() in SQL, on an item_card alias: 1 when the card's values break at least one, else 0 (NULLs read
     * as "unknown": they break nothing). ItemCardsTest::testTheSqlRuleMatchesThePhpRule checks it against breaches() card by card.
     */
    public static function sqlBreach(string $alias): string
    {
        self::alias($alias);
        $in = static fn (array $types): string => "'" . implode("','", $types) . "'";
        $a = $alias;
        return "COALESCE(({$a}.single_use = 1 OR {$a}.product_type = 'single_use' OR {$a}.nicotine_mg > " . self::NICOTINE_MAX_MG_HUNDREDTHS / 100
            . " OR ({$a}.product_type IN (" . $in(self::REFILL_TYPES) . ") AND {$a}.nicotine_mg > 0 AND {$a}.liquid_ml > " . self::REFILL_MAX_ML_TENTHS / 10 . ')'
            . " OR ({$a}.product_type IN (" . $in(self::TANK_TYPES) . ") AND {$a}.liquid_ml > " . self::TANK_MAX_ML_TENTHS / 10 . ')), 0)';
    }

    /** status()['level'] = 'block' in SQL (1 / 0), on an item_card alias: rules held by the last confirmation, or a confirmed card breaking one. */
    public static function sqlBlocked(string $alias): string
    {
        self::alias($alias);
        return "(COALESCE(JSON_LENGTH({$alias}.confirmed_breaches), 0) > 0 OR ({$alias}.confirmed_at IS NOT NULL AND " . self::sqlBreach($alias) . ' = 1))';
    }

    /** status()['level'] !== null in SQL (1 / 0): blocked, or breaking a rule now. */
    public static function sqlFlagged(string $alias): string
    {
        return '(' . self::sqlBlocked($alias) . ' OR ' . self::sqlBreach($alias) . ' = 1)';
    }

    /**
     * A decimal string as an integer of 10^-$decimals ("10.0", 1 -> 100; "20", 2 -> 2000); null for null, '' or a string that is
     * not a plain non-negative decimal. A float, a bool or anything else is refused (\InvalidArgumentException): a rule must never
     * be skipped silently because a caller computed a value (pass the stored decimal string).
     */
    public static function scaled(mixed $v, int $decimals): ?int
    {
        if (is_int($v)) {
            $v = (string) $v;
        }
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new \InvalidArgumentException('a card value is a decimal string (as stored), not ' . get_debug_type($v));
        }
        if (preg_match('/^([0-9]{1,9})(?:\.([0-9]+))?$/D', $v, $m) !== 1) {
            return null;
        }
        $frac = substr(str_pad($m[2] ?? '', $decimals, '0'), 0, $decimals);
        if (strlen(rtrim(substr($m[2] ?? '', $decimals), '0')) > 0) {
            return null; // more precision than the column holds: not a stored value
        }
        return (int) $m[1] * 10 ** $decimals + ($decimals > 0 ? (int) $frac : 0);
    }

    private static function alias(string $alias): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('bad alias');
        }
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function flag(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return (int) $v === 1 ? 1 : ((string) $v === '0' ? 0 : null);
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || $v === '';
    }
}
