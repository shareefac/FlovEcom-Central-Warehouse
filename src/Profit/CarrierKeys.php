<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * The carriers' API keys CW holds (docs/decisions.md DL49; the owner, 23 Nov 2026, Q4): one root-only file on the CW server,
 * /etc/cw/carrier_keys.env, "name=key" lines (parsed, never sourced), one key per account named by carrier_account.key_name. Never in the
 * database, never in git, never logged: a refusal names the file and the key's NAME, never the key. The file must be readable by its
 * owner only (mode 600): a file group or others may read is refused.
 */
final class CarrierKeys
{
    public const DEFAULT_PATH = '/etc/cw/carrier_keys.env';

    public static function key(string $name, ?string $path = null): string
    {
        $path ??= self::DEFAULT_PATH;
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $name) !== 1) {
            throw new CwException('no_key', 'the account\'s key name is not one CW accepts (lower-case letters, digits and _): fix the account\'s settings', 500);
        }
        clearstatcache(true, $path);
        // A link, a file another user owns (the cron runs as root: root's file only) or one others may read is refused; the file is opened once
        // and its checks made on what was opened (the Codex check r1, LOW 15).
        if (is_link($path) || !is_file($path)) {
            throw new CwException('no_key', "CW's key file {$path} is not there (or is a link): the key {$name} cannot be read", 500);
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new CwException('no_key', "CW's key file {$path} could not be opened: the key {$name} cannot be read", 500);
        }
        try {
            $st = fstat($h);
            // fail closed: no POSIX (the owner cannot be told) is no key (the Codex check r2, r1 LOW 15)
            $me = function_exists('posix_geteuid') ? posix_geteuid() : -1;
            if ($st === false || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 0077) !== 0 || $st['uid'] !== $me) {
                throw new CwException('no_key', "CW's key file {$path} must be a file of the user CW runs as, readable by it only (chmod 600) before CW uses it", 500);
            }
            $text = (string) stream_get_contents($h, 1_048_576);
        } finally {
            fclose($h);
        }
        $keys = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $no => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $m) !== 1) {
                throw new CwException('no_key', "CW's key file {$path} could not be read (line " . ($no + 1) . ' is not one name=key)', 500);
            }
            $v = $m[2];
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $keys[strtolower($m[1])] = $v;
        }
        $key = trim($keys[$name] ?? '');
        if ($key === '') {
            throw new CwException('no_key', "CW's key file {$path} has no key {$name}", 500);
        }
        return $key;
    }
}
