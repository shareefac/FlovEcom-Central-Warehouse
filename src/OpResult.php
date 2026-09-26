<?php

declare(strict_types=1);

namespace CW;

/**
 * The outcome of a stock operation: an HTTP-style status and a JSON-able body.
 * Domain outcomes (201 held, 409 short, 422 lines_changed, ...) are OpResults and are stored
 * for the idempotency key; `replayed` is true when the result came from the idempotency table.
 */
final class OpResult
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly bool $replayed = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @param array<string, mixed> $body */
    public static function of(int $status, array $body): self
    {
        return new self($status, $body);
    }

    public function asReplay(): self
    {
        return new self($this->status, $this->body, true);
    }
}
