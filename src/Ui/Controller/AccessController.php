<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ConfigHistory;
use CW\Admin\RolePermissions;
use CW\Auth\Permissions;
use CW\CwException;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * "Roles and permissions" (G37, docs/decisions.md Y35; pack E, PE2-PE8): the permission map in force (Auth\Permissions::map(), read
 * from role_permission on every request) in plain words, by job and by task, with the rules that always apply. Everyone looks
 * (reference.view). An admin or a reviewer (settings.manage) opens one task (`?perm=`) and changes which jobs can do it, with a reason,
 * through Admin\RolePermissions (which re-checks everything: the locked tasks, admin and the look-only jobs, the guarded jobs' own
 * tasks, "only a Reviewer gives", "never to a job you have"). Refusals are shown in words by their code, with the ticks kept.
 */
final class AccessController
{
    /** notice key => words: only these can be shown (a notice never comes from the URL as text). */
    public const NOTICES = ['saved' => Words::ROLES_EDIT['saved'], 'unchanged' => Words::ROLES_EDIT['unchanged']];

    public function index(Context $ctx): HtmlResponse
    {
        $perm = $ctx->req->param('perm');
        if ($perm !== null) {
            return $this->permission($ctx, $perm, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
        }
        $map = Permissions::map();
        $jobs = [];
        foreach (Words::JOB_ORDER as $role) {
            $can = [];
            foreach (Permissions::permissionsOf([$role]) as $p) {
                $can[] = Words::of('PERMISSION', $p);
            }
            $jobs[] = ['role' => $role, 'name' => Words::of('ROLE', $role), 'help' => Words::of('ROLE_HELP', $role), 'can' => $can,
                'mine' => in_array($role, $ctx->me()->roles, true)];
        }
        $canChange = $ctx->me()->can('settings.manage');
        $tasks = [];
        foreach ($map as $p => $holders) {
            usort($holders, static fn (string $a, string $b): int => array_search($a, Words::JOB_ORDER, true) <=> array_search($b, Words::JOB_ORDER, true));
            $tasks[] = ['what' => Words::of('PERMISSION', $p),
                'who' => count($holders) === count(Permissions::ROLES) ? Words::whoCan($p)
                    : ($holders === [] ? Words::ROLES_EDIT['none'] : Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $holders))),
                'fixed' => in_array($p, Permissions::LOCKED, true),
                'href' => Html::url('/ui/reference/access', ['perm' => $p])];
        }
        return $ctx->page('access', [
            'byJob' => $jobs,
            'tasks' => $tasks,
            'adminCompatible' => Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), Permissions::ADMIN_COMPATIBLE), 'or'),
            'canSeeStaff' => $ctx->me()->can('staff.view'),
            'canChange' => $canChange,
        ], 200, ['title' => Words::title('access'), 'active' => 'access']);
    }

    /** POST /ui/reference/access: which jobs can do one task (settings.manage; RolePermissions re-checks every rule). */
    public function save(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $perm = (string) ($req->field('perm') ?? '');
        $chosen = [];
        foreach (Permissions::ROLES as $role) {
            if ($req->field('job_' . $role) === '1') {
                $chosen[] = $role;
            }
        }
        $typed = ['jobs' => $chosen, 'reason' => (string) ($req->field('reason') ?? '')];
        $seen = $req->field('seen');
        if ($seen === null || preg_match('/^\d{1,9}$/D', $seen) !== 1) {
            return $this->permission($ctx, $perm, 400, new CwException('bad_version', Words::ERROR['bad_version'], 400), $typed);
        }
        try {
            $r = RolePermissions::change($ctx->db, $ctx->caller(), $perm, $chosen, $typed['reason'], (int) $seen);
        } catch (CwException $e) {
            return $this->permission($ctx, $perm, $e->httpStatus, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url('/ui/reference/access', ['perm' => $perm, 'notice' => $r['changed'] ? 'saved' : 'unchanged']));
    }

    /** A refusal of RolePermissions::change in the page's words, by its code; another code keeps the service's message. */
    public static function plain(CwException $e): string
    {
        $jobs = static fn (mixed $list): string => Words::andList(array_map(static fn (string $r): string => Words::of('ROLE_PLURAL', $r),
            array_values(array_filter(is_array($list) ? $list : [], 'is_string'))));
        return match ($e->errorCode) {
            'own_job' => sprintf(Words::ROLES_ERROR['own_job'], $jobs($e->detail['roles'] ?? [])),
            'required_job' => sprintf(Words::ROLES_ERROR['required_job'], $jobs($e->detail['required'] ?? [])),
            'loosen_needs_reviewer', 'permission_locked', 'acting_job', 'no_holders', 'bad_role', 'unknown_permission' => Words::ROLES_ERROR[$e->errorCode],
            'changed_meanwhile' => Words::say('CONFIG_ERROR', 'changed_meanwhile', ConfigWords::who((string) ($e->detail['by'] ?? ''), null),
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null)),
            'bad_reason', 'role_not_allowed', 'staff_not_allowed' => Words::CONFIG_ERROR[$e->errorCode],
            default => Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @param array{jobs: list<string>, reason: string}|null $typed what a refused form sent (kept) */
    private function permission(Context $ctx, string $perm, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        if (!isset(Permissions::MAP[$perm])) {
            return $ctx->error(404, 'no_permission', 'there is no such task', ['/ui/reference/access', Words::title('access')]);
        }
        $row = null;
        foreach (RolePermissions::all($ctx->db) as $r) {
            if ($r['perm'] === $perm) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            return $ctx->error(404, 'no_permission', 'there is no such task', ['/ui/reference/access', Words::title('access')]);
        }
        $me = $ctx->me();
        $canChange = $me->can('settings.manage') && !$row['locked'];
        $ticked = $typed['jobs'] ?? $row['holders'];
        $boxes = [];
        foreach (Words::JOB_ORDER as $role) {
            $blocked = !$row['read_only'] && ($role === 'admin' || in_array($role, Permissions::ADMIN_COMPATIBLE, true));
            $always = in_array($role, $row['required'], true);
            $boxes[] = ['role' => $role, 'name' => Words::of('ROLE', $role), 'checked' => $always || in_array($role, $ticked, true),
                'disabled' => $blocked || $always, 'always' => $always,
                'why' => match (true) {
                    $always => Words::ROLES_EDIT['always'],
                    $blocked && $role === 'admin' => Words::ROLES_EDIT['admin_never'],
                    $blocked => Words::ROLES_EDIT['looks_only'],
                    in_array($role, $me->roles, true) => Words::ROLES_EDIT['yours'],
                    default => null,
                }];
        }
        $holders = Permissions::inCodeOrder($perm, $row['holders']);
        return $ctx->page('access_permission', [
            'perm' => $perm,
            'what' => Words::of('PERMISSION', $perm),
            'who' => $holders === [] ? Words::ROLES_EDIT['none'] : Words::andList(array_map(static fn (string $r): string => Words::of('ROLE_PLURAL', $r), $holders)),
            'kind' => Words::ROLES_EDIT[$row['read_only'] ? 'read_only' : 'acts'],
            'fixed' => $row['locked'],
            'fromCode' => $row['from_code'],
            'canChange' => $canChange,
            'lookOnly' => $me->can('settings.manage') ? null : Words::ROLES_EDIT['look_only'],
            'boxes' => $boxes,
            'reason' => $typed['reason'] ?? '',
            'seen' => $row['version'],
            'history' => ConfigWords::history($ctx->db, 'permission', $perm),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::of('PERMISSION', $perm), 'active' => 'access', 'notice' => $notice]);
    }
}
