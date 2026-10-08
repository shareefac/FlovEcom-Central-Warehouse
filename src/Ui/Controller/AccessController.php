<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Permissions;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * "Who can do what" (G37, docs/decisions.md Y35): the permission map (Auth\Permissions::MAP, the one place that says what a job may
 * do) in plain words, by job and by task, with the rules that always apply. Generated from the code on every request, so it can
 * never disagree with what the system does. Read only, for everyone (reference.view).
 */
final class AccessController
{
    public function index(Context $ctx): HtmlResponse
    {
        $jobs = [];
        foreach (Words::JOB_ORDER as $role) {
            $can = [];
            foreach (Permissions::permissionsOf([$role]) as $perm) {
                $can[] = Words::of('PERMISSION', $perm);
            }
            $jobs[] = ['role' => $role, 'name' => Words::of('ROLE', $role), 'help' => Words::of('ROLE_HELP', $role), 'can' => $can,
                'mine' => in_array($role, $ctx->me()->roles, true)];
        }
        $tasks = [];
        foreach (Permissions::MAP as $perm => $holders) {
            usort($holders, static fn (string $a, string $b): int => array_search($a, Words::JOB_ORDER, true) <=> array_search($b, Words::JOB_ORDER, true));
            $tasks[] = ['what' => Words::of('PERMISSION', $perm),
                'who' => count($holders) === count(Permissions::ROLES) ? Words::whoCan($perm) : Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $holders))];
        }
        return $ctx->page('access', [
            'byJob' => $jobs,
            'tasks' => $tasks,
            'adminCompatible' => Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), Permissions::ADMIN_COMPATIBLE), 'or'),
            'canSeeStaff' => $ctx->me()->can('staff.view'),
        ], 200, ['title' => Words::title('access'), 'active' => 'access']);
    }
}
