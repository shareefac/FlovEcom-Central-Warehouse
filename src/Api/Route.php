<?php

declare(strict_types=1);

namespace CW\Api;

/** One API route. `stockWrite` routes change CW's books and are refused while the channel is `off`. */
final class Route
{
    /** @param \Closure(Context): Response $handler */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $regex,
        public readonly \Closure $handler,
        public readonly bool $stockWrite,
    ) {
    }
}
