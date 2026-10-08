<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Login;
use CW\Auth\Permissions;
use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\Output\QrCode;
use CW\Settings;
use CW\Staff\RoleRequests;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Staff\StaffSessions;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Staff and access (IM1, I13): who works on CW, what jobs (roles) they hold, since when and who gave them. Admin and
 * auditor look (staff.view); only an admin changes roles or switches an account on or off (staff.manage), never
 * their own, through StaffAdmin (which re-checks all of it inside its transaction). Until the set-it-yourself pack new people
 * and their secrets were made on the server only (I13); since Y20-Y22 / Y40-Y44 (below) the admin adds people and resets
 * sign-ins here, a sheet's secret shown once and never the person's for good.
 *
 * The role form carries the roles the page was drawn with (`roles_seen`): when another admin changed them since,
 * the save is refused 409 and the page says what they are now. Every refusal re-renders the page under its status
 * (422 role_conflict / no_roles, 409 roles_changed, 403 own_account, 409 placeholder_account) with the error and the
 * choices kept. A placeholder account (an e-mail under .invalid) is never switched on or given a role here (I35).
 *
 * Since the set-it-yourself pack (G06, docs/decisions.md Y20-Y25, amended by Y40-Y44) the admin also adds people here
 * (StaffAdmin::enrol: the answer is their sign-up sheet, shown ONCE: a QR code and a one-time set-up code; nothing secret is stored
 * or shown again, no password ever appears), makes a new sign-up sheet for someone who never finished (newSheet), a new sign-in code
 * for a lost phone (resetAuthenticator: it works only with the person's own password) and lets a person choose a new password
 * (resetPassword: a sheet with a set-up code only; they finish with the code app on their own phone). The admin never holds both
 * factors of anybody: each person finishes on their own page with a fresh secret the admin never sees. Each of those forms runs once
 * (FormOnce, M4): sent again, it shows no new secret. Signing people out of one device or all (signOut); every live session is
 * listed. While the owner has the staff-grant / staff-reset rules on, giving Admin or Reviewer, or resetting the sign-in of someone
 * who has them, becomes a request a reviewer decides first (RoleRequests).
 */
final class PeopleController
{
    /** notice key => text (Ui\Words). Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = Words::STAFF_NOTICE;
    /**
     * Decision 3: at least two reviewers (the owner as backup), so holidays never stop the reviews. The default of the setting
     * staff.min_reviewers (0019): minReviewers() reads it.
     */
    public const MIN_REVIEWERS = 2;

    /** The reviewers the warnings ask for now (staff.min_reviewers, the Settings page). */
    public static function minReviewers(\CW\Db $db): int
    {
        return Settings::number($db, 'staff.min_reviewers', self::MIN_REVIEWERS);
    }

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
        $min = self::minReviewers($db);
        if ($reviewers < $min) {
            $warnings[] = Words::say('STAFF', $reviewers === 0 ? 'reviewers_none' : 'reviewers_one', $min)
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
        return $this->listPage($ctx, $people, $warnings, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    /**
     * The list page: the people, the warnings, the add form (an admin), everyone signed in now, the open requests.
     *
     * @param list<array<string, mixed>> $people
     * @param list<string> $warnings
     * @param array<string, mixed>|null $typed what a refused add form sent (kept)
     */
    private function listPage(Context $ctx, array $people, array $warnings, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $me = $ctx->me();
        return $ctx->page('people', [
            'people' => $people,
            'warnings' => $warnings,
            'meId' => $me->id,
            'lookOnly' => $me->can('staff.manage') ? null : Words::STAFF['look_only'],
            'clash' => array_filter($people, static fn (array $p): bool => Permissions::switchedOff($p['roles']) !== []) !== [],
            'canManage' => $me->can('staff.manage'),
            'groups' => self::roleGroups($typed['roles'] ?? []),
            'typed' => ['name' => (string) ($typed['name'] ?? ''), 'email' => (string) ($typed['email'] ?? '')],
            'error' => $error === null ? null : self::plain($error, [], $typed['roles'] ?? []),
            'errorCode' => $error?->errorCode,
            'devices' => StaffSessions::live($ctx->db),
            'requests' => count((new RoleRequests($ctx->db))->pending()),
        ], $status, ['title' => Words::MENU['people'], 'active' => 'people', 'notice' => $notice]);
    }

    /**
     * Adds a person (G06, Y20, Y40): the answer is their sign-up sheet with the QR code and the set-up code, shown ONCE (no redirect:
     * a reload asks the browser to send the form again, which StaffAdmin refuses as staff_exists). A refusal comes back on the list,
     * typed kept.
     */
    public function create(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $roles = [];
        foreach (Permissions::ROLES as $role) {
            if ($req->field('role_' . $role) === '1') {
                $roles[] = $role;
            }
        }
        $typed = ['name' => (string) ($req->field('name') ?? ''), 'email' => (string) ($req->field('email') ?? ''), 'roles' => $roles];
        try {
            $made = (new StaffAdmin($ctx->db))->enrol($ctx->caller(), $typed['email'], $roles, $ctx->secretBox(), $typed['name']);
        } catch (CwException $e) {
            return $this->listPage($ctx, StaffRoles::people($ctx->db), [], $e->httpStatus, $e, $typed);
        }
        return $this->sheet($ctx, 'signup', $made['id'], (string) $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [$made['id']]), $made['email'],
            $made['otpauth'], $made['secret'], $made['setup_code'], $made['setup_until'], $made['request'] === null ? null : $made['roles']);
    }

    /** A new sign-up sheet for someone who never finished setting up (Y41): the sheet once. Needs its tick; runs once (FormOnce). */
    public function newSheet(Context $ctx): HtmlResponse
    {
        return $this->reset($ctx, 'sheet', static fn (Context $ctx, Db $db, int $id): array => (new StaffAdmin($db))->newSheet($ctx->caller(), $id, $ctx->secretBox()));
    }

    /** A new sign-in code for a lost or new phone (Y22, Y43): the sheet with the QR code, once. Needs its tick; runs once (FormOnce, M4). */
    public function authenticator(Context $ctx): HtmlResponse
    {
        return $this->reset($ctx, 'code', static fn (Context $ctx, Db $db, int $id): array => (new StaffAdmin($db))->resetAuthenticator($ctx->caller(), $id, $ctx->secretBox()));
    }

    /** Lets the person choose a new password (Y22, Y43): the sheet with the set-up code, once. Needs its tick; runs once (FormOnce). */
    public function password(Context $ctx): HtmlResponse
    {
        return $this->reset($ctx, 'password', static fn (Context $ctx, Db $db, int $id): array => (new StaffAdmin($db))->resetPassword($ctx->caller(), $id));
    }

    /**
     * One of the three resets: the tick, then the service inside FormOnce (the same form sent again replays the first answer's
     * redirect, "shown once already", and makes nothing new), then the sheet. A reset waiting for a reviewer (approvals.staff_reset)
     * goes back to the person's page.
     *
     * @param \Closure(Context, Db, int): array<string, mixed> $do
     */
    private function reset(Context $ctx, string $kind, \Closure $do): HtmlResponse
    {
        $id = $ctx->id();
        if ($ctx->req->field('confirm') !== '1') {
            return $this->personPage($ctx, $id, 422, new CwException('unconfirmed', Words::STAFF['reset_unconfirmed'], 422), null);
        }
        $made = null;
        try {
            $r = FormOnce::run($ctx, 'ui.staff.reset_' . $kind, ['id' => $id], static function (Db $db) use ($ctx, $id, $do, &$made): OpResult {
                $made = $do($ctx, $db, $id);
                return OpResult::of(303, ['result' => $made['result'],
                    'redirect' => Html::url("/ui/people/{$id}", ['notice' => $made['result'] === 'requested' ? 'reset_requested' : 'sheet_shown'])]);
            });
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, null);
        }
        if ($made === null || $made['result'] !== 'done') {
            return FormOnce::redirect($r); // sent again (nothing new was made), or waiting for a reviewer
        }
        $name = (string) $ctx->db->value('SELECT display_name FROM staff_user WHERE id = ?', [$id]);
        return $this->sheet($ctx, $kind === 'sheet' ? 'signup' : $kind, $id, $name, (string) $made['email'], $made['otpauth'] ?? null, $made['secret'] ?? null,
            $made['setup_code'] ?? null, $made['setup_until'] ?? null, null);
    }

    /** Signs a person out of one device (`session`: its handle) or everywhere (`session=all`), Y23. */
    public function signOut(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $which = (string) ($ctx->req->field('session') ?? '');
        $back = $ctx->req->field('back') === 'list' ? '/ui/people' : "/ui/people/{$id}";
        try {
            $ended = (new StaffAdmin($ctx->db))->signOut($ctx->caller(), $id, $which === 'all' ? null : $which);
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect(Html::url($back, ['notice' => $ended === 0 ? 'not_signed_in' : ($which === 'all' ? 'signed_out_all' : 'signed_out')]));
    }

    /** Withdraws the open request for Admin or Reviewer, or for a reset (Y25, Y44). */
    public function withdrawRequest(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            (new RoleRequests($ctx->db))->withdraw($ctx->caller(), (int) ($ctx->req->field('request') ?? 0));
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect("/ui/people/{$id}?notice=request_withdrawn");
    }

    /**
     * The address staff open, from the setting staff.sign_in_address (the Settings page; review nit: never the Host header a
     * request carried), with $path; null while it is not set.
     */
    public static function signInAddress(Context $ctx, string $path): ?string
    {
        $base = $ctx->settings()->has('staff.sign_in_address') ? trim((string) $ctx->settings()->get('staff.sign_in_address')) : '';
        return $base === '' ? null : rtrim($base, '/') . $path;
    }

    /**
     * A sheet (Y21, Y40-Y43), ONCE, in this answer only (no-store): `signup` (a new person or a new sign-up sheet: the QR code, its key
     * and the set-up code, for /ui/enrol), `code` (a new sign-in code: the QR code and its key, used with their own password at the
     * sign-in), `password` (a new password: the set-up code only, used with the code app on their own phone at /ui/enrol). The steps
     * the person follows, when their window ends, the jobs waiting for a reviewer's OK.
     *
     * @param list<string>|null $waiting the jobs the person has until a reviewer's OK (null: no request)
     */
    private function sheet(Context $ctx, string $kind, int $id, string $name, string $email, ?string $otpauth, ?string $secret, ?string $setupCode, ?string $until,
        ?array $waiting): HtmlResponse
    {
        $address = self::signInAddress($ctx, $kind === 'code' ? '/ui/login' : '/ui/enrol');
        return $ctx->page('staff_sheet', [
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'qr' => $otpauth === null ? null : QrCode::matrix($otpauth),
            'key' => $secret === null ? null : trim(implode(' ', str_split($secret, 4))),
            'setupCode' => $setupCode,
            'address' => $address ?? Words::say('SHEET', 'no_address', Words::ASK),
            'addressMissing' => $address === null,
            'until' => $until,
            'minPassword' => Login::MIN_PASSWORD,
            'waiting' => $waiting === null ? null : Words::roles($waiting),
        ], 200, ['title' => Words::say('SHEET', 'title_' . $kind, $name), 'active' => 'people']);
    }

    /**
     * The job boxes, grouped (the add form and the person page).
     *
     * @param list<string> $checked
     * @return list<array{label: string, roles: list<array<string, mixed>>}>
     */
    private static function roleGroups(array $checked): array
    {
        $groups = [];
        foreach (Permissions::ROLE_GROUPS as $label => $roles) {
            $items = [];
            foreach ($roles as $role) {
                $items[] = ['role' => $role, 'name' => Words::of('ROLE', $role), 'description' => Words::of('ROLE_HELP', $role), 'checked' => in_array($role, $checked, true)];
            }
            $groups[] = ['label' => Words::of('ROLE_GROUP', $label), 'roles' => $items];
        }
        return $groups;
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
        return HtmlResponse::redirect("/ui/people/{$id}?notice=" . match ($r['result']) {
            'unchanged' => 'roles_unchanged',
            'requested' => 'requested',
            default => 'roles_saved',
        });
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
            'devices' => StaffSessions::live($db, $id),
            'setup' => StaffAdmin::setupInfo($db, $id),
            'request' => (new RoleRequests($db))->openFor($id),
            'approvedReset' => RoleRequests::approvedReset($db, $id),
            'canManage' => $me->can('staff.manage') && $me->id !== $id,
            'formKeys' => ['sheet' => FormOnce::newKey(), 'code' => FormOnce::newKey(), 'password' => FormOnce::newKey()],
            'addressMissing' => self::signInAddress($ctx, '/ui/enrol') === null,
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
            'request_open', 'bad_name', 'bad_email', 'staff_exists', 'bad_session', 'not_set_up', 'code_reset_open', 'already_set_up' => Words::STAFF[$e->errorCode],
            'setup_open' => Words::STAFF['code_refused_open'],
            'request_closed', 'unknown_request' => Words::STAFF_REQUESTS['request_closed'],
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
