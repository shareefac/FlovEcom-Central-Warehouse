<?php

declare(strict_types=1);

namespace CW\Api\Controller;

use CW\Api\Context;
use CW\Api\Response;
use CW\CwException;
use CW\Mapping\ItemSuggestions;

/**
 * GET /v1/item-suggestions (docs/decisions.md SX2, SX3; tracker CW-1138) — which items a store's new variant may be, for the store's
 * Existing item box, and (with `code`) what a typed CW number is. Read only, any channel mode (it moves nothing).
 *
 * Query (every field checked; an unknown field is ignored):
 *   title     the product's name (at most 512)          option   the variant's own option text, "5mg" (at most 512)
 *   brand     the brand's name (at most 128)            attrs    at most 20 of attrs[i][name] (128) and attrs[i][value] (255)
 *   barcodes  a comma list (or barcodes[]=), at most 20 printable codes of at most 64
 *   code      a CW number: CW-011468, cw-11468, 11468  limit    1-5 (5)
 * At least one of title, option, barcodes and code. Answer: {suggestions: [{code, name, free, why, barcode?}], strong, item?}:
 * why = barcode | same_details | similar_name; free = free to sell for this store; item (only with code) = {code, name, free,
 * why: code, named?} or null (no such item; `named` = the number asked, when it was joined into the item shown).
 */
final class ItemSuggestionsController
{
    public const MAX_TEXT = 512;
    public const MAX_BRAND = 128;
    public const MAX_ATTRS = 20;
    public const MAX_BARCODES = 20;

    public function index(Context $c): Response
    {
        $q = $c->query();
        $in = [
            'title' => self::text($q, 'title', self::MAX_TEXT),
            'option' => self::text($q, 'option', self::MAX_TEXT),
            'brand' => self::text($q, 'brand', self::MAX_BRAND),
            'attrs' => self::attrs($q['attrs'] ?? null),
            'barcodes' => self::barcodes($q['barcodes'] ?? null),
        ];
        $code = null;
        if (isset($q['code']) && $q['code'] !== '') {
            $code = is_string($q['code']) ? ItemSuggestions::code($q['code']) : null;
            if ($code === null) {
                throw new CwException('bad_query', 'code must be a CW number (CW-000123)', 400, ['field' => 'code']);
            }
        }
        $limit = self::limit($q['limit'] ?? null);
        if ($in['title'] === '' && $in['option'] === '' && $in['barcodes'] === [] && $code === null) {
            throw new CwException('bad_query', 'say what to look for: title, option, barcodes or code', 400, ['field' => 'title']);
        }
        // Plain reads: no lock, nothing written.
        return Response::ok((new ItemSuggestions($c->db))->suggest($c->channel->id, $in, $code, $limit));
    }

    /** A printable text field of at most $max characters ('' when absent). @param array<string, mixed> $q */
    private static function text(array $q, string $field, int $max, ?string $label = null): string
    {
        $v = $q[$field] ?? null;
        if ($v === null) {
            return '';
        }
        $label ??= $field;
        if (!is_string($v) || mb_check_encoding($v, 'UTF-8') === false || mb_strlen($v) > $max || preg_match('/\p{Cc}/u', $v) === 1) {
            throw new CwException('bad_query', "{$label} must be printable text of at most {$max} characters", 400, ['field' => $label]);
        }
        return trim($v);
    }

    /** @return list<array{name: string, value: string}> */
    private static function attrs(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        if (!is_array($v) || count($v) > self::MAX_ATTRS) {
            throw new CwException('bad_query', 'attrs must be at most ' . self::MAX_ATTRS . ' of attrs[i][name] and attrs[i][value]', 400, ['field' => 'attrs']);
        }
        $out = [];
        foreach ($v as $i => $a) {
            if (!is_array($a) || array_diff(array_keys($a), ['name', 'value']) !== []) {
                throw new CwException('bad_query', "attrs[{$i}] must hold name and value only", 400, ['field' => 'attrs']);
            }
            $name = self::text($a, 'name', 128, "attrs[{$i}].name");
            $value = self::text($a, 'value', 255, "attrs[{$i}].value");
            if ($value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function barcodes(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        $list = is_string($v) ? explode(',', $v) : (is_array($v) && array_is_list($v) ? $v : null);
        if ($list === null || count($list) > self::MAX_BARCODES) {
            throw new CwException('bad_query', 'barcodes must be at most ' . self::MAX_BARCODES . ', comma separated', 400, ['field' => 'barcodes']);
        }
        $out = [];
        foreach ($list as $b) {
            if (!is_string($b)) {
                throw new CwException('bad_query', 'each barcode must be text', 400, ['field' => 'barcodes']);
            }
            $b = trim($b);
            if ($b === '') {
                continue;
            }
            if (strlen($b) > 64 || preg_match('/^[\x21-\x7e]+$/D', $b) !== 1) {
                throw new CwException('bad_query', 'each barcode must be 1-64 printable characters', 400, ['field' => 'barcodes']);
            }
            $out[$b] = true;
        }
        return array_map('strval', array_keys($out));
    }

    private static function limit(mixed $v): int
    {
        if ($v === null || $v === '') {
            return ItemSuggestions::MAX;
        }
        if (!is_string($v) || preg_match('/^[1-9]$/D', $v) !== 1 || (int) $v > ItemSuggestions::MAX) {
            throw new CwException('bad_query', 'limit must be 1 to ' . ItemSuggestions::MAX, 400, ['field' => 'limit']);
        }
        return (int) $v;
    }
}
