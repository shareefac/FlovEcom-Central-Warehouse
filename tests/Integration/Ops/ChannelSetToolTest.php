<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Api\Kernel;
use CW\ChannelAdmin;
use CW\CwException;
use CW\Schema\Grants;
use CW\Tests\Support\ApiKernelTestCase;
use CW\Tests\Support\TestDb;

/**
 * A14 (F2): bin/channel_set.php sets a channel's mode and/or allowlist through ChannelAdmin: a dry
 * run by default, before/after printed, each real change audited (channel.mode, channel.allowlist).
 * It runs as the app login (cw_app), like the other channel tools.
 */
final class ChannelSetToolTest extends ApiKernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $user = TestDb::config()->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        Grants::apply(self::$db, TestDb::name(), $user);
    }

    public function testDryRunByDefaultThenApplyAndAudit(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'off', ['203.0.113.7']);
        $row = fn (): array => self::$db->one('SELECT mode, allowed_ips FROM channel WHERE id = ?', [$site->channelId]);
        $before = $row();

        // Dry run: the plan is printed, nothing is written or audited.
        [$code, $out, $err] = self::tool('--code=vpg', '--mode=shadow', '--ips=' . self::CLIENT_IP . ',198.51.100.0/24', '--actor=Hari');
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("mode: off -> shadow\n", $out);
        self::assertStringContainsString('allowed_ips: ["203.0.113.7"] -> ["' . self::CLIENT_IP . '","198.51.100.0/24"]' . "\n", $out);
        self::assertStringContainsString('dry run: nothing written; run again with --apply', $out);
        self::assertSame($before, $row());
        self::assertSame(0, $this->audits());

        // Apply: both settings change, one audit row each, by the named actor.
        [$code, $out, $err] = self::tool('--code=vpg', '--mode=shadow', '--ips=' . self::CLIENT_IP . ',198.51.100.0/24', '--actor=Hari', '--apply');
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('applied by Hari; audited: channel.mode, channel.allowlist', $out);
        $now = $row();
        self::assertSame('shadow', $now['mode']);
        self::assertSame([self::CLIENT_IP, '198.51.100.0/24'], json_decode((string) $now['allowed_ips'], true));
        $audit = self::$db->all("SELECT actor, action, entity_type, entity_id, detail FROM audit_log WHERE action LIKE 'channel.%' ORDER BY id");
        self::assertSame([
            ['system:channel_admin', 'channel.mode', 'channel', 'vpg', ['by' => 'Hari', 'from' => 'off', 'to' => 'shadow']],
            ['system:channel_admin', 'channel.allowlist', 'channel', 'vpg',
                ['after' => [self::CLIENT_IP, '198.51.100.0/24'], 'before' => ['203.0.113.7'], 'by' => 'Hari']],
        ], array_map(static fn (array $a): array => [$a['actor'], $a['action'], $a['entity_type'], $a['entity_id'],
            self::detail((string) $a['detail'])], $audit));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE detail LIKE ?', ['%' . $key . '%']));

        // The site sees the new mode on its next call, from the allowed address.
        self::assertSame('shadow', self::header($this->call('GET', '/v1/changes', $key), Kernel::MODE_HEADER));

        // The same settings again (the allowlist in another order): nothing to change, nothing audited.
        [$code, $out] = self::tool('--code=vpg', '--mode=shadow', '--ips=198.51.100.0/24,' . self::CLIENT_IP, '--apply');
        self::assertSame(0, $code);
        self::assertStringContainsString("mode: shadow (unchanged)\n", $out);
        self::assertStringContainsString('(unchanged)', explode("\n", $out)[2]);
        self::assertStringContainsString('nothing to change; nothing written', $out);
        self::assertSame(2, $this->audits());

        // Mode only; the actor defaults to the login running the tool (SUDO_USER first).
        [$code, $out, $err] = self::runTool(['--code=vpg', '--mode=live', '--apply'], ['SUDO_USER' => 'ops-person']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('warning: no final opening_orders batch', $out);
        self::assertSame('live', $row()['mode']);
        self::assertSame(['by' => 'ops-person', 'from' => 'shadow', 'to' => 'live'],
            self::detail((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'channel.mode' ORDER BY id DESC LIMIT 1")));
        self::assertSame('live', self::header($this->call('GET', '/v1/health', $key), Kernel::MODE_HEADER));

        // --ips=none empties the allowlist: every call is refused, and the tool says so.
        [$code, $out] = self::tool('--code=vpg', '--ips=none', '--apply');
        self::assertSame(0, $code);
        self::assertStringContainsString('allowed_ips: ["' . self::CLIENT_IP . '","198.51.100.0/24"] -> []', $out);
        self::assertStringContainsString('warning: the allowlist is empty', $out);
        self::assertSame([], json_decode((string) $row()['allowed_ips'], true));
        $r = $this->call('GET', '/v1/health', $key);
        self::envelope($r, 403, 'forbidden');
        self::assertNull(self::header($r, Kernel::MODE_HEADER));
        self::assertSame(4, $this->audits());
    }

    public function testWarningsAndRefusals(): void
    {
        $this->apiSite('vpg', 'off', ['203.0.113.7']);
        [$code, $out] = self::tool('--code=vpg', '--mode=live');
        self::assertSame(0, $code);
        self::assertStringContainsString('warning: off -> live skips shadow', $out);
        self::assertStringContainsString('dry run', $out);

        self::$db->exec("UPDATE channel SET mode = 'live' WHERE code = 'vpg'");
        [$code, $out] = self::tool('--code=vpg', '--mode=off');
        self::assertSame(0, $code);
        self::assertStringContainsString('warning: lowering the mode', $out);

        // Refused by ChannelAdmin: exit 1, nothing written.
        foreach ([
            ['--code=nosuch', '--mode=shadow', '--apply'],
            ['--code=vpg', '--mode=on', '--apply'],
            ['--code=vpg', '--ips=not-an-ip', '--apply'],
            ['--code=vpg', '--ips=10.0.0.0/0', '--apply'],
            ['--code=vpg', '--ips=10.0.0.1/33', '--apply'],
            ['--code=vpg', '--mode=shadow', '--actor=' . str_repeat('x', 65), '--apply'],
        ] as $args) {
            [$code, $out, $err] = self::tool(...$args);
            self::assertSame(1, $code, implode(' ', $args) . ": {$out} {$err}");
            self::assertStringContainsString('ERROR', $err);
        }
        // Usage errors: exit 2, nothing written.
        foreach ([
            ['--mode=shadow'],
            ['--code=vpg'],
            ['--code=vpg', '--ips=', '--apply'],
            ['--code=vpg', '--ips', '--apply'],
            ['--code=vpg', '--mode', '--apply'],
            ['--code=vpg', '--ips=,', '--apply'],
            ['--code=vpg', '--mode=shadow', '--mode=live', '--apply'],
        ] as $args) {
            [$code, $out, $err] = self::tool(...$args);
            self::assertSame(2, $code, implode(' ', $args) . ": {$out} {$err}");
        }
        self::assertSame(['live', ['203.0.113.7']], [self::$db->value("SELECT mode FROM channel WHERE code = 'vpg'"),
            json_decode((string) self::$db->value("SELECT allowed_ips FROM channel WHERE code = 'vpg'"), true)]);
        self::assertSame(0, $this->audits());
    }

    public function testChannelAdminConfigureInProcess(): void
    {
        $this->apiSite('vpg', 'shadow', ['203.0.113.7']);
        $admin = new ChannelAdmin(self::$db);
        $dry = $admin->configure('vpg', 'live', null, 'tester', false);
        self::assertSame(['mode'], $dry['changed']);
        self::assertFalse($dry['applied']);
        self::assertSame(['mode' => 'shadow', 'allowed_ips' => ['203.0.113.7'], 'site_writer' => false], $dry['before']);
        self::assertSame(['mode' => 'live', 'allowed_ips' => ['203.0.113.7'], 'site_writer' => false], $dry['after']);
        self::assertSame('shadow', self::$db->value("SELECT mode FROM channel WHERE code = 'vpg'"));

        $done = $admin->configure('vpg', 'live', [' 203.0.113.7 ', '203.0.113.7'], 'tester', true);
        self::assertSame([['mode'], true], [$done['changed'], $done['applied']], 'a duplicate entry is not a change');
        self::assertSame(1, $this->audits());

        foreach ([[null, null, 'tester', 'nothing_to_set'], ['on', null, 'tester', 'bad_mode'], [null, ['x'], 'tester', 'bad_ip'],
            ['off', null, '  ', 'bad_actor']] as [$mode, $ips, $actor, $error]) {
            try {
                $admin->configure('vpg', $mode, $ips, $actor, true);
                self::fail("{$error} expected");
            } catch (CwException $e) {
                self::assertSame($error, $e->errorCode);
            }
        }
        self::assertSame(1, $this->audits());
    }

    /**
     * IM10 (I149): --writer=on|off turns CW's site stock writer on or off for one site: a dry run first, then written, audited
     * channel.site_writer, and a channel-wide feed row (the site re-snapshots its listings' `site` blocks).
     */
    public function testTheSiteWriterSwitch(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow', [self::CLIENT_IP]);
        $this->listing($site, 'W1', $this->item('strict', 3));
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        [$code, $out, $err] = self::tool('--code=vpg', '--writer=on', '--actor=Hari');
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("site_writer: off -> on\n", $out);
        self::assertStringContainsString('warning: the channel is shadow: nothing is written on the site until it is live', $out);
        self::assertStringContainsString('dry run', $out);
        self::assertSame(0, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vpg'"));

        [$code, $out, $err] = self::tool('--code=vpg', '--writer=on', '--actor=Hari', '--apply');
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('audited: channel.site_writer', $out);
        self::assertSame(1, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vpg'"));
        self::assertSame(['by' => 'Hari', 'from' => false, 'to' => true], self::detail((string) self::$db->value(
            "SELECT detail FROM audit_log WHERE action = 'channel.site_writer'")));
        $c = self::data($this->call('GET', '/v1/changes?after=' . $after, $key));
        self::assertTrue($c['resync']);
        $view = self::data($this->call('GET', '/v1/availability?variant_ids=W1', $key))['listings'][0];
        self::assertSame([true, 3, 'From-Warehouse', 0, 'strict'], [$view['site']['writer'], $view['site']['qty'], $view['site']['mode'], $view['site']['backorders'],
            $view['site']['why']]);

        [$code, $out] = self::tool('--code=vpg', '--writer=on', '--apply');
        self::assertSame(0, $code);
        self::assertStringContainsString('site_writer: on (unchanged)', $out);
        [$code, $out] = self::tool('--code=vpg', '--writer=off', '--apply');
        self::assertSame(0, $code);
        self::assertStringContainsString('warning: site writer off', $out);
        self::assertSame(0, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vpg'"));
        [$code] = self::tool('--code=vpg', '--writer=maybe', '--apply');
        self::assertSame(2, $code, 'a usage error');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'channel.site_writer'"));
    }

    /** An audit detail with its keys sorted (MySQL's JSON type re-orders them). @return array<string, mixed> */
    private static function detail(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ksort($d);
        return $d;
    }

    private function audits(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action IN ('channel.mode', 'channel.allowlist')");
    }

    /** @return array{0: int, 1: string, 2: string} exit status, stdout, stderr */
    private static function tool(string ...$args): array
    {
        return self::runTool($args, []);
    }

    /**
     * Runs bin/channel_set.php as the app login against this slot's schema.
     *
     * @param list<string> $args
     * @param array<string, string> $env added to this process's environment (SUDO_USER is never inherited)
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private static function runTool(array $args, array $env): array
    {
        $root = dirname(__DIR__, 3);
        $environment = $env + array_filter(getenv(), static fn (string $k): bool => $k !== 'SUDO_USER', ARRAY_FILTER_USE_KEY);
        $p = proc_open([PHP_BINARY, "{$root}/bin/channel_set.php", '--db=' . TestDb::name(), ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
}
