<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * One /ui route. $access: `public` (no sign-in), `any` (every signed-in person, whatever their roles) or a
 * permission of Auth\Permissions::MAP (I11: routes are guarded by permission, not only hidden from the menu).
 * $passive (the live drafts plan, step 3; docs/decisions.md LV4): a request the page sends by itself (the poll of who is here) does not
 * move the session's last activity unless it says the person did something (`active=1`; Ui\Kernel), so a page left open is still
 * signed out after the idle limit. Everything else about the route (sign-in, access, same origin, CSRF) is as for any other.
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
        public readonly bool $passive = false,
    ) {
    }
}
