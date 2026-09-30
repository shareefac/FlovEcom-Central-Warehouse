<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * One /ui request: method, path, query, form fields, cookies and the few headers the UI reads.
 * REMOTE_ADDR is the ONLY client address (mod_remoteip stays disabled, as for the API), so a
 * forged X-Forwarded-For can neither dodge the login limiter nor plant an address in audit_log.
 */
final class UiRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, string> $headers lower-case name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $headers,
        public readonly string $ip,
        public readonly bool $secure,
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $https = $server['HTTPS'] ?? '';
        return new self(
            strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $headers,
            filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0',
            is_string($https) && $https !== '' && strtolower($https) !== 'off',
        );
    }

    /** A query-string value that is a plain string (an array value `a[]=` counts as absent). */
    public function param(string $name): ?string
    {
        $v = $this->query[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    /** A form field that is a plain string. */
    public function field(string $name): ?string
    {
        $v = $this->post[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The positive integer in a query/form value, else null. */
    public static function id(?string $v): ?int
    {
        return $v !== null && preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1 ? (int) $v : null;
    }
}
