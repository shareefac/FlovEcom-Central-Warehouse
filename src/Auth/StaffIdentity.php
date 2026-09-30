<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Mapping\DecisionService;

/** The signed-in person of a /ui request (one live staff_session row + its staff_user). */
final class StaffIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly bool $mustChangePassword,
        /** staff_session.id (the sha256 of the cookie token): the CSRF token is bound to it. */
        public readonly string $sessionId,
    ) {
    }

    /** May make mapping decisions (link, new item, ignore, reject, unlink): DecisionService::DECIDERS. */
    public function canDecide(): bool
    {
        return in_array($this->role, DecisionService::DECIDERS, true);
    }

    /** May give the second approval of a pending decision. */
    public function isLead(): bool
    {
        return $this->role === DecisionService::LEAD;
    }
}
