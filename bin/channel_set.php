<?php

declare(strict_types=1);

/**
 * Sets a channel's mode, its API IP allowlist and/or its site writer switch (CW\ChannelAdmin::configure, decisions A14; the
 * switch: IM10, I149 — off by default, I-Day turns it on).
 * A DRY RUN unless --apply: it prints the settings before and after and any warning, and writes
 * nothing. With --apply each setting that changes is written and audited (`channel.mode`,
 * `channel.allowlist`, `channel.site_writer`, with --actor; the switch also writes a channel-wide feed row, so the site
 * re-snapshots). Run ON THE CW SERVER (reads /etc/cw/app.env; connects as the
 * app login, cw_app, unless --admin):
 *
 *   php bin/channel_set.php --code=vapeandgo --mode=shadow                         # dry run
 *   php bin/channel_set.php --code=vapeandgo --mode=shadow --actor="Hari" --apply
 *   php bin/channel_set.php --code=vapeandgo --ips=203.0.113.7,198.51.100.0/24 --apply
 *   php bin/channel_set.php --code=vapeandgo --ips=none --apply                    # empty: every call refused
 *   php bin/channel_set.php --code=vapeandgo --writer=on --actor="Hari" --apply   # CW writes the site's stock, mode, threshold
 *       [--db=<schema>] [--admin]
 *
 * --ips REPLACES the allowlist (addresses or CIDR blocks; a /0 block is refused); `none` empties it.
 * --actor names the person behind the change (default: the login running the tool, SUDO_USER first).
 * The site sees a new mode on its next call (X-CW-Channel-Mode). No key is read or printed.
 * Exit codes: 0 ok (dry run included) · 1 refused (unknown channel, bad mode/address) · 2 usage · 3 cannot run.
 */

use CW\ChannelAdmin;
use CW\CwException;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = 'usage: php bin/channel_set.php --code=<channel> [--mode=off|shadow|live] [--ips=<a,b/24>|none] [--writer=on|off] [--actor=<who>] [--apply]';

exit(Cli::main('channel_set', ['code:', 'mode:', 'ips:', 'writer:', 'actor:', 'apply'], USAGE, static function (Cli $cli, array $opts): int {
    // getopt() silently drops "--ips=" and would take the next option as the value of "--ips": refuse both.
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if (in_array($arg, ['--code=', '--mode=', '--ips=', '--writer=', '--actor='], true)) {
            throw new InvalidArgumentException("{$arg} has no value (use --ips=none to empty the allowlist)");
        }
    }
    foreach (['code', 'mode', 'ips', 'writer', 'actor'] as $k) {
        $v = $opts[$k] ?? null;
        if (is_array($v)) {
            throw new InvalidArgumentException("--{$k} given more than once");
        }
        if (is_string($v) && str_starts_with($v, '--')) {
            throw new InvalidArgumentException("--{$k} needs a value (write --{$k}=<value>)");
        }
    }
    $code = $opts['code'] ?? null;
    if (!is_string($code)) {
        throw new InvalidArgumentException(USAGE);
    }
    $mode = is_string($opts['mode'] ?? null) ? $opts['mode'] : null;
    $ips = null;
    if (is_string($opts['ips'] ?? null)) {
        $ips = strtolower(trim($opts['ips'])) === 'none' ? [] : explode(',', $opts['ips']);
        if ($ips !== [] && array_filter(array_map('trim', $ips), static fn (string $e): bool => $e !== '') === []) {
            throw new InvalidArgumentException('--ips names no address (use --ips=none to empty the allowlist)');
        }
    }
    $writer = null;
    if (is_string($opts['writer'] ?? null)) {
        $writer = match ($opts['writer']) {
            'on' => true,
            'off' => false,
            default => throw new InvalidArgumentException('--writer is on or off'),
        };
    }
    if ($mode === null && $ips === null && $writer === null) {
        throw new InvalidArgumentException('give --mode, --ips, --writer or more; ' . USAGE);
    }
    $actor = $opts['actor'] ?? null;
    if (!is_string($actor)) {
        $actor = getenv('SUDO_USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '') ?: get_current_user();
    }
    $apply = array_key_exists('apply', $opts);

    try {
        $r = (new ChannelAdmin($cli->db))->configure($code, $mode, $ips, (string) $actor, $apply, $writer);
    } catch (CwException $e) {
        $cli->error($e->getMessage());
        return Cli::PROBLEM;
    }
    $json = static fn (array $ips): string => json_encode($ips, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $out = ["channel {$r['code']} (id {$r['id']}) [{$cli->schema}]"];
    $out[] = in_array('mode', $r['changed'], true)
        ? "mode: {$r['before']['mode']} -> {$r['after']['mode']}"
        : "mode: {$r['before']['mode']} (unchanged)";
    $out[] = in_array('allowed_ips', $r['changed'], true)
        ? 'allowed_ips: ' . $json($r['before']['allowed_ips']) . ' -> ' . $json($r['after']['allowed_ips'])
        : 'allowed_ips: ' . $json($r['before']['allowed_ips']) . ' (unchanged)';
    $onOff = static fn (bool $b): string => $b ? 'on' : 'off';
    $out[] = in_array('site_writer', $r['changed'], true)
        ? 'site_writer: ' . $onOff($r['before']['site_writer']) . ' -> ' . $onOff($r['after']['site_writer'])
        : 'site_writer: ' . $onOff($r['before']['site_writer']) . ' (unchanged)';
    foreach ($r['warnings'] as $w) {
        $out[] = "warning: {$w}";
    }
    if ($r['changed'] === []) {
        $out[] = 'nothing to change' . ($apply ? '; nothing written' : '');
    } elseif (!$apply) {
        $out[] = 'dry run: nothing written; run again with --apply';
    } else {
        $actions = array_map(static fn (string $c): string => match ($c) {
            'mode' => 'channel.mode',
            'allowed_ips' => 'channel.allowlist',
            default => 'channel.site_writer',
        }, $r['changed']);
        $out[] = 'applied by ' . trim((string) $actor) . '; audited: ' . implode(', ', $actions);
    }
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return Cli::OK;
}, false));
