<?php

declare(strict_types=1);

/**
 * Lists or changes CW's settings (app_setting; CW\Settings; docs/decisions.md I38-I41, docs/ops.md "Settings"):
 *
 *   php bin/settings.php --list [--db=<schema>] [--admin]
 *   php bin/settings.php --set=<key> (--value=<v> | --value-file=<path>) --reason="<3..500 characters>" [--confirmed] --admin [--db=<schema>]
 *
 * --list prints one line per setting: key, type, value, [provisional] and the owner decision it implements (the app login
 * can read the table). --set changes one value: the app login has SELECT only on app_setting, so a change needs the admin
 * login (--admin); it is parsed for the setting's type (int, decimal, bool true|false, string one line, text up to 4000
 * characters with line breaks — give a multi-line text with --value-file —, date YYYY-MM-DD; an empty value clears a
 * string, text, number or date), checked against the key's rule (Settings::RULES), written with updated_actor
 * system:settings and audited setting.change {key, before, after, reason}. --confirmed also records that the owner
 * confirmed the value (the setting is no longer marked provisional).
 *
 * The company details (company.*) are not settings since 0013: a reviewer adds, changes and confirms them on the Company
 * details screen (/ui/reference/company, docs/decisions.md I91), which keeps every version; --set=company.<anything> is
 * refused (exit 2) with a pointer to it.
 *
 * Exit codes: 0 done (also "unchanged") · 1 refused (--set without --admin) · 2 usage, unknown key or bad value ·
 * 3 cannot run (database, schema).
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = 'usage: php bin/settings.php --list | --set=<key> (--value=<v> | --value-file=<path>) --reason="..." [--confirmed] --admin';

// Refused before connecting: without --admin the tool would connect as the app login, which may only read settings.
$pre = getopt('', ['set:', 'admin', 'help']);
if (is_array($pre) && isset($pre['set']) && !isset($pre['help']) && !array_key_exists('admin', $pre)) {
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z') . " settings ERROR settings change needs --admin (cw_app has SELECT only)\n");
    exit(Cli::PROBLEM);
}

exit(Cli::main('settings', ['list', 'set:', 'value:', 'value-file:', 'reason:', 'confirmed'], $usage,
    static function (Cli $cli, array $opts): int {
        $settings = new Settings($cli->db);
        $list = array_key_exists('list', $opts);
        $key = $opts['set'] ?? null;
        if ($list === ($key !== null)) {
            throw new InvalidArgumentException('give --list or --set=<key>');
        }
        if ($list) {
            foreach ($settings->all() as $s) {
                $value = str_replace("\n", '\n', $s['display']);
                fwrite(STDOUT, sprintf("%s\t%s\t%s%s%s\n", $s['key'], $s['type'], $value, $s['provisional'] ? "\t[provisional]" : '',
                    $s['decision'] !== null ? "\tdecision {$s['decision']}" : ''));
            }
            return Cli::OK;
        }
        $value = $opts['value'] ?? null;
        $file = $opts['value-file'] ?? null;
        $reason = $opts['reason'] ?? null;
        if (!is_string($key) || ($value === null) === ($file === null) || (is_array($value) || is_array($file)) || !is_string($reason)) {
            throw new InvalidArgumentException('give --set=<key> once, exactly one of --value / --value-file, and --reason');
        }
        if ($file !== null) {
            if (!is_string($file) || !is_file($file) || !is_readable($file)) {
                throw new InvalidArgumentException('--value-file must name a readable file');
            }
            $raw = (string) file_get_contents($file, false, null, 0, Settings::TEXT_MAX * 4 + 2);
            $raw = (string) preg_replace('/\r?\n\z/', '', $raw); // the line end every editor adds
        } else {
            $raw = (string) $value;
        }
        try {
            $r = $settings->set(Caller::system('settings'), $key, $raw, $reason, array_key_exists('confirmed', $opts));
        } catch (CwException $e) {
            // An unknown key, a value that is not of the setting's type or breaks its rule, a bad reason: usage errors.
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return Cli::USAGE;
        }
        $cli->log($r['changed']
            ? sprintf('%s changed: %s -> %s', $key, str_replace("\n", '\n', Settings::display($r['before'])), str_replace("\n", '\n', Settings::display($r['after'])))
            : "{$key} unchanged");
        return Cli::OK;
    }, false));
