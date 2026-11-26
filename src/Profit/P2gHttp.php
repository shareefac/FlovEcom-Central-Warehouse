<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * One HTTPS request to Parcel2Go (docs/decisions.md PGL6). The real one is CurlP2gHttp; the tests serve Parcel2Go's answers themselves (never
 * a call to Parcel2Go from a test). A request that gets no HTTP answer (no connection, a timeout) throws P2gHttpFailed in CW's own words.
 */
interface P2gHttp
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>} headers: lower-case name => value (Retry-After only is read)
     */
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, int $timeoutSec, int $maxBytes): array;
}
