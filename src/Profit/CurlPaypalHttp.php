<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * PaypalHttp over curl (docs/decisions.md PPF10): HTTPS only, the certificate checked, no redirect followed, a connect timeout of 15 s and the
 * whole request within $timeoutSec. Nothing is logged; a failure is said by curl's error number, never the request.
 */
final class CurlPaypalHttp implements PaypalHttp
{
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body, int $timeoutSec): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \LogicException('PayPal is called over HTTPS only');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new PaypalHttpFailed('curl could not start');
        }
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($out) || $errno !== 0) {
            throw new PaypalHttpFailed('no answer (curl error ' . $errno . ')');
        }
        return ['status' => $status, 'body' => $out];
    }
}
