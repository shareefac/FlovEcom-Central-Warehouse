<?php

declare(strict_types=1);

namespace CW\Api;

/** The authenticated site behind a request. */
final class ApiChannel
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $mode,
    ) {
    }
}
