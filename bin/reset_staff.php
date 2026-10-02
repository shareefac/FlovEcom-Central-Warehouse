<?php

declare(strict_types=1);

/**
 * Recovers or switches off an existing staff account for the /ui screens (accounts are never deleted
 * or re-created: decisions and audit rows name them). Any of:
 *
 *   --new-password   a new one-time password (printed ONCE), to be changed at the next sign-in
 *   --new-totp       a new TOTP secret (its otpauth:// URI printed ONCE; the old codes stop working)
 *   --deactivate     the account can no longer sign in (its sessions end at once)
 *   --activate       switch it back on
 *   --roles=<list>   replace the person's roles with this comma list (StaffAdmin::setRoles: the same rules as the
 *                    People and roles screen, e.g. admin only with viewer, accountant, auditor; audited staff.roles;
 *                    the break-glass when no admin can sign in, I12, I15); stderr shows `roles: a,b -> c,d`
 *
 *   php bin/reset_staff.php --email=<address> [--new-password] [--new-totp] [--deactivate | --activate] [--roles=<list>]
 *       [--db=<schema>] [--admin]
 *
 * Every reset of a secret or of the account's state signs the person out everywhere (staff_session revoked) and is
 * audited (staff.reset, staff.deactivate, staff.activate; never a secret); a role change alone takes effect on the
 * person's next request and keeps their sessions. STDOUT gets the new secrets and nothing else;
 * messages go to STDERR. Run on the CW server as root (app.env is 0640 root:www-data).
 * Exit codes: 0 ok · 1 refused (unknown account, nothing asked) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Ops\Cli;
use CW\Staff\SecretBox;
use CW\Staff\StaffAdmin;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('reset_staff', ['email:', 'new-password', 'new-totp', 'deactivate', 'activate', 'roles:'],
    'usage: php bin/reset_staff.php --email=<address> [--new-password] [--new-totp] [--deactivate | --activate] [--roles=<role>[,<role>...]]',
    static function (Cli $cli, array $opts): int {
        $email = $opts['email'] ?? null;
        if (!is_string($email)) {
            throw new InvalidArgumentException('--email=<address> is required');
        }
        $newPassword = array_key_exists('new-password', $opts);
        $newTotp = array_key_exists('new-totp', $opts);
        if (array_key_exists('deactivate', $opts) && array_key_exists('activate', $opts)) {
            throw new InvalidArgumentException('--deactivate and --activate exclude each other');
        }
        $active = array_key_exists('deactivate', $opts) ? false : (array_key_exists('activate', $opts) ? true : null);
        $roles = null;
        if (array_key_exists('roles', $opts)) {
            if (!is_string($opts['roles'])) {
                throw new InvalidArgumentException('give --roles once, as a comma list');
            }
            $roles = array_values(array_filter(array_map('trim', explode(',', $opts['roles'])), static fn (string $r): bool => $r !== ''));
        }
        if (!$newPassword && !$newTotp && $active === null && $roles === null) {
            throw new InvalidArgumentException('say what to do: --new-password, --new-totp, --deactivate, --activate or --roles=<list>');
        }
        $box = null;
        if ($newTotp) {
            $key = Config::load()->get(SecretBox::KEY_NAME);
            if ($key === null) {
                throw new RuntimeException('app.env has no ui_secret_key: the TOTP secret cannot be sealed (bin/create_staff.php adds one)');
            }
            $box = SecretBox::fromBase64($key);
        }
        $admin = new StaffAdmin($cli->db);
        $caller = Caller::system('reset_staff');
        try {
            if ($roles !== null) {
                // Roles first (a refused set changes nothing at all), then the rest as before.
                $id = $cli->db->value('SELECT id FROM staff_user WHERE email = ?', [strtolower(trim($email))]);
                if ($id === null) {
                    throw new CwException('unknown_staff', 'no staff user ' . strtolower(trim($email)), 404);
                }
                $set = $admin->setRoles($caller, (int) $id, $roles, null);
                fwrite(STDERR, sprintf("reset_staff: %s in %s: roles: %s -> %s%s\n", strtolower(trim($email)), $cli->schema,
                    implode(',', $set['before']) ?: '(none)', implode(',', $set['after']), $set['result'] === 'unchanged' ? ' (unchanged)' : ''));
                if (!$newPassword && !$newTotp && $active === null) {
                    return Cli::OK;
                }
            }
            $r = $admin->reset($caller, $email, $box, $newPassword, $newTotp, $active);
        } catch (CwException $e) {
            fwrite(STDERR, "reset_staff: {$e->getMessage()}\n");
            return Cli::PROBLEM;
        }
        fwrite(STDERR, sprintf("reset_staff: %s (id %d, roles %s) in %s: %s; active=%s; %d session(s) ended%s\n",
            $r['email'], $r['id'], implode(',', $r['roles']) ?: '(none)', $cli->schema,
            implode(', ', array_filter([$newPassword ? 'new one-time password' : null, $newTotp ? 'new TOTP secret' : null])) ?: 'no new secret',
            $r['active'] ? 'yes' : 'no', $r['sessions_ended'],
            $r['password'] !== null || $r['otpauth'] !== null ? '; the new secrets follow on stdout and are shown ONLY now' : ''));
        if ($r['password'] !== null) {
            fwrite(STDOUT, "password={$r['password']}\n");
        }
        if ($r['otpauth'] !== null) {
            fwrite(STDOUT, "otpauth={$r['otpauth']}\n");
        }
        return Cli::OK;
    }, false));
