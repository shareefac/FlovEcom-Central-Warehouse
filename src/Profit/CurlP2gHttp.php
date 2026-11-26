<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * P2gHttp over curl (docs/decisions.md PGL6): HTTPS only, the certificate checked, no redirect followed, a connect timeout of 15 s, the whole
 * request within $timeoutSec and its answer within $maxBytes (a larger one is stopped, never read whole). Nothing is logged; a failure is
 * said by curl's error number, never the request.
 */
final class CurlP2gHttp implements P2gHttp
{
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, int $timeoutSec, int $maxBytes): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \LogicException('Parcel2Go is called over HTTPS only');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new P2gHttpFailed('curl could not start');
        }
        $out = '';
        $tooLarge = false;
        $got = [];
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeoutSec, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$out, &$tooLarge, $maxBytes): int {
                if (strlen($out) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0; // curl stops the transfer
                }
                $out .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$got): int {
                if (preg_match('/^(retry-after):\s*([0-9]{1,6})\s*$/i', $line, $m) === 1) {
                    $got[strtolower($m[1])] = $m[2];
                }
                return strlen($line);
            }];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLarge) {
            throw new P2gHttpFailed('an answer larger than ' . $maxBytes . ' bytes (stopped)');
        }
        if ($ok === false || $errno !== 0) {
            throw new P2gHttpFailed('no answer (curl error ' . $errno . ')');
        }
        return ['status' => $status, 'body' => $out, 'headers' => $got];
    }
}
