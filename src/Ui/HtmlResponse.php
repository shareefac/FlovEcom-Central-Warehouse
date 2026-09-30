<?php

declare(strict_types=1);

namespace CW\Ui;

/** One /ui answer: status, headers (Set-Cookie may repeat) and a body. */
final class HtmlResponse
{
    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];

    public function __construct(public readonly int $status, public readonly string $body, string $contentType = 'text/html; charset=utf-8')
    {
        $this->headers[] = ['Content-Type', $contentType];
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Only paths of this site: a Location built from request data can never leave it.
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || preg_match('/[\r\n\\\\]/', $location) === 1) {
            throw new \InvalidArgumentException('redirect target must be a local path');
        }
        return (new self($status, ''))->withHeader('Location', $location);
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('header injection');
        }
        $this->headers[] = [$name, $value];
        return $this;
    }

    /** Sets a cookie for the whole /ui area: HttpOnly, SameSite=Strict, Secure on HTTPS. */
    public function withCookie(string $name, string $value, bool $secure, ?int $maxAge = null): self
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1 || preg_match('/^[A-Za-z0-9_.-]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('bad cookie');
        }
        $c = $name . '=' . $value . '; Path=/ui; HttpOnly; SameSite=Strict';
        if ($maxAge !== null) {
            $c .= '; Max-Age=' . $maxAge;
        }
        if ($secure) {
            $c .= '; Secure';
        }
        return $this->withHeader('Set-Cookie', $c);
    }

    /** Expires a cookie set by withCookie(). */
    public function withoutCookie(string $name, bool $secure): self
    {
        return $this->withCookie($name, '', $secure, 0);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$k, $v]) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return list<string> every value of a header (Set-Cookie) */
    public function headerValues(string $name): array
    {
        $out = [];
        foreach ($this->headers as [$k, $v]) {
            if (strcasecmp($k, $name) === 0) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as [$k, $v]) {
            header($k . ': ' . $v, strcasecmp($k, 'Set-Cookie') !== 0);
        }
        echo $this->body;
    }
}
