<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Idempotency;

/**
 * The real AlertTransport (docs/decisions.md PF10). The web address gets a JSON POST over https, and only to a PUBLIC server (review
 * finding 7 of 14 Oct 2026: a settings manager could point it at a service inside the network): destination() resolves the name, refuses
 * it when any address it resolves to is private, loopback, link-local or otherwise reserved (or when the address is an IP literal), and
 * the request is pinned to the address checked (curl's RESOLVE; the stream fallback connects to that address with the name for TLS),
 * so a second lookup cannot lead elsewhere. Redirects are not followed. E-mail goes through PHP's mail() (the server's mail transfer
 * agent: on a server without one, mail() refuses and the run says so). Errors name what failed, never the address.
 */
final class AlertSender implements AlertTransport
{
    /** Ranges refused besides PHP's private and reserved ones (shared address space, IETF protocol assignments, benchmarking). */
    private const EXTRA_BLOCKED = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'];

    /** @var \Closure(string): list<string> the addresses a host name resolves to */
    private readonly \Closure $resolve;

    /** @param (\Closure(string): list<string>)|null $resolve (tests inject it; the default asks DNS for A and AAAA records) */
    public function __construct(?\Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static function (string $host): array {
            $out = [];
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
                $ip = $r['ip'] ?? $r['ipv6'] ?? null;
                if (is_string($ip)) {
                    $out[] = $ip;
                }
            }
            return $out;
        };
    }

    /**
     * Where a web address may be sent: its host, port and the public address checked. RuntimeException when it is not https, names
     * an IP address, does not resolve, or resolves to any address that is not public.
     *
     * @param \Closure(string): list<string> $resolve
     * @return array{host: string, port: int, ip: string}
     */
    public static function destination(string $url, \Closure $resolve): array
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || !isset($p['host']) || $p['host'] === '') {
            throw new \RuntimeException('the web address must be an https address with a server name');
        }
        $host = strtolower(trim((string) $p['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new \RuntimeException('the web address must name a server, not an IP address');
        }
        $ips = $resolve($host);
        if ($ips === []) {
            throw new \RuntimeException('the web address\'s server name does not resolve');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic((string) $ip)) {
                throw new \RuntimeException('the web address\'s server is not a public server (it resolves to a private, local or reserved address)');
            }
        }
        return ['host' => $host, 'port' => (int) ($p['port'] ?? 443), 'ip' => (string) $ips[0]];
    }

    /** Whether an address is a public one (not private, loopback, link-local, reserved or one of EXTRA_BLOCKED). */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // fc00::/7 (unique local) and fe80::/10 (link-local) are refused by the flags above on PHP 8.3; ::ffff:a.b.c.d maps IPv4.
            $low = strtolower($ip);
            return !str_starts_with($low, 'fc') && !str_starts_with($low, 'fd') && !preg_match('/^fe[89ab]/', $low) && !str_starts_with($low, '::ffff:');
        }
        $n = ip2long($ip);
        foreach (self::EXTRA_BLOCKED as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return true;
    }

    public function webhook(string $url, array $payload, int $timeoutSec): void
    {
        $to = self::destination($url, $this->resolve);
        $body = Idempotency::json($payload);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('the web address could not be opened');
            }
            $pin = str_contains($to['ip'], ':') ? '[' . $to['ip'] . ']' : $to['ip'];
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeoutSec, CURLOPT_CONNECTTIMEOUT => $timeoutSec, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => ["{$to['host']}:{$to['port']}:{$pin}"]]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($out === false || $code < 200 || $code >= 300) {
                throw new \RuntimeException($code > 0 ? "the web address answered HTTP {$code}" : "the web address could not be reached (curl error {$err})");
            }
            return;
        }
        // No curl: connect to the checked address itself, with the server's name for the certificate and the Host header.
        $p = parse_url($url);
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $ipHost = str_contains($to['ip'], ':') ? '[' . $to['ip'] . ']' : $to['ip'];
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nHost: {$to['host']}\r\n", 'content' => $body,
            'timeout' => $timeoutSec, 'follow_location' => 0, 'ignore_errors' => true],
            'ssl' => ['peer_name' => $to['host'], 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $out = @file_get_contents("https://{$ipHost}:{$to['port']}{$path}", false, $ctx);
        $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m) === 1 ? (int) $m[1] : 0;
        if ($out === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($status > 0 ? "the web address answered HTTP {$status}" : 'the web address could not be reached');
        }
    }

    public function email(string $to, string $subject, string $body): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $subject) === 1) {
            throw new \RuntimeException('the e-mail address or subject is not valid');
        }
        $ok = mail($to, $subject, $body, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-CW-Alert' => '1']);
        if (!$ok) {
            throw new \RuntimeException('the server did not take the e-mail (no mail transfer agent?)');
        }
    }
}
