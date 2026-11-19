<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;
use CW\Matching\Form;
use CW\Matching\Gtin;
use CW\Matching\Normalizer;
use CW\Matching\Text;

/**
 * Which warehouse items a store's new variant may be (tracker CW-1138; docs/decisions.md SX1-SX8): read only, for the store's
 * Existing item box (GET /v1/item-suggestions) and for the store choice job's last look before it makes an item (StoreChoices,
 * outcome same_features).
 *
 * The new variant is described as the store knows it at Add product (its product name, its own option text, the brand, the
 * option names and values, its barcodes): card() reads it as the matching reads a listing (Normalizer, then
 * DecisionService::cardFrom), so it compares with the items' own identity cards (sku.brand, flavour, strength_mg, volume_ml,
 * form, nic_type, line, puffs, pack_units).
 *
 * The order of the suggestions (at most MAX, items not joined into another; one found through a joined item counts as the item
 * it is now):
 *   barcode       a barcode of the variant (any form one scan may take, Gtin::forms) on an item, or on a store variant linked to
 *                 one (the indexes of sku_barcode and listing_profile.barcodes; never a scan);
 *   same_details  the same brand family, flavour, strength, size and kind (sameDetails(): strict, every known field agrees);
 *   similar_name  most of the name's words in the item's name (a Dice score of the words, at least MIN_SIMILAR).
 * `strong`: the first one is a barcode or the same details (the store then shows "CW already has this").
 */
final class ItemSuggestions
{
    public const MAX = 5;
    public const WHY = ['code', 'barcode', 'same_details', 'similar_name'];
    /** The candidates a same-details look reads (the kind, strength, brand and a flavour word narrow it to a handful in practice). */
    private const DETAIL_POOL = 500;
    /** The best name matches the database finds (by words found) before they are scored here. */
    private const NAME_POOL = 80;
    /** The fewest words a name candidate shares, and the lowest score shown. */
    private const NAME_WORDS = 6;
    private const MIN_SIMILAR = 0.5;
    /** Words that say nothing about which item it is (beyond Normalizer::STOPWORDS). */
    /** Normalizer::brandTokens()'s one fold ("r & m", "r and m", "r n m" -> randm), as MySQL's ICU REGEXP_REPLACE reads it. */
    private const RANDM = '\\br\\s*(and|&|n)\\s*m\\b';
    private const NAME_NOISE = ['and', 'by', 'the', 'of', 'with', 'e', 'liquid', 'eliquid', 'nic', 'salt', 'salts', 'nicotine', 'vape', 'vapes', 'new', 'uk', 'x'];

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------------------------------
    // The store's question (GET /v1/item-suggestions)
    // ------------------------------------------------------------------------------------------

    /**
     * The suggestions for a new variant, and (with $code) what that CW number is.
     *
     * @param array{title?: string, option?: string, brand?: string, attrs?: list<array{name: string, value: string}>, barcodes?: list<string>} $in
     * @return array{suggestions: list<array<string, mixed>>, strong: bool, item?: ?array<string, mixed>}
     */
    public function suggest(int $channelId, array $in, ?string $code = null, int $limit = self::MAX): array
    {
        $limit = max(1, min(self::MAX, $limit));
        $out = [];
        if ($code !== null) {
            $out['item'] = $this->lookup($channelId, $code);
        }
        $found = [];
        foreach ($this->byBarcode($in['barcodes'] ?? []) as $id => $barcode) {
            $found[$id] ??= ['why' => 'barcode', 'barcode' => $barcode];
        }
        $hasText = trim(($in['title'] ?? '') . ($in['option'] ?? '')) !== '';
        if ($hasText && count($found) < $limit) {
            $card = self::card(self::profile($in));
            foreach ($this->sameDetailsItems($card, $limit) as $id) {
                $found[$id] ??= ['why' => 'same_details'];
            }
            if (count($found) < $limit) {
                foreach ($this->similarNames($card, $limit + count($found)) as $id) {
                    $found[$id] ??= ['why' => 'similar_name'];
                }
            }
        }
        $found = array_slice($found, 0, $limit, true);
        $items = $this->items(array_keys($found), $channelId);
        $list = [];
        foreach ($found as $id => $f) {
            if (isset($items[$id])) {
                $list[] = $items[$id] + ['why' => $f['why']] + (isset($f['barcode']) ? ['barcode' => $f['barcode']] : []);
            }
        }
        return $out + ['suggestions' => $list, 'strong' => $list !== [] && in_array($list[0]['why'], ['barcode', 'same_details'], true)];
    }

    /**
     * A CW number as people type it: "CW-011468", "cw-11468", "CW 11468" or "11468" -> "CW-011468"; null when it is not one (letters,
     * more than 9 digits, CW-000000). The stores' connector reads it the same way (cw_choices_code).
     */
    public static function code(mixed $typed): ?string
    {
        if (!is_string($typed) || strlen($typed) > 40) {
            return null;
        }
        if (preg_match('/^\s*(?:cw\s*-?\s*)?0*([0-9]{1,9})\s*$/iD', $typed, $m) !== 1 || (int) $m[1] === 0) {
            return null;
        }
        return sprintf('CW-%06d', (int) $m[1]);
    }

    /**
     * The item a CW number names, as it is now (followed to the item it was joined into: `named` says the number asked), with
     * its name and free to sell for the store; null when there is no such item.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(int $channelId, string $code): ?array
    {
        $id = $this->db->value('SELECT id FROM sku WHERE code = ?', [$code]);
        if ($id === null) {
            return null;
        }
        $root = $this->rootOf((int) $id);
        $item = $this->items([$root], $channelId)[$root] ?? null;
        if ($item === null) {
            return null;
        }
        return $item + ['why' => 'code'] + ($root !== (int) $id ? ['named' => $code] : []);
    }

    // ------------------------------------------------------------------------------------------
    // The variant as the matching reads it
    // ------------------------------------------------------------------------------------------

    /**
     * What the store knows of a new variant as a profile row (listing_profile's columns): the option's title is the product's
     * name and the option text, as the stores name a variant ("Elux Legend Lemon & Lime Nic Salt E-liquid 10ml - 5mg").
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function profile(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $option = trim((string) ($in['option'] ?? ''));
        $items = [];
        foreach ($in['attrs'] ?? [] as $a) {
            $items[] = ['name' => (string) ($a['name'] ?? ''), 'value' => (string) ($a['value'] ?? ''), 'is_variable' => 1];
        }
        return ['product_title' => $title === '' ? $option : $title, 'variant_title' => $option === '' ? $title : ($title === '' ? $option : $title . ' - ' . $option),
            'brand' => trim((string) ($in['brand'] ?? '')), 'attributes' => json_encode(['items' => $items]), 'barcodes' => json_encode(array_values($in['barcodes'] ?? []))];
    }

    /**
     * A profile's identity card, read afresh (Normalizer, then DecisionService::cardFrom: the fields an item made from it would
     * get), with what the comparison needs besides: the brand family words, the line's numbers and modifiers, the name's words.
     *
     * @param array<string, mixed> $p a profile row (product_title, variant_title, brand, attributes and barcodes as JSON text)
     * @return array<string, mixed>
     */
    public static function card(array $p): array
    {
        $attrs = is_string($p['attributes'] ?? null) ? json_decode((string) $p['attributes'], true) : null;
        $attrs = is_array($attrs) ? (is_array($attrs['items'] ?? null) ? $attrs['items'] : (array_is_list($attrs) ? $attrs : [])) : [];
        $codes = is_string($p['barcodes'] ?? null) ? json_decode((string) $p['barcodes'], true) : null;
        try {
            $f = Normalizer::normalize(['site' => '', 'variant_id' => 0, 'product_title' => (string) ($p['product_title'] ?? ''),
                'variant_title' => (string) ($p['variant_title'] ?? ''), 'brand' => (string) ($p['brand'] ?? ''), 'attributes' => $attrs,
                'barcodes' => is_array($codes) ? array_values($codes) : [], 'price' => null, 'sale_price' => null, 'units_30d' => 0]);
        } catch (\Throwable) {
            $f = [];
        }
        $card = DecisionService::cardFrom($p, $f);
        $name = (string) ($card['name'] ?? '');
        return $card + [
            'family' => self::family((string) ($card['brand'] ?? ''), (string) ($card['line'] ?? '')),
            'line_numbers' => self::lineNumbers((string) ($card['line'] ?? '')),
            'modifiers' => self::modifiers((string) ($card['line'] ?? '')),
            'words' => self::words($name),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // The same details (also StoreChoices' look before it makes an item)
    // ------------------------------------------------------------------------------------------

    /**
     * Items (not joined into another) with the same details as $card (sameDetails()), the closest name first, at most $limit.
     * Reads only items of the same strength and kind that hold its longest flavour word: one pass over the items' cards.
     *
     * @param array<string, mixed> $card card()
     * @return list<int>
     */
    public function sameDetailsItems(array $card, int $limit = 1): array
    {
        $flavour = self::flavourSet($card['flavour'] ?? null);
        $class = Form::classOf(is_string($card['form'] ?? null) ? $card['form'] : null);
        if ($flavour === [] || $class === null || $card['family'] === []) {
            return [];
        }
        $forms = array_keys(array_filter(Form::CLASS_OF, static fn (string $c): bool => $c === $class));
        // One pass over the items' cards, narrowed in the database to the same kind and strength, a brand family word in the brand or
        // the range, and a flavour word (a stem: "raspberr" finds raspberry and raspberries); sameDetails() then compares it all.
        $like = static fn (string $w): string => '%' . self::like($w) . '%';
        $fam = array_slice($card['family'], 0, 8);
        $flav = array_slice($flavour, 0, 6);
        $sql = 'SELECT id, code, name, brand, strength_mg, nic_type, line, form, flavour, volume_ml, puffs, pack_units FROM sku '
            . 'WHERE merged_into_sku_id IS NULL AND form IN (' . implode(',', array_fill(0, count($forms), '?')) . ') '
            // the brand as Normalizer::brandTokens() reads it ("R&M" / "R and M" is "randm"), so this narrows exactly as sameDetails() compares
            . 'AND (' . implode(' OR ', array_fill(0, count($fam), "REGEXP_REPLACE(LOWER(COALESCE(brand, '')), ?, 'randm') LIKE ? OR line LIKE ?")) . ') '
            . 'AND (' . implode(' OR ', array_fill(0, count($flav), 'flavour LIKE ?')) . ') AND ';
        $params = $forms;
        foreach ($fam as $w) {
            array_push($params, self::RANDM, $like($w), $like($w));
        }
        foreach ($flav as $w) {
            $params[] = $like(substr($w, 0, max(4, strlen($w) - 2)));
        }
        if ($card['strength_mg'] === null) {
            $sql .= 'strength_mg IS NULL';
        } else {
            $sql .= 'strength_mg = ?';
            $params[] = number_format((float) $card['strength_mg'], 2, '.', '');
        }
        $hits = [];
        foreach ($this->db->all($sql . ' ORDER BY id LIMIT ' . self::DETAIL_POOL, $params) as $s) {
            if (self::sameDetails($card, $s)) {
                $hits[(int) $s['id']] = self::dice($card['words'], self::words((string) $s['name']));
            }
        }
        // the closest name first, then the oldest item
        uksort($hits, static fn (int $a, int $b): int => [$hits[$b], $a] <=> [$hits[$a], $b]);
        return array_slice(array_keys($hits), 0, max(1, $limit));
    }

    /**
     * Whether an item's card has the same details as a new variant's (strict: a wrong "same" sends a store's variant to a person,
     * never links it): the same flavour words (both known), the same kind of product (form class; both known), the same strength
     * (both unknown counts as the same), a brand family word in common (brand or range), the same nicotine type, size and puffs
     * when both say one, the same pack size (unknown = 1), and the same range numbers and modifiers ("Legend 3500" is not
     * "Legend 8000", "Pro" is not plain).
     *
     * @param array<string, mixed> $c card()
     * @param array<string, mixed> $s an sku row (brand, strength_mg, nic_type, line, form, flavour, volume_ml, puffs, pack_units)
     */
    public static function sameDetails(array $c, array $s): bool
    {
        $line = (string) ($s['line'] ?? '');
        $sFamily = self::family((string) ($s['brand'] ?? ''), $line);
        if (array_intersect($c['family'], $sFamily) === []) {
            return false;
        }
        // A brand or range word read as flavour on one side ("Elux Legend Lemon & Lime" under the brand "Elux": "legend lemon lime")
        // is left out of both: the flavour is what is left, and it must be the same and not empty.
        $brandWords = array_merge($c['family'], $sFamily);
        $flavour = array_values(array_diff(self::flavourSet($c['flavour'] ?? null), $brandWords));
        if ($flavour === [] || $flavour !== array_values(array_diff(self::flavourSet($s['flavour'] ?? null), $brandWords))) {
            return false;
        }
        $cf = is_string($c['form'] ?? null) ? $c['form'] : null;
        $sf = is_string($s['form'] ?? null) ? $s['form'] : null;
        if ($cf === null || $sf === null || Form::classOf($cf) === null || Form::classOf($cf) !== Form::classOf($sf)) {
            return false;
        }
        $num = static fn (mixed $v): ?string => $v === null || $v === '' ? null : Text::num((string) $v);
        if ($num($c['strength_mg'] ?? null) !== $num($s['strength_mg'] ?? null)) {
            return false;
        }
        foreach (['volume_ml', 'puffs'] as $k) {
            $a = $num($c[$k] ?? null);
            $b = $num($s[$k] ?? null);
            if ($a !== null && $b !== null && $a !== $b) {
                return false;
            }
        }
        $ct = is_string($c['nic_type'] ?? null) ? $c['nic_type'] : null;
        $st = is_string($s['nic_type'] ?? null) ? $s['nic_type'] : null;
        if ($ct !== null && $st !== null && $ct !== $st) {
            return false;
        }
        if ((int) ($c['pack_units'] ?? 1) !== (int) ($s['pack_units'] ?? 1)) {
            return false;
        }
        return $c['line_numbers'] === self::lineNumbers($line) && $c['modifiers'] === self::modifiers($line);
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /**
     * Items a barcode of the variant is on (one scan = one unit, usable), or on a store variant linked to one one for one: root id => the
     * barcode, in the order found. A pack's barcode is never suggested as the item itself.
     *
     * @param list<string> $barcodes
     * @return array<int, string>
     */
    private function byBarcode(array $barcodes): array
    {
        $forms = array_slice(Gtin::forms($barcodes), 0, 400);
        if ($forms === []) {
            return [];
        }
        $out = [];
        $in = implode(',', array_fill(0, count($forms), '?'));
        // one scan = one unit only: an outer case's barcode (units_per_scan > 1) or a barcode found on two items (is_usable 0) is never a
        // one-for-one suggestion (Existing item links one for one)
        foreach ($this->db->all("SELECT b.barcode, b.sku_id FROM sku_barcode b WHERE b.barcode IN ({$in}) AND b.units_per_scan = 1 AND b.is_usable = 1 "
            . 'ORDER BY b.sku_id LIMIT 20', $forms) as $r) {
            $out[$this->rootOf((int) $r['sku_id'])] ??= (string) $r['barcode'];
        }
        // a barcode the warehouse holds as an outer case or as found on two items is not taken from a store variant either (Codex re-check)
        $bad = array_map('strval', $this->db->column("SELECT DISTINCT b.barcode FROM sku_barcode b WHERE b.barcode IN ({$in}) AND (b.units_per_scan <> 1 OR b.is_usable = 0)", $forms));
        $forms = array_values(array_diff($forms, $bad));
        if ($forms === []) {
            return $out;
        }
        foreach ($this->db->all("SELECT cl.sku_id, CAST(lp.barcodes AS CHAR) AS barcodes FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id "
            . "WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND cl.sku_id IS NOT NULL AND cl.status IN ('mapped', 'quarantined') AND cl.units_per_item = 1 "
            . 'ORDER BY cl.sku_id LIMIT 20',
            [json_encode($forms, JSON_THROW_ON_ERROR)]) as $r) {
            $have = json_decode((string) $r['barcodes'], true);
            $shared = is_array($have) ? array_values(array_intersect(array_map('strval', $have), $forms)) : [];
            $out[$this->rootOf((int) $r['sku_id'])] ??= (string) ($shared[0] ?? '');
        }
        return $out;
    }

    /**
     * Items whose names hold most of the variant's name words, best first: the database counts the words found in every item's
     * name (one pass, the NAME_WORDS most telling words), the best NAME_POOL are scored here (Dice of the words, plus a little when
     * the strength, the size and the kind agree), those at MIN_SIMILAR or above are kept.
     *
     * @param array<string, mixed> $card card()
     * @return list<int>
     */
    private function similarNames(array $card, int $limit): array
    {
        $words = $card['words'];
        if ($words === []) {
            return [];
        }
        // the most telling words: letters before numbers, longer before shorter
        $pick = $words;
        usort($pick, static fn (string $a, string $b): int => [ctype_digit($a[0]) ? 1 : 0, -strlen($a), $a] <=> [ctype_digit($b[0]) ? 1 : 0, -strlen($b), $b]);
        $pick = array_slice($pick, 0, self::NAME_WORDS);
        $hit = implode(' + ', array_fill(0, count($pick), '(name LIKE ?)'));
        $need = min(2, count($pick));
        $rows = $this->db->all("SELECT id, name, strength_mg, volume_ml, form, ({$hit}) AS hits FROM sku WHERE merged_into_sku_id IS NULL "
            . "HAVING hits >= {$need} ORDER BY hits DESC, id DESC LIMIT " . self::NAME_POOL, array_map(static fn (string $w): string => '%' . self::like($w) . '%', $pick));
        $num = static fn (mixed $v): ?string => $v === null || $v === '' ? null : Text::num((string) $v);
        $scored = [];
        foreach ($rows as $r) {
            $score = self::dice($words, self::words((string) $r['name']));
            foreach (['strength_mg', 'volume_ml'] as $k) {
                if ($num($card[$k] ?? null) !== null && $num($card[$k] ?? null) === $num($r[$k])) {
                    $score += 0.05;
                }
            }
            $class = Form::classOf(is_string($card['form'] ?? null) ? $card['form'] : null);
            if ($class !== null && $class === Form::classOf(is_string($r['form'] ?? null) ? $r['form'] : null)) {
                $score += 0.05;
            }
            if ($score >= self::MIN_SIMILAR) {
                $scored[(int) $r['id']] = $score;
            }
        }
        uksort($scored, static fn (int $a, int $b): int => [$scored[$b], $a] <=> [$scored[$a], $b]);
        return array_slice(array_keys($scored), 0, $limit);
    }

    /**
     * Code, name and free to sell for the store (on hand + on its way - allocated - held, over the store's sellable warehouses;
     * 0 without a balance), keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, array{code: string, name: string, free: int}>
     */
    private function items(array $ids, int $channelId): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $free = [];
        foreach ($this->db->all("SELECT b.sku_id, SUM(b.on_hand + b.incoming - b.allocated - b.held) AS free FROM channel_warehouse cw "
            . "JOIN stock_balance b ON b.warehouse_id = cw.warehouse_id WHERE cw.channel_id = ? AND cw.is_sellable = 1 AND b.sku_id IN ({$in}) GROUP BY b.sku_id",
            [$channelId, ...$ids]) as $r) {
            $free[(int) $r['sku_id']] = (int) $r['free'];
        }
        $out = [];
        foreach ($this->db->all("SELECT id, code, name FROM sku WHERE id IN ({$in}) AND code IS NOT NULL", $ids) as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'free' => $free[(int) $r['id']] ?? 0];
        }
        return $out;
    }

    /** The item an item is now: itself, or the one it was joined into (as DecisionService::rootOf reads the chain). */
    private function rootOf(int $skuId): int
    {
        $root = $this->db->value(
            'WITH RECURSIVE up (id, nxt, depth) AS (SELECT id, merged_into_sku_id, 0 FROM sku WHERE id = ? '
            . 'UNION ALL SELECT s.id, s.merged_into_sku_id, up.depth + 1 FROM sku s JOIN up ON s.id = up.nxt WHERE up.depth < 100) '
            . 'SELECT id FROM up WHERE nxt IS NULL LIMIT 1',
            [$skuId],
        );
        return $root === null ? $skuId : (int) $root;
    }

    /** Flavour words as a sorted set, plurals folded ("Lemon & Lime" and "lime lemon" are one). @return list<string> */
    private static function flavourSet(mixed $flavour): array
    {
        if (!is_string($flavour) || trim($flavour) === '') {
            return [];
        }
        $set = [];
        foreach (Text::tokens($flavour) as $t) {
            if (!in_array($t, Normalizer::FLAVOUR_NOISE, true)) {
                $set[Text::stem($t)] = true;
            }
        }
        $out = array_map('strval', array_keys($set));
        sort($out, SORT_STRING);
        return $out;
    }

    /** The brand family words of a brand and the words of its range (no numbers, no modifiers, no weak words). @return list<string> */
    private static function family(string $brand, string $line): array
    {
        $out = [];
        foreach (Normalizer::familyTokens(Normalizer::brandTokens($brand)) as $t) {
            $out[$t] = true;
        }
        foreach (Text::tokens($line) as $t) {
            if (!ctype_digit($t[0]) && strlen($t) >= 2 && !in_array($t, Normalizer::BRAND_WEAK, true) && !in_array($t, Normalizer::LINE_MODIFIERS, true)
                && !in_array($t, Normalizer::BRAND_GENERIC, true)) {
                $out[$t] = true;
            }
        }
        $out = array_values(array_filter(array_map('strval', array_keys($out)), static fn (string $t): bool => !in_array($t, Normalizer::BRAND_WEAK, true)));
        sort($out, SORT_STRING);
        return $out;
    }

    /** The numbers of a range ("elux legend 3500" -> ["3500"]), sorted. @return list<string> */
    private static function lineNumbers(string $line): array
    {
        $out = array_values(array_unique(array_filter(Text::tokens($line), static fn (string $t): bool => ctype_digit($t))));
        sort($out, SORT_STRING);
        return $out;
    }

    /** The modifiers of a range ("pro", "max", "plus", ...), sorted. @return list<string> */
    private static function modifiers(string $line): array
    {
        $out = array_values(array_unique(array_filter(Text::tokens($line), static fn (string $t): bool => in_array($t, Normalizer::LINE_MODIFIERS, true))));
        sort($out, SORT_STRING);
        return $out;
    }

    /** A name's telling words: lower case, no stop words, no single letters ("Nic Salt - Lemon & Lime - 10ml - 5mg" -> lemon, lime, 10ml, 5mg). @return list<string> */
    private static function words(string $name): array
    {
        $out = [];
        foreach (Text::tokens($name) as $t) {
            if (strlen($t) >= 2 && !in_array($t, Normalizer::STOPWORDS, true) && !in_array($t, self::NAME_NOISE, true)) {
                $out[$t] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** Dice of two word lists (2 x shared / (a + b)), 0..1. @param list<string> $a @param list<string> $b */
    private static function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }

    /** A word for LIKE: its own % and _ (and the escape) taken literally. */
    private static function like(string $w): string
    {
        return addcslashes($w, '\\%_');
    }
}
