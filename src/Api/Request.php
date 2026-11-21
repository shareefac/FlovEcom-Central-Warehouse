<?php

declare(strict_types=1);

namespace CW\Api;

use CW\CwException;

/**
 * One HTTP request as the API sees it. Built from the PHP globals by the front controller
 * (fromGlobals) or directly in tests (create). The body is read lazily, only after the caller
 * has authenticated, and never beyond MAX_BODY_BYTES.
 *
 * The client address is REMOTE_ADDR only: CF-Connecting-IP, X-Forwarded-For, X-Real-IP and
 * friends are ordinary headers here and are never trusted (plan §11).
 */
final class Request
{
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    private ?string $body;
    /** @var \Closure(): string|null */
    private ?\Closure $bodyReader;

    /**
     * @param array<string, string> $headers lower-case name => value
     * @param array<string, mixed> $query
     * @param (\Closure(): string)|null $bodyReader reads at most MAX_BODY_BYTES + 1 bytes
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly array $headers,
        public readonly string $remoteAddr,
        ?string $body,
        ?\Closure $bodyReader,
    ) {
        $this->body = $body;
        $this->bodyReader = $bodyReader;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (!is_string($v)) {
                continue;
            }
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            } elseif ($k === 'CONTENT_TYPE' || $k === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $k))] = $v;
            }
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $headers,
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            null,
            static function (): string {
                $in = fopen('php://input', 'rb');
                if ($in === false) {
                    return '';
                }
                $data = stream_get_contents($in, self::MAX_BODY_BYTES + 1);
                fclose($in);
                return $data === false ? '' : $data;
            },
        );
    }

    /**
     * For tests and tools.
     *
     * @param array<string, string> $headers any case
     */
    public static function create(string $method, string $uri, array $headers = [], string $body = '', string $remoteAddr = '127.0.0.1'): self
    {
        $path = parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        return new self(strtoupper($method), is_string($path) && $path !== '' ? $path : '/', $query, $h, $remoteAddr, $body, null);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The raw body; 413 when it is larger than MAX_BODY_BYTES. */
    public function body(): string
    {
        if ($this->body === null) {
            $declared = $this->header('content-length');
            if ($declared !== null && ctype_digit($declared) && (int) $declared > self::MAX_BODY_BYTES) {
                throw self::tooLarge();
            }
            $this->body = $this->bodyReader === null ? '' : ($this->bodyReader)();
            $this->bodyReader = null;
        }
        if (strlen($this->body) > self::MAX_BODY_BYTES) {
            throw self::tooLarge();
        }
        return $this->body;
    }

    private static function tooLarge(): CwException
    {
        return new CwException('body_too_large', 'the request body is larger than ' . intdiv(self::MAX_BODY_BYTES, 1024 * 1024) . ' MB', 413);
    }
}
