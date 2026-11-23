<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * A plain HTTP GET (docs/decisions.md DL49): the only verb the Click & Drop client has in phase 1 of the delivery cost (the owner, 23 Nov
 * 2026, Q4: CW reads Click & Drop; the first write is the manifest button's own plan). The tests give a fake one.
 */
interface HttpGet
{
    /**
     * @param list<string> $headers "Name: value" lines
     * @return array{status: int, body: string, retry_after: ?int} status 0: no answer (a network error, a timeout)
     */
    public function get(string $url, array $headers, int $timeoutSec): array;
}
