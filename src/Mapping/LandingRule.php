<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Matching\Text;

/**
 * Is a website product a LANDING PAGE (docs/decisions.md LP1-LP12): the row of a product page where the customer picks an option
 * (10mg or 20mg, a flavour, a colour), which the shops keep as one more "option" of the product but which is not something that is
 * sold. The owner (21-22 Oct 2026): "Landing pages are not real products. Even if you see any sale on it, that only means something
 * is broken". Pure: the caller reads the rows (CW\Mapping\LandingPages).
 *
 * TWO WAYS TO KNOW, in this order (decide()):
 *  1. THE WEBSITE'S OWN WORD (`website_flag`): the stored website state of the variant (SiteState, 0028) says `is_landing` and is
 *     fresh (not older than mapping.arrival_state_max_days). The shops keep it as their own flag (prodt_islanding); a push carries it in
 *     the optional object `website` (connector 0.4.7 and later), an export load carries it from the file. A fresh state that says
 *     "not a landing row" is the website's word too: the rule below is then never asked (`website_says_no`).
 *  2. THE RULE (`same_page`, version VERSION), only when the website's word is not known (no state, or an old one), and only when it
 *     is CERTAIN. Every one of these must hold:
 *     a. no barcode (a real option has one or may have one; a page has none);
 *     b. no option words of its own: its option title is empty or the product's title;
 *     c. no option value: no attribute the website marks as the one the customer chooses (`is_variable`) holds a real value (the
 *        prompt "Choose a Flavour", "Choose an option", "Select" is the page's, not a value: the Normalizer drops it too); attributes
 *        that carry no such mark at all leave the rule unable to tell (`attributes_unmarked`);
 *     d. at least one OTHER website product of the same store and the same product page IS an option: the same product title, an
 *        option title of its own and an option value. "The same product page": the website's product number (site_product_id) when
 *        the state holds it; else the web address, the option's being the page's with "/<option>" after it.
 *     A real product with one option has no other option on its page, so (d) never holds for it: it is never called a landing page.
 *
 * Measured on the shops' own exports (22 Oct 2026, /root/cw_work/LANDING_PAGES_DESIGN.md): see the design file.
 */
final class LandingRule
{
    /** The rule's version: a mark records it, so a later rule can be told from this one (a re-check never flips a mark back). */
    public const VERSION = 'lp1';
    /** Why a variant is a landing page (match_decision.detail `landing_reason`; the words: Ui\Words::LANDING_REASON). */
    public const WEBSITE_FLAG = 'website_flag';
    public const SAME_PAGE = 'same_page';
    public const REASONS = [self::WEBSITE_FLAG, self::SAME_PAGE];
    /** Why the rule does not call a variant a landing page (the dry run's counts). */
    public const NOT = ['website_says_no', 'has_barcode', 'option_title', 'option_value', 'attributes_unmarked', 'no_option_sibling'];
    /** The prompt a page shows in place of a value (the Normalizer's own list, n2.1). */
    private const PROMPT = '/^(?:choose an?\b|choose\b|select\b|please\s+(?:select|choose)|-+$|n\/?a$)/u';

    /**
     * The answer for one variant.
     *
     * @param array<string, mixed> $row the variant's profile: product_title, variant_title, attributes (JSON text or decoded),
     *        barcodes (JSON text, a list or null), perma_link, site_product_id; and its website state: `website` = SiteState::read()'s
     *        answer, `state_fresh` = whether that stored state is not too old
     * @param list<array<string, mixed>> $siblings the other website products of the same store and the same page (same shape; their
     *        own website state is not read): LandingPages::siblings()
     * @return array{landing: bool, reason: ?string, not: list<string>, evidence: array<string, mixed>}
     */
    public static function decide(array $row, array $siblings): array
    {
        $state = is_array($row['website'] ?? null) ? $row['website'] : [];
        $stored = ($state['source'] ?? null) === SiteState::STORED && ($row['state_fresh'] ?? false) === true;
        if ($stored && ($state['is_landing'] ?? null) === true) {
            return ['landing' => true, 'reason' => self::WEBSITE_FLAG, 'not' => [], 'evidence' => ['as_of' => $state['as_of'] ?? null,
                'product_id' => $state['product_id'] ?? null]];
        }
        if ($stored && ($state['is_landing'] ?? null) === false) {
            return ['landing' => false, 'reason' => null, 'not' => ['website_says_no'], 'evidence' => []];
        }
        $not = [];
        if (self::codes($row['barcodes'] ?? null) !== []) {
            $not[] = 'has_barcode';
        }
        if (self::optionTitle($row)) {
            $not[] = 'option_title';
        }
        $attrs = self::attributes($row['attributes'] ?? null);
        if ($attrs['marked'] === false && $attrs['items'] !== []) {
            $not[] = 'attributes_unmarked';
        } elseif (self::optionValue($attrs)) {
            $not[] = 'option_value';
        }
        $options = [];
        $title = Text::squash(Text::clean((string) ($row['product_title'] ?? '')));
        foreach ($siblings as $s) {
            if ($title !== '' && Text::squash(Text::clean((string) ($s['product_title'] ?? ''))) === $title && self::optionTitle($s)
                && self::optionValue(self::attributes($s['attributes'] ?? null))) {
                $options[] = isset($s['listing_id']) ? (int) $s['listing_id'] : null;
            }
        }
        if ($options === []) {
            $not[] = 'no_option_sibling';
        }
        if ($not !== []) {
            return ['landing' => false, 'reason' => null, 'not' => $not, 'evidence' => []];
        }
        $options = array_values(array_filter($options, static fn (?int $i): bool => $i !== null));
        sort($options);
        return ['landing' => true, 'reason' => self::SAME_PAGE, 'not' => [], 'evidence' => ['rule' => self::VERSION,
            'options' => array_slice($options, 0, 10), 'options_count' => count($options),
            'page' => ($row['site_product_id'] ?? null) !== null && (string) $row['site_product_id'] !== '' ? 'product_id' : 'web_address']];
    }

    /** Whether a variant has option words of its own: an option title that is not empty and not the product's title. */
    public static function optionTitle(array $row): bool
    {
        $v = Text::squash(Text::clean((string) ($row['variant_title'] ?? '')));
        return $v !== '' && $v !== Text::squash(Text::clean((string) ($row['product_title'] ?? '')));
    }

    /**
     * The attributes of a profile as the rule reads them: each item's name, value and whether the website marks it as the one the
     * customer chooses (`is_variable`: true, false, or null when the item says nothing). `marked`: at least one item says it.
     * Shapes: {"items": [{name, value, is_variable}, ...]} (the exports and the connector), a plain list of such items, or an object
     * of name => value (no marks).
     *
     * @return array{items: list<array{name: string, value: string, variable: ?bool}>, marked: bool}
     */
    public static function attributes(mixed $a): array
    {
        if (is_string($a)) {
            $a = json_decode($a, true);
        }
        if (!is_array($a) || $a === []) {
            return ['items' => [], 'marked' => false];
        }
        $list = isset($a['items']) && is_array($a['items']) ? $a['items'] : (array_is_list($a) ? $a : null);
        $items = [];
        $marked = false;
        if ($list !== null) {
            foreach ($list as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $flag = $it['is_variable'] ?? null;
                $variable = match (true) {
                    $flag === true || $flag === 1 || $flag === '1' => true,
                    $flag === false || $flag === 0 || $flag === '0' => false,
                    default => null,
                };
                $marked = $marked || $variable !== null;
                $items[] = ['name' => is_scalar($it['name'] ?? null) ? (string) $it['name'] : '', 'value' => is_scalar($it['value'] ?? null) ? (string) $it['value'] : '',
                    'variable' => $variable];
            }
            return ['items' => $items, 'marked' => $marked];
        }
        foreach ($a as $k => $v) {
            $items[] = ['name' => (string) $k, 'value' => is_scalar($v) ? (string) $v : '', 'variable' => null];
        }
        return ['items' => $items, 'marked' => false];
    }

    /** Whether the attributes hold a value the customer chose: an item marked variable whose value is not empty and not a prompt. */
    public static function optionValue(array $attrs): bool
    {
        foreach ($attrs['items'] as $it) {
            if ($it['variable'] === true && !self::prompt($it['value'])) {
                return true;
            }
        }
        return false;
    }

    /** "Choose a Flavour", "Select", "-", "" : what a page shows in place of a value. */
    public static function prompt(string $value): bool
    {
        $v = Text::lower(Text::clean($value));
        return trim($v) === '' || preg_match(self::PROMPT, trim($v)) === 1;
    }

    /** @return list<string> */
    private static function codes(mixed $codes): array
    {
        if (is_string($codes)) {
            $codes = json_decode($codes, true);
        }
        if (!is_array($codes)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (mixed $c): string => is_scalar($c) ? trim((string) $c) : '', $codes), static fn (string $c): bool => $c !== ''));
    }

    /**
     * The web address of the page a variant's address hangs under ("brand-product/10mg" -> "brand-product"), lower case, without
     * the closing "/"; null when the address has no "/" in it (it is a page itself, or no address).
     */
    public static function parentAddress(?string $perma): ?string
    {
        $p = self::address($perma);
        if ($p === null || !str_contains($p, '/')) {
            return null;
        }
        return substr($p, 0, (int) strrpos($p, '/'));
    }

    /** A web address as the rule compares it: trimmed, lower case, without the closing "/" (ArrivalRules::permaKey(), lower case). */
    public static function address(?string $perma): ?string
    {
        $p = mb_strtolower(rtrim(trim((string) $perma), '/'));
        return $p === '' ? null : $p;
    }
}
