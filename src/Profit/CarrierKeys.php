<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Config;
use CW\ConfigException;
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
        if (!is_file($path)) {
            throw new CwException('no_key', "CW's key file {$path} is not there: the key {$name} cannot be read", 500);
        }
        $mode = @fileperms($path);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new CwException('no_key', "CW's key file {$path} may be read by others than its owner: make it root's only (chmod 600) before CW uses it", 500);
        }
        try {
            $keys = Config::parseEnvFile($path);
        } catch (ConfigException) {
            throw new CwException('no_key', "CW's key file {$path} could not be read (one name=key a line)", 500);
        }
        $key = trim($keys[$name] ?? '');
        if ($key === '') {
            throw new CwException('no_key', "CW's key file {$path} has no key {$name}", 500);
        }
        return $key;
    }
}
