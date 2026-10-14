<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * A PDO statement that runs a test's closure once, just before a statement whose SQL starts with a given text is executed (pack H's
 * review tests): it puts another session's write, or a deadlock, exactly between a service's read and its write. Used on an extra
 * connection only (TestDb::connect(), then setAttribute(PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []])); never on the
 * test's own connection.
 */
final class HookedStatement extends \PDOStatement
{
    /** @var array<string, \Closure(): void> SQL prefix => what to run once before it */
    public static array $before = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        foreach (self::$before as $prefix => $fn) {
            if (str_starts_with(ltrim($this->queryString), $prefix)) {
                unset(self::$before[$prefix]);
                $fn();
            }
        }
        return parent::execute($params);
    }
}
