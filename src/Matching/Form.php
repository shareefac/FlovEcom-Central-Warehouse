<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * The product form enum, in one place (run3 follow-up (d), docs/decisions.md M29). Normalizer extracts it, Veto compares it,
 * JudgeCard shows it to the judge and the judge prompt (judge_v2.md, "form: one of ...") asks for it back, all with the
 * same values:
 *
 *   form      one of ALL (e.g. pod_kit), never a phrase ("Prefilled Pod Kit") or a label ("pod_kit (prefilled)");
 *   form_sub  one of SUBS or null, a separate field (prefilled vs refillable, longfill, nicotine_pouch, ...);
 *   class     CLASS_OF[form]: forms in one class may be the same physical item under another label (the form veto
 *             compares classes, then prefilled vs refillable).
 *
 * canonical() maps the free text people and judges write ("prefilled pod kit", "e-liquid", "vape_kit", "replacement
 * pods") onto the enum; run3's judges answered 22 different spellings for 14 values.
 *
 * Pure: no I/O, no state.
 */
final class Form
{
    public const ALL = [
        'disposable', 'prefilled_pod', 'pod_kit', 'refill_pod_cartridge', 'e_liquid', 'nic_salt', 'shortfill',
        'nic_shot', 'coil', 'tank', 'kit', 'battery', 'accessory', 'other',
    ];

    /** Form -> veto class. */
    public const CLASS_OF = [
        'disposable' => 'device', 'pod_kit' => 'device', 'kit' => 'device',
        'prefilled_pod' => 'pod_refill', 'refill_pod_cartridge' => 'pod_refill',
        'e_liquid' => 'liquid', 'nic_salt' => 'liquid', 'shortfill' => 'liquid',
        'nic_shot' => 'nic_shot', 'coil' => 'coil', 'tank' => 'tank', 'battery' => 'battery',
        'accessory' => 'accessory', 'other' => 'other',
    ];

    /** form_sub values Normalizer emits (its internal 'conflict' never leaves it: it becomes null + an internal conflict). */
    public const SUBS = [
        'prefilled', 'refillable', 'longfill', 'inferred_from_volume', 'nicotine_pouch', 'heated_tobacco', 'nicotine_strip',
    ];

    /** Forms that carry a flavour of their own (a pod kit only when prefilled). */
    public const FLAVOURED = ['e_liquid', 'nic_salt', 'shortfill', 'nic_shot', 'disposable', 'prefilled_pod'];

    /**
     * Free-text spellings -> [form, form_sub]. Keys are normalised by key(): lower case, "_" "-" "/" as spaces, one space,
     * plurals of the last word folded ("pods" -> "pod", "pouches" -> "pouch", "batteries" -> "battery"). A phrase that spells an enum value ("prefilled pods", "Nic Salt")
     * is that value with no sub, like the value itself; these are the other spellings, run3's judges' first.
     */
    private const SPELLINGS = [
        'prefilled pod kit' => ['pod_kit', 'prefilled'], 'prefilled pod vape kit' => ['pod_kit', 'prefilled'],
        'pre filled pod kit' => ['pod_kit', 'prefilled'],
        'refillable pod kit' => ['pod_kit', 'refillable'], 'open pod kit' => ['pod_kit', 'refillable'],
        'pod kit' => ['pod_kit', null], 'vape pod kit' => ['pod_kit', null], 'pod vape kit' => ['pod_kit', null],
        'pod system' => ['pod_kit', null], 'pod mod' => ['pod_kit', null],
        'kit' => ['kit', null], 'vape kit' => ['kit', null], 'starter kit' => ['kit', null], 'mod kit' => ['kit', null],
        'mod' => ['kit', null], 'box mod' => ['kit', null], 'device' => ['kit', null],
        'pre filled pod' => ['prefilled_pod', 'prefilled'],
        'refill pod' => ['prefilled_pod', 'prefilled'], 'pod refill' => ['prefilled_pod', 'prefilled'],
        'refill pack' => ['prefilled_pod', 'prefilled'], 'pod' => ['prefilled_pod', null],
        'replacement pod' => ['refill_pod_cartridge', null], 'cartridge' => ['refill_pod_cartridge', null],
        'refill pod cartridge' => ['refill_pod_cartridge', null], 'pod cartridge' => ['refill_pod_cartridge', null],
        'refillable pod' => ['refill_pod_cartridge', 'refillable'], 'empty pod' => ['refill_pod_cartridge', 'refillable'],
        'refillable cartridge' => ['refill_pod_cartridge', 'refillable'], 'empty cartridge' => ['refill_pod_cartridge', 'refillable'],
        'e liquid' => ['e_liquid', null], 'eliquid' => ['e_liquid', null], 'freebase' => ['e_liquid', null],
        'freebase e liquid' => ['e_liquid', null], 'vape juice' => ['e_liquid', null], 'e juice' => ['e_liquid', null],
        'nic salt' => ['nic_salt', null], 'nic salt e liquid' => ['nic_salt', null], 'nicotine salt' => ['nic_salt', null],
        'nicotine salt e liquid' => ['nic_salt', null], 'salt' => ['nic_salt', null], 'nicsalt' => ['nic_salt', null],
        'short fill' => ['shortfill', null], 'shortfill e liquid' => ['shortfill', null],
        'longfill' => ['shortfill', 'longfill'], 'long fill' => ['shortfill', 'longfill'],
        'nicotine shot' => ['nic_shot', null], 'nic booster' => ['nic_shot', null], 'booster shot' => ['nic_shot', null],
        'disposable vape' => ['disposable', 'prefilled'], 'prefilled vape' => ['disposable', 'prefilled'],
        'disposable vape kit' => ['disposable', 'prefilled'],
        'replacement coil' => ['coil', null], 'sub ohm tank' => ['tank', null], 'atomiser' => ['tank', null],
        'atomizer' => ['tank', null], 'clearomiser' => ['tank', null], 'clearomizer' => ['tank', null],
        'nicotine pouch' => ['other', 'nicotine_pouch'], 'pouch' => ['other', 'nicotine_pouch'],
        'heated tobacco' => ['other', 'heated_tobacco'], 'nicotine strip' => ['other', 'nicotine_strip'],
    ];

    public static function isForm(?string $form): bool
    {
        return $form !== null && in_array($form, self::ALL, true);
    }

    /** The veto class of a form, or null (unknown form). */
    public static function classOf(?string $form): ?string
    {
        return $form !== null ? (self::CLASS_OF[$form] ?? null) : null;
    }

    /** A flavoured consumable: liquid, nic shot, disposable, prefilled pod, or a prefilled pod kit. */
    public static function flavoured(?string $form, ?string $sub): bool
    {
        return in_array($form, self::FLAVOURED, true) || ($form === 'pod_kit' && $sub === 'prefilled');
    }

    /** One display form for both parts ("pod_kit/prefilled", "nic_salt"); null form = "unknown". */
    public static function label(?string $form, ?string $sub = null): string
    {
        return ($form ?? 'unknown') . ($sub !== null ? '/' . $sub : '');
    }

    /**
     * The enum value of a free-text form, or null when it names none. Accepts the enum itself, its spellings
     * (SPELLINGS), and the old JudgeCard label "pod_kit (prefilled)" / label() "pod_kit/prefilled".
     *
     * @return ?array{form: string, form_sub: ?string}
     */
    public static function canonical(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim(Text::lower(Text::clean($raw)));
        if (in_array($raw, self::ALL, true)) {
            return ['form' => $raw, 'form_sub' => null];
        }
        // "pod_kit (prefilled)" and "pod_kit/prefilled": an enum value with its sub
        if (preg_match('/^([a-z_]+)\s*(?:\(\s*([a-z_]+)\s*\)|\/\s*([a-z_]+))$/', $raw, $m) && in_array($m[1], self::ALL, true)) {
            $sub = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            return ['form' => $m[1], 'form_sub' => in_array($sub, self::SUBS, true) ? $sub : null];
        }
        $k = self::key($raw);
        if ($k === '') {
            return null;
        }
        foreach (self::ALL as $f) {
            if ($k === self::key($f)) {
                return ['form' => $f, 'form_sub' => null];
            }
        }
        if (isset(self::SPELLINGS[$k])) {
            return ['form' => self::SPELLINGS[$k][0], 'form_sub' => self::SPELLINGS[$k][1]];
        }
        return null;
    }

    /** Lower case, separators as one space, the last word's plural folded ("Prefilled-Pods" -> "prefilled pod"). */
    private static function key(string $s): string
    {
        $s = trim(preg_replace('/[\s_\-\/]+/u', ' ', Text::lower($s)) ?? '');
        if ($s === '') {
            return '';
        }
        $w = explode(' ', $s);
        $last = (string) array_pop($w);
        if (strlen($last) > 4 && str_ends_with($last, 'ies')) {
            $last = substr($last, 0, -3) . 'y';                       // batteries, accessories
        } elseif (preg_match('/(?:ch|sh|x|ss)es$/', $last) === 1) {
            $last = substr($last, 0, -2);                             // pouches
        } elseif (strlen($last) > 3 && str_ends_with($last, 's') && !str_ends_with($last, 'ss')) {
            $last = substr($last, 0, -1);                             // pods, kits, coils
        }
        $w[] = $last;
        return implode(' ', $w);
    }
}
