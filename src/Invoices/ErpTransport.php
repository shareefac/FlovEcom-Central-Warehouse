<?php

declare(strict_types=1);

namespace CW\Invoices;

/**
 * How the ERPNext reader reaches ERPNext (CW-917; docs/decisions.md EC1): GET only. The real one is ErpHttpTransport; the tests give a
 * fixture transport (no network in a test).
 */
interface ErpTransport
{
    /**
     * One GET of $url with these headers, within $timeoutSec. Throws \RuntimeException when ERPNext cannot be reached (never naming a
     * header: the API key and secret travel in one).
     *
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers, int $timeoutSec): array;
}
