<?php

declare(strict_types=1);

namespace CW\Invoices;

/**
 * The real ErpTransport (CW-917; docs/decisions.md EC1): one HTTPS GET with curl, no redirect followed, connect and total timeouts; it
 * never sends anything but a GET and never writes a header or a body to a log. Errors name the HTTP status or curl's error number only.
 *
 * Public only (docs/decisions.md AK5), for an address a person typed on Settings › APIs: the name is looked up once per transport (the
 * system's resolver, IPv4) and every address it gives must be a public internet address (not private, local, link-local, shared, reserved
 * or multicast), else ErpAddressRefused and nothing is sent; the connection then goes to that looked-up address only (curl's RESOLVE, so a
 * name that changes its answer between the look-up and the call cannot lead elsewhere; no proxy), on port 443, with the certificate still
 * checked for the name. The address of app.env (the integrator's) is called as before.
 */
final class ErpHttpTransport implements ErpTransport
{
    /** The most bytes one answer may have (a purchase invoice with its rows is a few hundred kB at most). */
    public const MAX_BYTES = 16_777_216;

    /** @var array<string, string> name => the address it was pinned to (public only: looked up once per transport) */
    private array $pinned = [];

    /**
     * @param (\Closure(string): (list<string>|false))|null $lookup the name look-up of the public-only mode (default gethostbynamel; tests
     *        give their own: no network in a test)
     */
    public function __construct(private readonly bool $publicOnly = false, private readonly ?\Closure $lookup = null)
    {
    }

    public function get(string $url, array $headers, int $timeoutSec): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('ERPNext cannot be read: this server has no curl');
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new \RuntimeException('ERPNext is read over https only');
        }
        // Public only: refused here, before anything is opened or sent.
        $pinned = $this->publicOnly ? [CURLOPT_RESOLVE => [$this->pin($url)], CURLOPT_NOPROXY => '*'] : [];
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
        ] + $pinned);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($ok === false && $status === 0) {
            throw new \RuntimeException("ERPNext could not be reached (curl error {$err})");
        }
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Whether an IPv4 address is a public internet address: not private (10/8, 172.16/12, 192.168/16), local (127/8, 0/8), link-local
     * (169.254/16: the cloud's metadata), shared (100.64/10), documentation, reserved or broadcast (PHP's global range), nor multicast
     * (224/4, which PHP's global range lets through). An IPv6 address is never one here (the look-up is IPv4).
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        return (int) explode('.', $ip)[0] < 224;
    }

    /**
     * curl's RESOLVE line for $url's name ("name:443:address"), the name looked up once per transport. ErpAddressRefused (nothing sent) when
     * the address is not a name on port 443, or any address the name gives is not public; \RuntimeException when the name gives none.
     */
    private function pin(string $url): string
    {
        $p = parse_url($url);
        $host = is_array($p) ? strtolower((string) ($p['host'] ?? '')) : '';
        $port = is_array($p) ? (int) ($p['port'] ?? 443) : 0;
        if ($host === '' || $port !== 443 || isset($p['user']) || isset($p['pass']) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            throw new ErpAddressRefused('ERPNext\'s address must be a name on the usual https port: refused, nothing sent');
        }
        if (!isset($this->pinned[$host])) {
            $ips = $this->lookup !== null ? ($this->lookup)($host) : @gethostbynamel($host);
            if (!is_array($ips) || $ips === []) {
                throw new \RuntimeException('ERPNext could not be reached (its name was not found)');
            }
            foreach ($ips as $ip) {
                if (!is_string($ip) || !self::isPublic($ip)) {
                    throw new ErpAddressRefused('ERPNext\'s name leads to an address that is not on the internet: refused, nothing sent');
                }
            }
            $this->pinned[$host] = $ips[0];
        }
        return "{$host}:443:{$this->pinned[$host]}";
    }
}
