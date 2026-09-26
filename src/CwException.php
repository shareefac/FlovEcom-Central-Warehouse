<?php

declare(strict_types=1);

namespace CW;

/**
 * A request CW cannot act on *yet* or at all: invalid input (400/422), unknown order (404),
 * a unit not yet committed (409), ... Unlike an OpResult it is NOT stored for the idempotency
 * key and the whole operation is rolled back, so the caller may retry the same key later.
 */
final class CwException extends \RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $detail = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'message' => $this->getMessage()] + ($this->detail === [] ? [] : ['detail' => $this->detail]);
    }
}
