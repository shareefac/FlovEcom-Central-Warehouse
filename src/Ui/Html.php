<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * Output helpers of the /ui templates. EVERYTHING that came from a database row, a request or a
 * site (product titles are hostile) goes through e() on its way into HTML: the templates never
 * echo a variable raw. The CSP (default-src 'self') is the second line, not the first.
 */
final class Html
{
    /** HTML-escapes any scalar (null -> ''); valid for text and quoted attribute values. */
    public static function e(mixed $v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if (is_bool($v)) {
            return '1';
        }
        if (!is_scalar($v)) {
            throw new \InvalidArgumentException('cannot print a ' . get_debug_type($v));
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** '1,234' or '-' for null. */
    public static function int(mixed $v): string
    {
        return $v === null || $v === '' ? '-' : number_format((int) $v);
    }

    /** A decimal column value without trailing zeros ('6.00' -> '6'), '' for null. */
    public static function dec(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = (string) $v;
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** 'Y-m-d H:i' (UTC) of a database DATETIME(6), '' for null. */
    public static function dt(?string $v): string
    {
        return $v === null || $v === '' ? '' : substr($v, 0, 16);
    }

    /** Percentage with one decimal, '-' when there is nothing to divide. */
    public static function pct(int|float $part, int|float $whole): string
    {
        return $whole <= 0 ? '-' : number_format(100 * $part / $whole, 1) . '%';
    }

    /**
     * A URL path with a query string (values url-encoded, null/'' values dropped).
     *
     * @param array<string, scalar|null> $query
     */
    public static function url(string $path, array $query = []): string
    {
        $q = [];
        foreach ($query as $k => $v) {
            if ($v !== null && $v !== '' && $v !== false) {
                $q[$k] = (string) $v;
            }
        }
        return $path . ($q === [] ? '' : '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
    }

    /** An http(s) URL safe to put in href (never javascript:, data:, ...), else null. */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || preg_match('#^https?://[^\s<>"\']+$#iD', $url) !== 1) {
            return null;
        }
        return $url;
    }

    /** @param mixed $v a decoded JSON value @return list<string> the scalar members of a JSON list */
    public static function strings(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            if (is_string($x) || is_int($x) || is_float($x)) {
                $out[] = (string) $x;
            }
        }
        return $out;
    }

    /** Decodes a JSON column into an array ([] when NULL or malformed). @return array<mixed> */
    public static function json(mixed $v): array
    {
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }
}
