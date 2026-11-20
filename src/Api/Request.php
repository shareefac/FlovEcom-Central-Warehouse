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
    /** @var \Closure(int): string|null reads at most the given number of bytes */
    private ?\Closure $bodyReader;
    /** The limit the body was read with (it holds at most this + 1 bytes); null: read whole (created with its body). */
    private ?int $readLimit = null;

    /**
     * @param array<string, string> $headers lower-case name => value
     * @param array<string, mixed> $query
     * @param (\Closure(int): string)|null $bodyReader reads at most the given number of bytes
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
            static function (int $max): string {
                $in = fopen('php://input', 'rb');
                if ($in === false) {
                    return '';
                }
                $data = stream_get_contents($in, $max);
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

    /**
     * For tests and tools: a request whose body is read by $reader, at most the bytes it is given (as fromGlobals reads php://input).
     *
     * @param array<string, string> $headers any case
     * @param \Closure(int): string $reader
     */
    public static function streamed(string $method, string $uri, array $headers, \Closure $reader, string $remoteAddr = '127.0.0.1'): self
    {
        $path = parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        return new self(strtoupper($method), is_string($path) && $path !== '' ? $path : '/', $query, $h, $remoteAddr, null, $reader);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The raw body; 413 when it is larger than $max (at most MAX_BODY_BYTES): a declared length over it is refused before anything is read,
     * and never more than $max + 1 bytes are read (a route's own cap, as the order snapshot's 256 KB, is kept before the body is buffered:
     * the final review of 6d59eda, b-LOW1).
     */
    public function body(int $max = self::MAX_BODY_BYTES): string
    {
        $max = min($max, self::MAX_BODY_BYTES);
        if ($this->body === null) {
            $declared = $this->header('content-length');
            if ($declared !== null && ctype_digit($declared) && (int) $declared > $max) {
                throw self::tooLarge($max);
            }
            $this->body = $this->bodyReader === null ? '' : ($this->bodyReader)($max + 1);
            $this->bodyReader = null;
            $this->readLimit = $max;
        }
        if (strlen($this->body) > $max || ($this->readLimit !== null && $max > $this->readLimit && strlen($this->body) > $this->readLimit)) {
            throw self::tooLarge($max);
        }
        return $this->body;
    }

    private static function tooLarge(int $max = self::MAX_BODY_BYTES): CwException
    {
        return new CwException('body_too_large', 'the request body is larger than ' . ($max >= 1024 * 1024 ? intdiv($max, 1024 * 1024) . ' MB' : intdiv($max, 1024) . ' KB'), 413);
    }
}
