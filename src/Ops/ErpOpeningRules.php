<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\CwException;
use CW\Movements;
use CW\Settings;

/**
 * The rules of the ERPNext opening import (pack I, docs/decisions.md PI1-PI6), each an `erp_opening.*` setting (0039; changed on
 * Settings with a reason, never in code):
 *
 *  - warehouses        the ERPNext warehouses whose stock is CW's opening, one name per line (only these are read: PI2);
 *  - companies         the ERPNext companies whose rows are read when the export has a Company column, one per line (empty: the
 *                      company is not checked, the warehouse list alone decides);
 *  - blocked_names     names that are NEVER read, whatever the two lists say: a company or warehouse whose name contains one of them,
 *                      spaces and letter case ignored ("VPG 2" also stops "VPG 2 Main warehouse - VPG2"); a list above that names
 *                      one is refused as a setting mistake (PI2);
 *  - below_zero        what an item whose ERPNext figure (after the sales still on their way) is below zero opens at: `zero`, or
 *                      `carry` the minus into CW (PI4; the plan's D36 A and B);
 *  - placeholder_costs the valuation rates that are only placeholders (pounds, comma-separated; a blank rate always is): never used
 *                      as a cost, the item is listed for re-costing (PI5);
 *  - listing_store     the store whose variant numbers ERPNext's items carry (`product_id`), through whose links an ERPNext item
 *                      reaches a CW item (PI3; empty: only the ERPNext item links of CW resolve);
 *  - cw_warehouse      the CW warehouse the opening is booked at (PI1);
 *  - require_pending_sales  an --apply needs the file of the units sold but not yet in ERPNext (an empty file says "none") (PI6).
 */
final class ErpOpeningRules
{
    public const BELOW_ZERO = ['zero', 'carry'];
    public const KEYS = ['erp_opening.warehouses', 'erp_opening.companies', 'erp_opening.blocked_names', 'erp_opening.below_zero',
        'erp_opening.placeholder_costs', 'erp_opening.listing_store', 'erp_opening.cw_warehouse', 'erp_opening.require_pending_sales'];

    /**
     * @param list<string> $warehouses
     * @param list<string> $companies
     * @param list<string> $blocked
     * @param list<string> $placeholders 6-decimal amounts
     */
    public function __construct(
        public readonly array $warehouses,
        public readonly array $companies,
        public readonly array $blocked,
        public readonly string $belowZero,
        public readonly array $placeholders,
        public readonly string $listingStore,
        public readonly string $cwWarehouse,
        public readonly bool $requirePendingSales,
    ) {
        $bad = static fn (string $why): CwException => new CwException('bad_setting', $why, 409);
        if ($warehouses === []) {
            throw $bad('no ERPNext warehouse is set to be read (erp_opening.warehouses): set the main warehouse first');
        }
        if (!in_array($belowZero, self::BELOW_ZERO, true)) {
            throw $bad('erp_opening.below_zero must be zero or carry');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $cwWarehouse) !== 1) {
            throw $bad('erp_opening.cw_warehouse must name a CW warehouse code, like MAIN');
        }
        foreach ([...$warehouses, ...$companies] as $name) {
            if (($hit = $this->blockedIn($name)) !== null) {
                throw $bad("the ERPNext name \"{$name}\" is listed to be read but contains the blocked name \"{$hit}\" (erp_opening.blocked_names): "
                    . 'it is never read; take it off the list');
            }
        }
    }

    public static function fromSettings(Settings $s): self
    {
        $lines = static fn (string $key): array => self::lines((string) $s->get($key));
        $placeholders = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $s->get('erp_opening.placeholder_costs'))), static fn (string $v): bool => $v !== '') as $v) {
            $placeholders[] = Movements::normaliseCost($v, 'erp_opening.placeholder_costs');
        }
        $below = (string) $s->get('erp_opening.below_zero');
        return new self($lines('erp_opening.warehouses'), $lines('erp_opening.companies'), $lines('erp_opening.blocked_names'),
            $below === '' ? 'zero' : $below, $placeholders, trim((string) $s->get('erp_opening.listing_store')),
            trim((string) $s->get('erp_opening.cw_warehouse')), $s->get('erp_opening.require_pending_sales') === true);
    }

    /** @return list<string> the non-empty lines of a text setting, trimmed, each once */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '' && !in_array(self::key($l), array_map(self::key(...), $out), true)) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /** How two ERPNext names are compared: letter case and runs of white space do not matter (ERPNext's own names ignore case). */
    public static function key(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * How two ERPNext ITEM CODES are compared (review of 14 Oct 2026, PI18): letter case and the spaces at the ends do not matter (ERPNext
     * keeps names case-insensitive and trims them), but the spaces inside do: "ABC DEF" and "ABC  DEF" are two items. key() above, which
     * also folds inner spaces, is for warehouse and company names only.
     */
    public static function itemKey(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    /** The blocked name $name contains (spaces and case ignored), or null. */
    public function blockedIn(string $name): ?string
    {
        $flat = (string) preg_replace('/\s+/u', '', mb_strtolower($name));
        foreach ($this->blocked as $b) {
            $needle = (string) preg_replace('/\s+/u', '', mb_strtolower($b));
            if ($needle !== '' && str_contains($flat, $needle)) {
                return $b;
            }
        }
        return null;
    }

    public function allowsWarehouse(string $name): bool
    {
        return in_array(self::key($name), array_map(self::key(...), $this->warehouses), true);
    }

    /** Whether a company passes: always when no company is listed (the warehouse decides), else when it is listed. */
    public function allowsCompany(string $name): bool
    {
        return $this->companies === [] || in_array(self::key($name), array_map(self::key(...), $this->companies), true);
    }

    /** Whether a 6-decimal rate is one of the placeholder amounts. */
    public function isPlaceholder(string $rate): bool
    {
        foreach ($this->placeholders as $p) {
            if (bccomp($p, $rate, 6) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> the rules in force, for the run's summary */
    public function toArray(): array
    {
        return ['warehouses' => $this->warehouses, 'companies' => $this->companies, 'blocked_names' => $this->blocked, 'below_zero' => $this->belowZero,
            'placeholder_costs' => $this->placeholders, 'listing_store' => $this->listingStore, 'cw_warehouse' => $this->cwWarehouse,
            'require_pending_sales' => $this->requirePendingSales];
    }
}
