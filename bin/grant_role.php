<?php

declare(strict_types=1);

/**
 * Gives one job (role) to an EXISTING staff account, or takes it away, on the server: how the owner's and Fazil's accounts get the People
 * manager job on production without the People screen (docs/decisions.md PM6; the owner, 16 Nov 2026: "i want you to build the option to
 * create users, admin roles etc for my cw account"; 18 Nov 2026: "build it in so Fazil can create [staff] himself"). After that they add
 * people and set their jobs on Settings › People themselves.
 *
 *   php bin/grant_role.php --email=<address> --role=<role> [--revoke] [--apply] [--db=<schema>] [--admin]
 *
 * A DRY RUN unless --apply: it says what the person's jobs are and what they would become, and writes nothing. With --apply the change is
 * made through StaffAdmin::setRoles, as the People screen's own (one transaction, the person's row locked, the jobs as they were when
 * this tool read them or 409 roles_changed), audited `staff.roles` as `system:grant_role` (granted_by empty: given on the server). The
 * other jobs of the person stay as they are; the account is not switched on or off; nobody is signed out (the new jobs work on their
 * next page). A server tool never waits for the staff-grant rule (approvals.staff_grant): it is root on the server.
 *
 * Refused, writing nothing (exit 1): an unknown e-mail or job; a job set the rules refuse (Admin only with the look-only jobs, I12:
 * "admin cannot be combined with ..."; People manager never with Admin; a person needs at least one job: to stop someone, switch the
 * account off); taking the power to manage people (Admin, People manager) from the last active person who has it (PM5: "nobody else can
 * manage people"), in the dry run too. Giving a job the person has, or taking one they do not have: `unchanged`, nothing written.
 *
 * Its command line is read whole or not at all (a misspelt option, `--apply=no`, a stray word: usage, exit 2). Messages: the result on
 * stdout, refusals on stderr; no secret is printed. Exit codes: 0 ok · 1 refused · 2 usage · 3 cannot run.
 */

use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('grant_role', ['email:', 'role:', 'revoke', 'apply'],
    'usage: php bin/grant_role.php --email=<address> --role=<role> [--revoke] [--apply]   (a dry run unless --apply; roles: '
        . implode(', ', Permissions::ROLES) . ')',
    static function (Cli $cli, array $opts): int {
        $email = $opts['email'] ?? null;
        $role = $opts['role'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            throw new InvalidArgumentException('--email=<address> is required');
        }
        if (!is_string($role) || trim($role) === '') {
            throw new InvalidArgumentException('--role=<role> is required (one of ' . implode(', ', Permissions::ROLES) . ')');
        }
        $email = strtolower(trim($email));
        $role = trim($role);
        $revoke = array_key_exists('revoke', $opts);
        $apply = array_key_exists('apply', $opts);
        try {
            Permissions::checkRoleSet([$role]); // 400 bad_role: "a role must be one of ..."
            $u = $cli->db->one('SELECT id, is_active FROM staff_user WHERE email = ?', [$email])
                ?? throw new CwException('unknown_staff', "no staff user {$email} in {$cli->schema}", 404);
            $id = (int) $u['id'];
            $active = (int) $u['is_active'] === 1;
            $before = StaffRoles::of($cli->db, $id);
            $list = static fn (array $roles): string => $roles === [] ? '(none)' : implode(',', $roles);
            if (in_array($role, $before, true) !== $revoke) {
                // Nothing to do (checked before the set's rules: a person with no job yet, waiting for a reviewer's OK, can be asked to lose one they lack).
                $cli->log(sprintf('%s (id %d%s): roles %s: unchanged (%s %s; nothing written)', $email, $id, $active ? '' : ', switched off', $list($before),
                    $revoke ? 'they do not have' : 'they already have', $role));
                return Cli::OK;
            }
            $after = Permissions::checkRoleSet($revoke ? array_values(array_diff($before, [$role])) : [...$before, $role]);
            $what = sprintf('%s (id %d%s): roles %s -> %s', $email, $id, $active ? '' : ', switched off', $list($before), $list($after));
            if (!$apply) {
                if (StaffRoles::takesTheLastManager($cli->db, $id, $after, $active, false)) {
                    throw StaffRoles::lastManagerRefusal();
                }
                $cli->log("DRY RUN: {$what}: would be changed. Nothing was written: run the same command again with --apply to make the change "
                    . '(audited staff.roles as system:grant_role).');
                return Cli::OK;
            }
            $r = (new StaffAdmin($cli->db))->setRoles(Caller::system('grant_role'), $id, $after, $before, true);
            $cli->log(sprintf('%s (id %d): roles %s -> %s: %s', $email, $id, $list($r['before']), $list($r['after']), $r['result']));
            return Cli::OK;
        } catch (CwException $e) {
            fwrite(STDERR, "grant_role: {$e->getMessage()}\n");
            return Cli::PROBLEM;
        }
    }, false, true));
