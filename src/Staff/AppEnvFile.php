<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\ConfigException;

/**
 * Adds or replaces keys in /etc/cw/app.env (key=value lines, parsed by CW\Config, never sourced),
 * keeping every other line. Written atomically (temp file + rename). A new file is 0600; an existing
 * one keeps its group and mode (capped at 0640), so php-fpm's group read set up by
 * deploy/staging/install_api.sh survives (same rules as bin/setup_staging.php).
 */
final class AppEnvFile
{
    /** @param array<string, string> $set */
    public static function set(string $path, #[\SensitiveParameter] array $set): void
    {
        foreach ($set as $k => $v) {
            if (preg_match('/^[a-z_][a-z0-9_.-]*$/', $k) !== 1 || preg_match('/[\r\n]/', $v) === 1) {
                throw new ConfigException('invalid app.env key or value');
            }
        }
        $prev = is_file($path) ? stat($path) : false;
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : ['# Central Warehouse app settings (key=value, parsed by CW\\Config, never sourced).'];
        $pending = $set;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.-]*)\s*=/', $line, $m) === 1) {
                $key = strtolower($m[1]);
                if (array_key_exists($key, $pending)) {
                    $lines[$i] = $key . '=' . $pending[$key];
                    unset($pending[$key]);
                }
            }
        }
        foreach ($pending as $k => $v) {
            $lines[] = $k . '=' . $v;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new ConfigException("{$dir} does not exist");
        }
        $old = umask(0077);
        try {
            $tmp = tempnam($dir, '.app.env.');
            if ($tmp === false) {
                throw new ConfigException("cannot write in {$dir}");
            }
            chmod($tmp, 0600);
            if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
                @unlink($tmp);
                throw new ConfigException("cannot write {$path}");
            }
            if ($prev !== false) {
                @chgrp($tmp, $prev['gid']);
                chmod($tmp, $prev['mode'] & 0640);
            }
            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new ConfigException("cannot write {$path}");
            }
        } finally {
            umask($old);
        }
    }
}
