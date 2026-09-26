<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/** One HTTP answer from the API under test. */
final class ApiResponse
{
    /** @param array<string, string> $headers lower-case name => value */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $raw,
        public readonly mixed $json,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return is_array($this->json) && ($this->json['ok'] ?? null) === true;
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        return is_array($this->json) ? ($this->json['data'] ?? null) : null;
    }

    public function code(): ?string
    {
        return is_array($this->json) ? ($this->json['error']['code'] ?? null) : null;
    }

    public function message(): ?string
    {
        return is_array($this->json) ? ($this->json['error']['message'] ?? null) : null;
    }

    public function replayed(): bool
    {
        return $this->header('idempotent-replayed') === 'true';
    }

    public function describe(): string
    {
        return $this->status . ' ' . substr($this->raw, 0, 600);
    }
}
