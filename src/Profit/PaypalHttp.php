<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * One HTTPS request to PayPal (docs/decisions.md PPF10). The real one is CurlPaypalHttp; the tests serve PayPal's pages from a file.
 * A request that gets no HTTP answer (no connection, a timeout) throws PaypalHttpFailed with CW's own words (never the request).
 */
interface PaypalHttp
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, int $timeoutSec): array;
}
