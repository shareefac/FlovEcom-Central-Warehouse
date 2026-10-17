<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/** Interleave a second connection immediately after a chosen statement executes, before its caller continues. */
final class StatementHook extends \PDOStatement
{
    protected function __construct(private readonly \Closure $after)
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        ($this->after)($this->queryString);
        return $ok;
    }
}
