<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * One /ui route. $access: `public` (no sign-in), `any` (every signed-in person, whatever their roles) or a
 * permission of Auth\Permissions::MAP (I11: routes are guarded by permission, not only hidden from the menu).
 */
final class Route
{
    public const PUBLIC = 'public';
    public const ANY = 'any';
    /** Link, new item, ignore, reject, withdraw: mapper, mapping_lead. */
    public const DECIDE = 'mapping.decide';
    /** The second approval: mapping_lead. */
    public const LEAD = 'mapping.approve';

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
