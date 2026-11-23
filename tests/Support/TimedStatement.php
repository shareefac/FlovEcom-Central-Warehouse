<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * A PDO statement that notes when statements starting with given texts ran (the timing tests of the delivery bills: when a bill write took
 * its first order guard and when it let them go). Used on an extra connection only (TestDb::connect(), then
 * setAttribute(PDO::ATTR_STATEMENT_CLASS, [TimedStatement::class, []])).
 */
final class TimedStatement extends \PDOStatement
{
    /** @var list<string> the SQL prefixes noted */
    public static array $watch = [];
    /** @var list<array{0: string, 1: float}> prefix and when (microtime) it ran, in order */
    public static array $marks = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        foreach (self::$watch as $prefix) {
            if (str_starts_with(ltrim($this->queryString), $prefix)) {
                self::$marks[] = [$prefix, microtime(true)];
                return parent::execute($params);
            }
        }
        if (getenv('CW_TIMING_DETAIL') === '1') {
            self::$marks[] = [substr((string) preg_replace('/\s+/', ' ', ltrim($this->queryString)), 0, 48), microtime(true)];
        }
        return parent::execute($params);
    }

    /** The longest stretch from a $from statement to the next $to one, in seconds (0: none). */
    public static function longest(string $from, string $to): float
    {
        $best = 0.0;
        $start = null;
        foreach (self::$marks as [$p, $t]) {
            if ($p === $from && $start === null) {
                $start = $t;
            } elseif ($p === $to && $start !== null) {
                $best = max($best, $t - $start);
                $start = null;
            }
        }
        return $best;
    }
}
