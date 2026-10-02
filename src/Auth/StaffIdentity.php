<?php

declare(strict_types=1);

namespace CW\Auth;

/**
 * The signed-in person of a /ui request (one live staff_session row + its staff_user and live staff_role
 * rows). The roles are read on every request (Sessions::resolve), so a role taken away stops working on
 * the person's next page (I11). What a role may do is Permissions' business, never a role-name check here.
 */
final class StaffIdentity
{
    /** @var list<string> live roles, sorted, no duplicates */
    public readonly array $roles;

    /** @param list<string> $roles */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        array $roles,
        public readonly bool $mustChangePassword,
        /** staff_session.id (the sha256 of the cookie token): the CSRF token is bound to it. */
        public readonly string $sessionId,
    ) {
        $roles = array_values(array_unique(array_filter($roles, static fn (mixed $r): bool => is_string($r) && $r !== '')));
        sort($roles);
        $this->roles = $roles;
    }

    public function has(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** Whether the person's roles hold $perm (Permissions::MAP; an unknown permission throws). */
    public function can(string $perm): bool
    {
        return Permissions::can($this->roles, $perm);
    }

    /** May make mapping decisions (link, new item, ignore, reject, unlink). */
    public function canDecide(): bool
    {
        return $this->can('mapping.decide');
    }

    /** May give the second approval of a pending decision. */
    public function isLead(): bool
    {
        return $this->can('mapping.approve');
    }

    /** "mapper, reviewer", or "no roles". */
    public function rolesLabel(): string
    {
        return $this->roles === [] ? 'no roles' : implode(', ', $this->roles);
    }

    /** "Your role (viewer)" / "Your roles (a, b)" as the start of a sentence; lower-case first letter with $lower. */
    public function rolesPhrase(bool $lower = false): string
    {
        $s = (count($this->roles) === 1 ? 'Your role (' : 'Your roles (') . $this->rolesLabel() . ')';
        return $lower ? lcfirst($s) : $s;
    }
}
