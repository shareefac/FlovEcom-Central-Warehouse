<?php

declare(strict_types=1);

namespace CW\Ui;

/** One /ui route. $access: public | any (every signed-in role) | decide (mapper, mapping_lead) | lead (mapping_lead). */
final class Route
{
    public const PUBLIC = 'public';
    public const ANY = 'any';
    public const DECIDE = 'decide';
    public const LEAD = 'lead';

    /** @param \Closure(Context): HtmlResponse $handler */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $regex,
        public readonly \Closure $handler,
        public readonly string $access,
    ) {
    }
}
