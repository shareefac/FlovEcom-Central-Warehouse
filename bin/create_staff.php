<?php

declare(strict_types=1);

/**
 * Creates a staff user for the /ui screens and prints, ONCE, a one-time password and the
 * otpauth:// URI of their TOTP secret (scan it into an authenticator app now; it is not shown
 * again). Stored: an argon2id hash of the password (to be changed at first login) and the TOTP
 * secret encrypted with `ui_secret_key` from app.env, which this tool adds (32 random bytes,
 * base64) when it is missing. The key is never printed.
 *
 *   php bin/create_staff.php --email=<address> --roles=<role>[,<role>...] [--name="Display Name"] [--db=<schema>] [--admin]
 *
 * Roles (CW\Auth\Permissions::ROLES): viewer, mapper, mapping_lead, warehouse, manager, admin, buyer,
 * purchasing_manager, goods_in, purchasing_desk, stock_controller, reviewer, accountant, auditor. A person may hold
 * several (`--roles=buyer,reviewer`); admin may be combined only with viewer, accountant and auditor (I12).
 * `--role=<one>` is kept as an alias of `--roles` with one role; give exactly one of the two (I15).
 *
 * STDOUT gets the two secrets and nothing else; messages go to STDERR. Run on the CW server as
 * root (app.env is 0640 root:www-data). Exit codes: 0 ok · 1 refused (exists, bad input) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Ops\Cli;
use CW\Staff\AppEnvFile;
use CW\Staff\SecretBox;
use CW\Staff\StaffAdmin;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('create_staff', ['email:', 'roles:', 'role:', 'name:'],
    'usage: php bin/create_staff.php --email=<address> --roles=<role>[,<role>...] [--name="Display Name"]   (--role=<role> is an alias)',
    static function (Cli $cli, array $opts): int {
        $email = $opts['email'] ?? null;
        $list = $opts['roles'] ?? null;
        $one = $opts['role'] ?? null;
        if (!is_string($email)) {
            throw new InvalidArgumentException('--email=<address> is required');
        }
        if (($list === null) === ($one === null) || ($list !== null && !is_string($list)) || ($one !== null && !is_string($one))) {
            throw new InvalidArgumentException('give the roles once: --roles=<role>[,<role>...] (or --role=<role>), not both and not twice');
        }
        $roles = is_string($list) ? array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $r): bool => $r !== '')) : [(string) $one];
        $config = Config::load();
        $key = $config->get(SecretBox::KEY_NAME);
        if ($key === null) {
            AppEnvFile::set($config->appEnvPath, [SecretBox::KEY_NAME => SecretBox::newKeyBase64()]);
            $key = Config::load()->get(SecretBox::KEY_NAME);
            if ($key === null) {
                throw new RuntimeException('ui_secret_key could not be stored in ' . $config->appEnvPath);
            }
            fwrite(STDERR, "create_staff: added a new ui_secret_key to {$config->appEnvPath} (not shown)\n");
        }
        try {
            $made = (new StaffAdmin($cli->db))->create(Caller::system('create_staff'), $email, $roles, SecretBox::fromBase64($key),
                is_string($opts['name'] ?? null) ? $opts['name'] : null);
        } catch (CwException $e) {
            fwrite(STDERR, "create_staff: {$e->getMessage()}\n");
            return Cli::PROBLEM;
        }
        fwrite(STDERR, "create_staff: staff user {$made['email']} created (id {$made['id']}, roles " . implode(',', $made['roles']) . ") in {$cli->schema}; "
            . "the one-time password and the TOTP URI follow on stdout and are shown ONLY now.\n");
        fwrite(STDOUT, "password={$made['password']}\notpauth={$made['otpauth']}\n");
        return Cli::OK;
    }, false));
