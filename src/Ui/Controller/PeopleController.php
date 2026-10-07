<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Output\CsvWriter;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Staff and access (IM1, I13): who works on CW, what jobs (roles) they hold, since when and who gave them. Admin and
 * auditor look (staff.view); only an admin changes roles or switches an account on or off (staff.manage), never
 * their own, through StaffAdmin (which re-checks all of it inside its transaction). New people and their secrets
 * are made on the server (bin/create_staff.php): a one-time password or sign-in seed never appears in a browser.
 *
 * The role form carries the roles the page was drawn with (`roles_seen`): when another admin changed them since,
 * the save is refused 409 and the page says what they are now. Every refusal re-renders the page under its status
 * (422 role_conflict / no_roles, 409 roles_changed, 403 own_account, 409 placeholder_account) with the error and the
 * choices kept. A placeholder account (an e-mail under .invalid) is never switched on or given a role here (I35).
 */
final class PeopleController
{
    /** notice key => text (Ui\Words). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = Words::STAFF_NOTICE;
    /** Decision 3: at least two reviewers (the owner as backup), so holidays never stop the reviews. */
    public const MIN_REVIEWERS = 2;

    public function index(Context $ctx): HtmlResponse
    {
        $db = $ctx->db;
        $people = StaffRoles::people($db);
        $warnings = [];
        // How many people can really approve work: a Reviewer whose job Admin does not switch off (plan F429: effective jobs).
        $reviewers = 0;
        $admins = 0;
        foreach ($people as $p) {
            if (!$p['is_active']) {
                continue;
            }
            $off = Permissions::switchedOff($p['roles']);
            $reviewers += in_array('reviewer', $p['roles'], true) && !in_array('reviewer', $off, true) ? 1 : 0;
            $admins += in_array('admin', $p['roles'], true) ? 1 : 0;
        }
        if ($reviewers < self::MIN_REVIEWERS) {
            $warnings[] = Words::say('STAFF', $reviewers === 0 ? 'reviewers_none' : 'reviewers_one', self::MIN_REVIEWERS)
                . (StaffRoles::activeCount($db, 'reviewer') > $reviewers ? ' ' . Words::STAFF['reviewers_admin'] : '');
        }
        if ($admins === 0) {
            $warnings[] = Words::STAFF['no_admin'];
        }
        $me = $ctx->me();
        foreach ($people as $p) {
            if ($p['is_active'] && StaffAdmin::isPlaceholder($p['email'])) {
                $warnings[] = Words::say('STAFF', 'test_account', (string) $p['email']);
            }
            $off = Permissions::switchedOff($p['roles']);
            if ($p['is_active'] && $off !== [] && $p['id'] !== $me->id) {
                // The fix is on that person's page: untick Admin (correction a: never a second account).
                $warnings[] = Words::say('STAFF', 'clash', (string) $p['display_name'],
                    Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), self::inJobOrder($off))));
            }
        }
        return $ctx->page('people', [
            'people' => $people,
            'warnings' => $warnings,
            'meId' => $me->id,
            'lookOnly' => $me->can('staff.manage') ? null : Words::STAFF['look_only'],
            'clash' => array_filter($people, static fn (array $p): bool => Permissions::switchedOff($p['roles']) !== []) !== [],
        ], 200, ['title' => Words::MENU['people'], 'active' => 'people']);
    }

    /**
     * The People list as CSV (staff.view; Excel-safe: display names are typed by people, so a name like
     * =HYPERLINK(...) is written as text, I25). No secrets: no hashes, seeds or session data.
     */
    public function csv(Context $ctx): HtmlResponse
    {
        $csv = new CsvWriter([['id', 'number'], ['name', 'text'], ['email', 'text'], ['roles', 'text'], ['active', 'text'],
            ['last_sign_in_utc', 'text'], ['created_utc', 'text']]);
        foreach (StaffRoles::people($ctx->db) as $p) {
            $csv->add([$p['id'], $p['display_name'], $p['email'], implode(', ', $p['roles']), $p['is_active'] ? 'yes' : 'no',
                $p['last_login_at'] === null ? null : substr((string) $p['last_login_at'], 0, 19), substr((string) $p['created_at'], 0, 19)]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'people.csv');
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->personPage($ctx, $ctx->id(), 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function roles(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $chosen = [];
        foreach (Permissions::ROLES as $role) {
            if ($ctx->req->field('role_' . $role) === '1') {
                $chosen[] = $role;
            }
        }
        $seen = $ctx->req->field('roles_seen');
        if ($seen === null) {
            return $this->personPage($ctx, $id, 400, new CwException('bad_form', Words::ERROR['bad_form'], 400), $chosen);
        }
        try {
            $r = (new StaffAdmin($ctx->db))->setRoles($ctx->caller(), $id, $chosen, $seen === '' ? [] : explode(',', $seen));
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, $chosen);
        }
        return HtmlResponse::redirect("/ui/people/{$id}?notice=" . ($r['result'] === 'unchanged' ? 'roles_unchanged' : 'roles_saved'));
    }

    public function active(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $want = $ctx->req->field('active');
        if ($want !== '0' && $want !== '1') {
            return $this->personPage($ctx, $id, 400, new CwException('bad_form', Words::ERROR['bad_form'], 400), null);
        }
        if ($want === '0' && $ctx->req->field('confirm') !== '1') {
            // Behaviour item 4 (F441, provisional): stopping someone signing in needs its tick-box, also when a browser skips the
            // `required` (nothing is changed without it).
            return $this->personPage($ctx, $id, 422, new CwException('unconfirmed', Words::STAFF['stop_unconfirmed'], 422), null);
        }
        try {
            (new StaffAdmin($ctx->db))->setActive($ctx->caller(), $id, $want === '1');
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect("/ui/people/{$id}?notice=" . ($want === '1' ? 'activated' : 'deactivated'));
    }

    /**
     * The person page: details, the job form (admin, someone else) or why it is read-only, the switch, the history. The
     * service's refusals are shown in the page's words by their code (Words::STAFF; the service messages are the API's).
     *
     * @param list<string>|null $chosen the roles ticked on a refused form (kept), else the person's live roles
     */
    private function personPage(Context $ctx, int $id, int $status, ?CwException $error, ?array $chosen, ?string $notice = null): HtmlResponse
    {
        $db = $ctx->db;
        $person = $db->one('SELECT id, display_name, email, is_active, last_login_at, created_at FROM staff_user WHERE id = ?', [$id]);
        if ($person === null) {
            return $ctx->error(404, 'unknown_staff', 'there is no such person', ['/ui/people', Words::MENU['people']]);
        }
        $me = $ctx->me();
        $live = StaffRoles::of($db, $id);
        $checked = $chosen ?? $live;
        $readOnly = null;
        if (!$me->can('staff.manage')) {
            $readOnly = Words::STAFF['auditor'];
        } elseif ($me->id === $id) {
            $readOnly = Words::STAFF['yours'];
        }
        $groups = [];
        foreach (Permissions::ROLE_GROUPS as $label => $roles) {
            $items = [];
            foreach ($roles as $role) {
                $items[] = ['role' => $role, 'name' => Words::of('ROLE', $role), 'description' => Words::of('ROLE_HELP', $role), 'checked' => in_array($role, $checked, true)];
            }
            $groups[] = ['label' => Words::of('ROLE_GROUP', $label), 'roles' => $items];
        }
        $history = [];
        foreach (StaffRoles::history($db, $id) as $h) {
            $history[] = [
                'role' => (string) $h['role'],
                'granted_at' => $h['granted_at'],
                'granted_by' => $h['granted_by'] === null ? Words::STAFF['server'] : (string) ($h['granted_by_name'] ?? '#' . $h['granted_by']),
                'revoked_at' => $h['revoked_at'],
                'revoked_by' => $h['revoked_at'] === null ? null
                    : ($h['revoked_by'] === null ? Words::STAFF['server_taken'] : (string) ($h['revoked_by_name'] ?? '#' . $h['revoked_by'])),
            ];
        }
        $off = self::inJobOrder(Permissions::switchedOff($live));
        return $ctx->page('person', [
            'person' => ['id' => (int) $person['id'], 'display_name' => (string) $person['display_name'], 'email' => $person['email'],
                'is_active' => (int) $person['is_active'] === 1, 'last_login_at' => $person['last_login_at'], 'created_at' => $person['created_at']],
            'roles' => $live,
            'rolesSeen' => implode(',', $live),
            'groups' => $groups,
            'readOnly' => $readOnly,
            // Admin with working jobs: the jobs do nothing until Admin is unticked (plan F035; correction a: the one fix).
            'clash' => $off === [] ? null : Words::say('STAFF', 'clash_person', Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $off))),
            'error' => $error === null ? null : self::plain($error, $live, $chosen ?? []),
            'errorCode' => $error?->errorCode,
            'history' => $history,
            'adminCompatible' => Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), Permissions::ADMIN_COMPATIBLE), 'or'),
            'placeholder' => StaffAdmin::isPlaceholder($person['email']),
        ], $status, ['title' => (string) $person['display_name'], 'active' => 'people', 'notice' => $notice]);
    }

    /**
     * A refusal of StaffAdmin in the page's words, by its code (plan F438-F440, F447, F451). Any other code keeps the
     * service's message.
     *
     * @param list<string> $live the person's jobs now
     * @param list<string> $chosen the jobs ticked on the refused form
     */
    private static function plain(CwException $e, array $live, array $chosen): string
    {
        $jobs = static fn (array $roles): string => $roles === [] ? Words::UI['no_jobs'] : Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $roles));
        return match ($e->errorCode) {
            'role_conflict' => (static function () use ($e, $chosen, $jobs): string {
                $conflict = is_array($e->detail['conflict'] ?? null) ? array_map('strval', $e->detail['conflict'])
                    : array_values(array_diff($chosen, ['admin'], Permissions::ADMIN_COMPATIBLE));
                $names = $jobs(self::inJobOrder($conflict));
                return Words::say('STAFF', 'role_conflict', $names, $names);
            })(),
            'no_roles' => Words::STAFF['no_roles'],
            'roles_changed' => Words::say('STAFF', 'roles_changed', $jobs(self::inJobOrder($live))),
            'own_account' => Words::STAFF['own_account'],
            'placeholder_account' => Words::STAFF['placeholder'],
            'role_not_allowed' => Words::STAFF['not_admin'],
            default => Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** @param list<string> $roles @return list<string> the roles in the order jobs are named (Words::JOB_ORDER) */
    private static function inJobOrder(array $roles): array
    {
        $roles = array_values($roles);
        usort($roles, static fn (string $a, string $b): int => array_search($a, Words::JOB_ORDER, true) <=> array_search($b, Words::JOB_ORDER, true));
        return $roles;
    }
}
