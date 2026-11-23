<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * HttpGet through curl (docs/decisions.md DL49): a GET and nothing else, https only, no redirect followed, a time limit. A network error or a
 * timeout is status 0 (the client tries again); the answer's Retry-After (seconds) is handed back for a 429.
 */
final class CurlHttpGet implements HttpGet
{
    public function get(string $url, array $headers, int $timeoutSec): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new CwException('bad_url', 'CW reads Click & Drop over https only', 500);
        }
        $retry = null;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'retry_after' => null];
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => max(1, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeoutSec)),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$retry): int {
                if (preg_match('/^Retry-After:\s*(\d{1,5})\s*$/i', $line, $m) === 1) {
                    $retry = (int) $m[1];
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            return ['status' => 0, 'body' => '', 'retry_after' => null];
        }
        return ['status' => $status, 'body' => $body, 'retry_after' => $retry];
    }
}
