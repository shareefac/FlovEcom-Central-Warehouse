<?php

declare(strict_types=1);

namespace CW\Schema;

/**
 * Splits a migration file into single statements on ";" outside quotes and comments.
 * Supports '...' and "..." strings (backslash escapes and doubled quotes), `identifiers`,
 * "-- " / "#" line comments and C-style block comments (all comments are dropped).
 * No DELIMITER support: migrations must not contain procedures/triggers with inner ";".
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($c === "'" || $c === '"' || $c === '`') {
                $end = self::skipQuoted($sql, $i, $c);
                $buf .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if (($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new \InvalidArgumentException('unterminated block comment in SQL');
                }
                $buf .= ' ';
                $i = $close + 2;
                continue;
            }
            if ($c === ';') {
                self::push($out, $buf);
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        self::push($out, $buf);
        return $out;
    }

    /** Returns the offset just past the closing quote. */
    private static function skipQuoted(string $sql, int $start, string $q): int
    {
        $len = strlen($sql);
        $i = $start + 1;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '\\' && $q !== '`') {
                $i += 2;
                continue;
            }
            if ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {
                    $i += 2; // doubled quote
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        throw new \InvalidArgumentException('unterminated quoted string in SQL');
    }

    /** @param list<string> $out */
    private static function push(array &$out, string $buf): void
    {
        $stmt = trim($buf);
        if ($stmt !== '') {
            $out[] = $stmt;
        }
    }
}
