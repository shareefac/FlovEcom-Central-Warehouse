<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * A PDO statement for one test at a time (Confirm all's session settings, docs/decisions.md CA24): it records every statement executed,
 * answers fetchColumn() for a statement that starts with a scripted text (a value the server cannot hold, like a lock wait of 0), and
 * skips the statements that start with a text given in $skip (so such a value is never sent to the server). Install on a connection with
 * setAttribute(PDO::ATTR_STATEMENT_CLASS, [ScriptedStatement::class, []]) and put PDOStatement back afterwards.
 */
final class ScriptedStatement extends \PDOStatement
{
    /** @var array<string, string> SQL prefix => what fetchColumn() answers */
    public static array $answers = [];
    /** @var list<string> SQL prefixes that are recorded but not executed */
    public static array $skip = [];
    /** @var list<string> every statement executed (or skipped), in order */
    public static array $seen = [];

    protected function __construct()
    {
    }

    public static function reset(): void
    {
        self::$answers = [];
        self::$skip = [];
        self::$seen = [];
    }

    public function execute(?array $params = null): bool
    {
        self::$seen[] = $this->queryString;
        foreach (self::$skip as $prefix) {
            if (str_starts_with(ltrim($this->queryString), $prefix)) {
                return true;
            }
        }
        return parent::execute($params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        foreach (self::$answers as $prefix => $value) {
            if (str_starts_with(ltrim($this->queryString), $prefix)) {
                return $value;
            }
        }
        return parent::fetchColumn($column);
    }
}
