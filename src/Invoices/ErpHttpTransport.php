<?php

declare(strict_types=1);

namespace CW\Invoices;

/**
 * The real ErpTransport (CW-917; docs/decisions.md EC1): one HTTPS GET with curl, no redirect followed, connect and total timeouts; it
 * never sends anything but a GET and never writes a header or a body to a log. Errors name the HTTP status or curl's error number only.
 */
final class ErpHttpTransport implements ErpTransport
{
    /** The most bytes one answer may have (a purchase invoice with its rows is a few hundred kB at most). */
    public const MAX_BYTES = 16_777_216;

    public function get(string $url, array $headers, int $timeoutSec): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('ERPNext cannot be read: this server has no curl');
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new \RuntimeException('ERPNext is read over https only');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('ERPNext\'s address could not be opened');
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0; // stops the transfer: an answer this big is not an invoice
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSec, 10),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($ok === false && $status === 0) {
            throw new \RuntimeException("ERPNext could not be reached (curl error {$err})");
        }
        return ['status' => $status, 'body' => $body];
    }
}
