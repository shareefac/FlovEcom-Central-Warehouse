<?php

declare(strict_types=1);

namespace CW\Api;

use CW\CwException;

/**
 * Shape checks for request fields before they reach the core (whose signatures are typed).
 * Everything the core validates itself (ids, lines, times, ...) is passed through untouched.
 */
final class Input
{
    /** An order ref from JSON: a string, or an integer (a site's numeric ord_id). */
    public static function ref(mixed $v, string $field = 'order_ref'): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (!is_string($v)) {
            throw new CwException('bad_order_ref', "{$field} must be a string or an integer", 400, ['field' => $field]);
        }
        return $v;
    }

    /** @return array<mixed> */
    public static function list(mixed $v, string $field, string $code = 'bad_request'): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new CwException($code, "{$field} must be a non-empty list", 400, ['field' => $field]);
        }
        return $v;
    }

    public static function bool(mixed $v, string $field): bool
    {
        if (!is_bool($v)) {
            throw new CwException('bad_request', "{$field} must be true or false", 400, ['field' => $field]);
        }
        return $v;
    }

    public static function optInt(mixed $v, string $field, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        if ($v === null) {
            return null;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            $range = $max === PHP_INT_MAX ? ">= {$min}" : "between {$min} and {$max}";
            throw new CwException('bad_request', "{$field} must be an integer {$range}", 400, ['field' => $field]);
        }
        return $v;
    }

    public static function optString(mixed $v, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new CwException('bad_request', "{$field} must be a string", 400, ['field' => $field]);
        }
        return $v;
    }

    /** A non-negative integer query parameter (digits only). */
    public static function queryInt(array $query, string $name, int $default, int $min, int $max): int
    {
        $v = $query[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 19) {
            throw new CwException('bad_query', "{$name} must be an integer between {$min} and {$max}", 400, ['field' => $name]);
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            throw new CwException('bad_query', "{$name} must be an integer between {$min} and {$max}", 400, ['field' => $name]);
        }
        return $n;
    }
}
