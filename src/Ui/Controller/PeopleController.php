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

/**
 * People and roles (IM1, I13): who works on CW, what roles they hold, since when and who gave them. Admin and
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
    /** notice key => text. Only these can be shown: a notice never comes from the URL as text. */
    public const NOTICES = [
        'roles_saved' => 'Roles saved. They take effect on the person\'s next page.',
        'roles_unchanged' => 'Nothing changed: the person already had exactly these roles.',
        'deactivated' => 'Account switched off: the person can no longer sign in, and every session of theirs has ended.',
        'activated' => 'Account switched on: the person can sign in again.',
    ];
    /** Decision 3: at least two reviewers (the owner as backup), so holidays never stop the reviews. */
    public const MIN_REVIEWERS = 2;

    public function index(Context $ctx): HtmlResponse
    {
        $db = $ctx->db;
        $warnings = [];
        $reviewers = StaffRoles::activeCount($db, 'reviewer');
        if ($reviewers < self::MIN_REVIEWERS) {
            $warnings[] = ($reviewers === 0 ? 'No active person holds' : 'Only ' . $reviewers . ' active person holds')
                . ' the reviewer role: at least ' . self::MIN_REVIEWERS . ' are needed (decision 3), so that a holiday never stops the reviews.'
                . ' The owner is the backup reviewer, and therefore not an admin.';
        }
        if (StaffRoles::activeCount($db, 'admin') === 0) {
            $warnings[] = 'No active person holds the admin role: nobody can change roles on this screen. On the server, '
                . 'bin/reset_staff.php --email=<address> --roles=admin gives it to someone who is not a reviewer.';
        }
        $people = StaffRoles::people($db);
        foreach ($people as $p) {
            if ($p['is_active'] && StaffAdmin::isPlaceholder($p['email'])) {
                $warnings[] = "{$p['email']} is a placeholder account (an e-mail under .invalid) and it is switched on: switch it off "
                    . '(bin/reset_staff.php --email=<it> --deactivate). An active placeholder is a working second identity that defeats '
                    . 'the two-person rule (U23).';
            }
        }
        return $ctx->page('people', [
            'people' => $people,
            'warnings' => $warnings,
            'meId' => $ctx->me()->id,
        ], 200, ['title' => 'People and roles', 'active' => 'people']);
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
            return $this->personPage($ctx, $id, 400, new CwException('bad_form', 'the form is incomplete: reload the page and try again', 400), $chosen);
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
            return $this->personPage($ctx, $id, 400, new CwException('bad_form', 'the form is incomplete: reload the page and try again', 400), null);
        }
        try {
            (new StaffAdmin($ctx->db))->setActive($ctx->caller(), $id, $want === '1');
        } catch (CwException $e) {
            return $this->personPage($ctx, $id, $e->httpStatus, $e, null);
        }
        return HtmlResponse::redirect("/ui/people/{$id}?notice=" . ($want === '1' ? 'activated' : 'deactivated'));
    }

    /**
     * The person page: details, the role form (admin, someone else) or why it is read-only, the switch, the history.
     *
     * @param list<string>|null $chosen the roles ticked on a refused form (kept), else the person's live roles
     */
    private function personPage(Context $ctx, int $id, int $status, ?CwException $error, ?array $chosen, ?string $notice = null): HtmlResponse
    {
        $db = $ctx->db;
        $person = $db->one('SELECT id, display_name, email, is_active, last_login_at, created_at FROM staff_user WHERE id = ?', [$id]);
        if ($person === null) {
            return $ctx->error(404, 'unknown_staff', 'there is no such person');
        }
        $me = $ctx->me();
        $live = StaffRoles::of($db, $id);
        $checked = $chosen ?? $live;
        $readOnly = null;
        if (!$me->can('staff.manage')) {
            $readOnly = $me->rolesPhrase() . ' can look at people and roles but not change them.';
        } elseif ($me->id === $id) {
            $readOnly = 'This is your own account: nobody changes their own roles or switches their own account off. Ask another admin.';
        }
        $groups = [];
        foreach (Permissions::ROLE_GROUPS as $label => $roles) {
            $items = [];
            foreach ($roles as $role) {
                $items[] = ['role' => $role, 'description' => Permissions::DESCRIPTIONS[$role], 'checked' => in_array($role, $checked, true)];
            }
            $groups[] = ['label' => $label, 'roles' => $items];
        }
        $message = null;
        if ($error !== null) {
            $message = $error->getMessage();
            if ($error->errorCode === 'roles_changed') {
                $message .= '. Roles now: ' . (implode(', ', $live) ?: 'none') . '. Your choices are kept below: check them and save again.';
            }
        }
        $history = [];
        foreach (StaffRoles::history($db, $id) as $h) {
            $history[] = [
                'role' => (string) $h['role'],
                'granted_at' => $h['granted_at'],
                'granted_by' => $h['granted_by'] === null ? 'server tool or migration' : (string) ($h['granted_by_name'] ?? '#' . $h['granted_by']),
                'revoked_at' => $h['revoked_at'],
                'revoked_by' => $h['revoked_at'] === null ? null
                    : ($h['revoked_by'] === null ? 'server tool' : (string) ($h['revoked_by_name'] ?? '#' . $h['revoked_by'])),
            ];
        }
        return $ctx->page('person', [
            'person' => ['id' => (int) $person['id'], 'display_name' => (string) $person['display_name'], 'email' => $person['email'],
                'is_active' => (int) $person['is_active'] === 1, 'last_login_at' => $person['last_login_at'], 'created_at' => $person['created_at']],
            'roles' => $live,
            'rolesSeen' => implode(',', $live),
            'groups' => $groups,
            'readOnly' => $readOnly,
            'error' => $message,
            'errorCode' => $error?->errorCode,
            'history' => $history,
            'adminCompatible' => implode(', ', Permissions::ADMIN_COMPATIBLE),
            'placeholder' => StaffAdmin::isPlaceholder($person['email']),
        ], $status, ['title' => (string) $person['display_name'], 'active' => 'people', 'notice' => $notice]);
    }
}
